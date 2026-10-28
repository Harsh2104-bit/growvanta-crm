<?php
declare(strict_types=1);

/**
 * Meta (Facebook / Instagram) Lead Ads ingestion.
 *
 * Meta's webhook tells you only that a lead exists, never what is in it — the
 * payload carries a leadgen_id, a page_id and a form_id. The actual field
 * values must be fetched from the Graph API with the page's access token, which
 * is why meta_lead_forms stores one (encrypted) per connected page/form.
 *
 * Flow:
 *   1. webhooks/meta_leads.php verifies X-Hub-Signature-256 and hands the
 *      change entries here.
 *   2. Every entry is logged to meta_webhook_events before anything else, so a
 *      failure is debuggable and replayable rather than silently lost.
 *   3. The page/form mapping decides organisation, source, project and owner.
 *   4. Field values are fetched, normalised, and pushed through create_lead()
 *      — the same single write path manual entry and web forms use, so scoring,
 *      assignment and automation all behave identically.
 */

/** Field names Meta's standard questions use, mapped onto lead columns. */
const META_FIELD_ALIASES = [
    'full_name' => 'firstName', 'first_name' => 'firstName', 'last_name' => 'lastName',
    'email' => 'email', 'phone_number' => 'phone', 'phone' => 'phone',
    'company_name' => 'company', 'job_title' => 'jobTitle', 'city' => 'city',
    'state' => 'state', 'province' => 'state', 'country' => 'country',
    'street_address' => 'address', 'post_code' => 'pincode', 'zip_code' => 'pincode',
];

function meta_graph_base(): string
{
    return 'https://graph.facebook.com/' . gv_config('meta.graph_version', 'v19.0');
}

/** Verifies Meta's X-Hub-Signature-256 against the raw body using the app secret. */
function meta_verify_signature(string $rawBody, ?string $signatureHeader): bool
{
    $appSecret = (string) gv_config('meta.app_secret');
    if ($appSecret === '' || !$signatureHeader) {
        return false;
    }
    if (!str_starts_with($signatureHeader, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $appSecret);
    return hash_equals($expected, substr($signatureHeader, 7));
}

/** One GET to the Graph API; returns the decoded body or null on failure. */
function meta_graph_get(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[meta] Graph request failed: $error");
        return null;
    }
    $decoded = json_decode((string) $response, true);
    if ($status >= 400) {
        error_log("[meta] Graph returned HTTP $status: " . substr((string) $response, 0, 300));
        return is_array($decoded) ? $decoded : null;
    }
    return is_array($decoded) ? $decoded : null;
}

/** Fetches one lead's full field data from the Graph API. */
function meta_fetch_lead(string $leadgenId, string $pageAccessToken): ?array
{
    $url = meta_graph_base() . '/' . rawurlencode($leadgenId)
        . '?fields=' . rawurlencode('id,created_time,ad_id,adset_id,campaign_id,form_id,field_data')
        . '&access_token=' . rawurlencode($pageAccessToken);
    $data = meta_graph_get($url);
    if (!$data || isset($data['error'])) {
        return null;
    }
    return $data;
}

/**
 * Turns Meta's `field_data` ([{name, values:[...]}, ...]) into lead input.
 * Unrecognised questions are preserved as custom-field values rather than
 * dropped, so nothing the advertiser asked for is lost.
 */
function meta_map_field_data(array $fieldData): array
{
    $input = ['customFields' => []];
    foreach ($fieldData as $field) {
        $name = strtolower(trim((string) ($field['name'] ?? '')));
        $value = trim((string) (($field['values'][0] ?? '')));
        if ($name === '' || $value === '') {
            continue;
        }
        $target = META_FIELD_ALIASES[$name] ?? null;
        if ($target === 'firstName' && $name === 'full_name' && str_contains($value, ' ')) {
            // Meta returns answers in form order, so full_name can arrive after
            // first_name. The more specific answer wins either way.
            if (empty($input['firstName'])) {
                $parts = preg_split('/\s+/', $value);
                $input['firstName'] = array_shift($parts);
                if (empty($input['lastName'])) {
                    $input['lastName'] = implode(' ', $parts);
                }
            }
            continue;
        }
        if ($target) {
            if (empty($input[$target])) {
                $input[$target] = $value;
            }
        } else {
            $input['customFields'][$name] = $value;
        }
    }
    return $input;
}

