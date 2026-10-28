<?php
declare(strict_types=1);

/**
 * Duplicate detection — ported from src/services/duplicates.js, including the
 * fix applied during hardening: matching is NAME_COMPANY (same person, same
 * company), not "company + email domain", which would wrongly flag every
 * colleague at the same firm as a duplicate of each other.
 */

const GV_DUP_STRATEGY_WEIGHT = ['PHONE' => 100, 'EMAIL' => 90, 'NAME_PHONE' => 80, 'NAME_COMPANY' => 45];

function find_duplicates(string $orgId, array $input, ?string $excludeId = null, int $limit = 5): array
{
    $emailNorm = normalize_email($input['email'] ?? null);
    $phoneNorm = normalize_phone($input['phone'] ?? null);
    $altPhoneNorm = normalize_phone($input['altPhone'] ?? null);

    $or = [];
    $params = [];
    if ($phoneNorm) {
        $or[] = 'phone_norm = ?';
        $params[] = $phoneNorm;
    }
    if ($altPhoneNorm && $altPhoneNorm !== $phoneNorm) {
        $or[] = 'phone_norm = ?';
        $params[] = $altPhoneNorm;
    }
    if ($emailNorm) {
        $or[] = 'email_norm = ?';
        $params[] = $emailNorm;
    }
    if (!empty($input['company']) && !empty($input['firstName'])) {
        if (!empty($input['lastName'])) {
            $or[] = '(company = ? AND first_name = ? AND last_name = ?)';
            $params[] = $input['company'];
            $params[] = $input['firstName'];
            $params[] = $input['lastName'];
        } else {
            $or[] = '(company = ? AND first_name = ?)';
            $params[] = $input['company'];
            $params[] = $input['firstName'];
        }
    }
    if (!$or) {
        return [];
    }

    $sql = 'SELECT id, code, first_name, last_name, email, phone, company, email_norm, phone_norm, created_at, owner_id
            FROM leads WHERE organization_id = ? AND is_duplicate = 0';
    $bind = [$orgId];
    if ($excludeId) {
        $sql .= ' AND id != ?';
        $bind[] = $excludeId;
    }
    $sql .= ' AND (' . implode(' OR ', $or) . ') ORDER BY created_at DESC LIMIT ' . ($limit * 3);
    $rows = db_all($sql, array_merge($bind, $params));

    // Hydrate owner + status labels the frontend expects.
    $ownerIds = array_values(array_unique(array_filter(array_column($rows, 'owner_id'))));
    $owners = [];
    if ($ownerIds) {
        [$in, $p] = db_in_clause($ownerIds);
        foreach (db_all("SELECT id, first_name, last_name FROM users WHERE id IN $in", $p) as $u) {
            $owners[$u['id']] = $u;
        }
    }

    $scored = [];
    foreach ($rows as $row) {
        $reasons = [];
        if ($phoneNorm && $row['phone_norm'] === $phoneNorm) {
            $reasons[] = 'PHONE';
        }
        if ($emailNorm && $row['email_norm'] === $emailNorm) {
            $reasons[] = 'EMAIL';
        }
        if (in_array('PHONE', $reasons, true) && !empty($input['firstName'])
            && strtolower($row['first_name']) === strtolower($input['firstName'])) {
            $reasons[] = 'NAME_PHONE';
        }
        if (!$reasons) {
            $reasons[] = 'NAME_COMPANY';
        }
        $confidence = max(array_map(fn($r) => GV_DUP_STRATEGY_WEIGHT[$r] ?? 0, $reasons));
        $scored[] = [
            'id' => $row['id'], 'code' => $row['code'],
            'firstName' => $row['first_name'], 'lastName' => $row['last_name'],
            'email' => $row['email'], 'phone' => $row['phone'], 'company' => $row['company'],
            'createdAt' => $row['created_at'],
            'owner' => isset($owners[$row['owner_id']]) ? [
                'id' => $row['owner_id'],
                'firstName' => $owners[$row['owner_id']]['first_name'],
                'lastName' => $owners[$row['owner_id']]['last_name'],
            ] : null,
            'matchedOn' => $reasons,
            'confidence' => $confidence,
        ];
    }
    usort($scored, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    return array_slice($scored, 0, $limit);
}

/**
 * Merges $sourceIds into $targetId: fills the target's blank fields from the
 * newest source, re-parents every child record, and tombstones the sources
 * (isDuplicate = true) rather than deleting them, so old links still resolve.
 */
function merge_leads(string $orgId, string $targetId, array $sourceIds, ?string $actorId): array
{
    $ids = array_values(array_unique(array_diff($sourceIds, [$targetId])));
    if (!$ids) {
        throw bad_request('Nothing to merge');
    }

    return db_transaction(function () use ($orgId, $targetId, $ids, $actorId) {
        $target = db_one('SELECT * FROM leads WHERE id = ? AND organization_id = ?', [$targetId, $orgId]);
        if (!$target) {
            throw not_found('Target lead not found');
        }
        [$in, $p] = db_in_clause($ids);
        $sources = db_all("SELECT * FROM leads WHERE id IN $in AND organization_id = ? ORDER BY created_at DESC", array_merge($p, [$orgId]));
        if (!$sources) {
            throw not_found('No source leads found');
        }

        $fillable = [
            'last_name', 'email', 'phone', 'alt_phone', 'company', 'job_title', 'website',
            'country', 'state', 'city', 'address', 'pincode',
            'source_id', 'project_id', 'medium', 'landing_page', 'referrer_url',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'expected_value', 'notes',
        ];
        $patch = [];
        foreach ($fillable as $field) {
            if ($target[$field] === null || $target[$field] === '') {
                foreach ($sources as $s) {
                    if ($s[$field] !== null && $s[$field] !== '') {
                        $patch[$field] = $s[$field];
                        break;
                    }
                }
            }
        }
        if (isset($patch['email'])) {
            $patch['email_norm'] = normalize_email($patch['email']);
        }
        if (isset($patch['phone'])) {
            $patch['phone_norm'] = normalize_phone($patch['phone']);
        }

        // Earliest first-touch across the merged set is the true first touch.
        $all = array_merge($sources, [$target]);
        $withTouch = array_filter($all, fn($l) => $l['first_touch_at']);
        if ($withTouch) {
            usort($withTouch, fn($a, $b) => strtotime($a['first_touch_at']) <=> strtotime($b['first_touch_at']));
            $earliest = $withTouch[0];
            if ($earliest['id'] !== $target['id']) {
                $patch['first_touch_source'] = $earliest['first_touch_source'];
                $patch['first_touch_campaign'] = $earliest['first_touch_campaign'];
                $patch['first_touch_at'] = $earliest['first_touch_at'];
            }
        }
        $patch['score'] = max(array_merge([(int) $target['score']], array_map(fn($s) => (int) $s['score'], $sources)));

        foreach (['activities', 'documents'] as $table) {
            db_exec("UPDATE $table SET lead_id = ? WHERE lead_id IN $in", array_merge([$targetId], $p));
        }

        // Tags: a join table with a unique constraint — move only tags the target lacks.
        $existingTags = db_all('SELECT tag_id FROM lead_tags WHERE lead_id = ?', [$targetId]);
        $have = array_flip(array_column($existingTags, 'tag_id'));
        $incoming = db_all("SELECT * FROM lead_tags WHERE lead_id IN $in", $p);
        foreach ($incoming as $link) {
            if (isset($have[$link['tag_id']])) {
                db_exec('DELETE FROM lead_tags WHERE id = ?', [$link['id']]);
            } else {
                db_exec('UPDATE lead_tags SET lead_id = ? WHERE id = ?', [$targetId, $link['id']]);
                $have[$link['tag_id']] = true;
            }
        }

        db_exec("UPDATE leads SET is_duplicate = 1, merged_into_id = ? WHERE id IN $in", array_merge([$targetId], $p));

        if ($patch) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($patch)));
            db_exec("UPDATE leads SET $sets WHERE id = ?", array_merge(array_values($patch), [$targetId]));
        }
        $merged = db_one('SELECT * FROM leads WHERE id = ?', [$targetId]);

        db_exec(
            'INSERT INTO activities (id, organization_id, type, subject, body, lead_id, user_id, meta, occurred_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())',
            [
                gv_id(), $orgId, 'SYSTEM', 'Merged ' . count($sources) . ' duplicate lead(s)',
                implode(', ', array_map(fn($s) => $s['code'] . ' - ' . $s['first_name'] . ' ' . ($s['last_name'] ?? ''), $sources)),
                $targetId, $actorId, to_json(['mergedIds' => $ids]),
            ]
        );

        return ['merged' => $merged, 'mergedCount' => count($sources)];
    });
}
