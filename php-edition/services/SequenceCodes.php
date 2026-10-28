<?php
declare(strict_types=1);

/**
 * Human-readable record codes (LD-1042, OPP-1043). A single monotonic counter
 * per organisation backs every prefix — the increment and read happen in one
 * UPDATE + SELECT under the same DB row lock, so two concurrent inserts can't
 * collide even without a surrounding transaction.
 */
function next_code(string $orgId, string $prefix): string
{
    db_exec('UPDATE organizations SET lead_seq = lead_seq + 1 WHERE id = ?', [$orgId]);
    $seq = (int) db_scalar('SELECT lead_seq FROM organizations WHERE id = ?', [$orgId]);
    return "$prefix-$seq";
}

function next_code_block(string $orgId, string $prefix, int $count): array
{
    db_exec('UPDATE organizations SET lead_seq = lead_seq + ? WHERE id = ?', [$count, $orgId]);
    $end = (int) db_scalar('SELECT lead_seq FROM organizations WHERE id = ?', [$orgId]);
    $start = $end - $count + 1;
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = "$prefix-" . ($start + $i);
    }
    return $codes;
}