/**
 * Processes a single `leadgen` change entry.
 *
 * @return array{status: string, leadId?: string, message?: string}
 */
function meta_process_leadgen(array $change, array $rawEntry): array
{
    $value = $change['value'] ?? [];
    $leadgenId = (string) ($value['leadgen_id'] ?? '');
    $pageId = (string) ($value['page_id'] ?? '');
    $formId = (string) ($value['form_id'] ?? '');

    $eventId = gv_id();
    $mapping = ($pageId && $formId)
        ? db_one('SELECT * FROM meta_lead_forms WHERE meta_page_id = ? AND meta_form_id = ?', [$pageId, $formId])
        : null;

    // Fall back to the page.
    //
    // Connecting a page that has no form yet stores a placeholder row keyed
    // "page-<id>", and the promise made at that moment is that the first form
    // published afterwards starts delivering by itself. It did not: the lookup
    // above needs an exact form id, so a real lead against a form created later
    // matched nothing and was dropped as UNMAPPED - silently, because a dropped
    // webhook looks like no webhook. The page is what Facebook subscribed and
    // the page token is what reads the lead, so the page is the right thing to
    // fall back to.
    $viaPage = false;
    if (!$mapping && $pageId) {
        $mapping = db_one(
            'SELECT * FROM meta_lead_forms WHERE meta_page_id = ? AND meta_form_id = ? LIMIT 1',
            [$pageId, 'page-' . $pageId]
        );
        if (!$mapping) {
            // Any active row for this page will do; they all carry the same
            // page access token, which is what actually fetches the lead.
            $mapping = db_one(
                'SELECT * FROM meta_lead_forms WHERE meta_page_id = ? AND is_active = 1 ORDER BY created_at LIMIT 1',
                [$pageId]
            );
        }
        $viaPage = (bool) $mapping;
        if ($viaPage) {
            // Worth a line in the log: it means a form exists on Facebook that
            // the CRM has never been told about by name.
            error_log("[meta] lead for unknown form $formId matched via page $pageId");
        }
    }

    db_exec(
        'INSERT INTO meta_webhook_events (id, organization_id, meta_page_id, meta_form_id, meta_lead_id, raw_payload, status, created_at)
         VALUES (?,?,?,?,?,?,?,NOW())',
        [$eventId, $mapping['organization_id'] ?? null, $pageId ?: null, $formId ?: null, $leadgenId ?: null, to_json($rawEntry), 'RECEIVED']
    );

    $fail = function (string $status, string $message) use ($eventId): array {
        db_exec('UPDATE meta_webhook_events SET status = ?, error = ?, processed_at = NOW() WHERE id = ?', [$status, substr($message, 0, 500), $eventId]);
        error_log("[meta] $status: $message");
        return ['status' => $status, 'message' => $message];
    };

    if (!$leadgenId) {
        return $fail('INVALID', 'Change payload had no leadgen_id');
    }
    if (!$mapping) {
        return $fail('UNMAPPED', "Facebook delivered a lead for page $pageId (form $formId), but no page in this CRM is connected to it. Press Connect Facebook on the Integrations screen and tick that page.");
    }
    if (!gv_bool($mapping['is_active'])) {
        return $fail('INACTIVE', 'That Meta form is connected but paused.');
    }

    // Meta retries a delivery until it gets a 200; the platform lead id is the
    // natural idempotency key.
    $existing = db_one('SELECT id FROM leads WHERE meta_lead_id = ?', [$leadgenId]);
    if ($existing) {
        db_exec('UPDATE meta_webhook_events SET status = ?, lead_id = ?, processed_at = NOW() WHERE id = ?', ['DUPLICATE', $existing['id'], $eventId]);
        return ['status' => 'DUPLICATE', 'leadId' => $existing['id']];
    }

    $token = gv_decrypt($mapping['page_access_token']);
    if (!$token) {
        return $fail('NO_TOKEN', 'No page access token stored for that form. Reconnect the page under Integrations > Meta Ads.');
    }

    $fetched = meta_fetch_lead($leadgenId, $token);
    if (!$fetched || !empty($fetched['error'])) {
        $message = $fetched['error']['message'] ?? 'Graph API did not return the lead.';
        db_exec('UPDATE meta_lead_forms SET last_error = ? WHERE id = ?', [substr($message, 0, 500), $mapping['id']]);
        return $fail('FETCH_FAILED', $message);
    }

    $input = meta_map_field_data($fetched['field_data'] ?? []);
    if (empty($input['firstName'])) {
        // Meta forms do not always ask for a name; fall back to something usable
        // rather than rejecting a real, paid-for lead.
        $input['firstName'] = $input['email'] ?? $input['phone'] ?? 'Meta lead';
    }
    if (empty($input['email']) && empty($input['phone'])) {
        return $fail('INCOMPLETE', 'That Meta form collected neither an email address nor a phone number.');
    }

    // Ad-level attribution, so marketing reports can tie revenue to the exact ad.
    $input['metaLeadId'] = $leadgenId;
    $input['metaFormId'] = $formId ?: ($fetched['form_id'] ?? null);
    $input['metaPageId'] = $pageId ?: null;
    $input['metaAdId'] = $fetched['ad_id'] ?? null;
    $input['metaAdsetId'] = $fetched['adset_id'] ?? null;
    $input['metaCampaignId'] = $fetched['campaign_id'] ?? null;
    $input['utmSource'] = $input['utmSource'] ?? 'meta';
    $input['utmMedium'] = $input['utmMedium'] ?? 'paid_social';
    $input['medium'] = $input['medium'] ?? 'paid_social';

    // The connected form decides routing; fall back to the meta_ads source so a
    // lead is never orphaned just because the mapping omitted one.
    $sourceId = $mapping['source_id'];
    if (!$sourceId) {
        $sourceId = db_scalar("SELECT id FROM lead_sources WHERE organization_id = ? AND `key` = 'meta_ads'", [$mapping['organization_id']]);
    }
    if ($sourceId) {
        $input['sourceId'] = $sourceId;
    }

    // A Meta lead form cannot ask the buyer which tower they mean, so the
    // project is chosen once when the form is connected rather than guessed per
    // lead. Optional by design: an unattributed lead is still a lead, and it
    // surfaces under the "No project" filter for someone to put right.
    if (!empty($mapping['project_id'])) {
        $input['projectId'] = $mapping['project_id'];
    }

    // Campaign attribution rides on the lead itself (meta_campaign_id above,
    // plus utm_campaign) rather than a CRM campaign object, which no longer
    // exists. Nothing is lost: the marketing report groups on exactly these.

    try {
        $result = create_lead($input, [
            'organizationId' => $mapping['organization_id'],
            'origin' => 'META',
            'duplicatePolicy' => 'FLAG',
            'ownerId' => $mapping['assign_to_user_id'] ?: null,
            'teamId' => $mapping['assign_to_team_id'] ?: null,
            'skipAssignment' => (bool) $mapping['assign_to_user_id'],
        ]);
    } catch (Throwable $e) {
        return $fail('FAILED', $e->getMessage());
    }

    db_exec('UPDATE meta_webhook_events SET status = ?, lead_id = ?, processed_at = NOW() WHERE id = ?', ['PROCESSED', $result['lead']['id'], $eventId]);
    db_exec('UPDATE meta_lead_forms SET last_synced_at = NOW(), last_error = NULL WHERE id = ?', [$mapping['id']]);

    return ['status' => 'PROCESSED', 'leadId' => $result['lead']['id']];
}

/**
 * Walks a full webhook body ({object: 'page', entry: [...]}) and processes
 * every leadgen change it carries.
 */
function meta_process_webhook(array $payload): array
{
    $results = [];
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? '') !== 'leadgen') {
                continue;
            }
            try {
                $results[] = meta_process_leadgen($change, $entry);
            } catch (Throwable $e) {
                error_log('[meta] unhandled processing error: ' . $e->getMessage());
                $results[] = ['status' => 'FAILED', 'message' => $e->getMessage()];
            }
        }
    }
    return $results;
}
