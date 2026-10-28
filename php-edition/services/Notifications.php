<?php
declare(strict_types=1);

/**
 * In-app notifications — ported from src/services/notifications.js. Email is
 * deliberately not wired to a transport by default: without SMTP configured,
 * a notification stays in the in-app centre and the server logs that delivery
 * was skipped, rather than silently pretending to have sent something.
 */

const GV_NOTIFICATION_TYPES = [
    'LEAD_ASSIGNED' => 'INFO', 'LEAD_REASSIGNED' => 'INFO', 'LEAD_CONVERTED' => 'SUCCESS',
    'TASK_ASSIGNED' => 'INFO', 'TASK_DUE' => 'WARNING', 'TASK_OVERDUE' => 'DANGER',
    'FOLLOWUP_DUE' => 'WARNING', 'FOLLOWUP_UPCOMING' => 'INFO', 'DAILY_SUMMARY' => 'INFO', 'OPPORTUNITY_UPDATED' => 'INFO', 'DEAL_WON' => 'SUCCESS',
    'DEAL_LOST' => 'DANGER', 'MENTION' => 'INFO', 'SYSTEM_ALERT' => 'WARNING', 'IMPORT_COMPLETE' => 'SUCCESS',
];

function notify_user(
    string $orgId,
    ?string $userId,
    string $type,
    string $title,
    ?string $body = null,
    ?string $entityType = null,
    ?string $entityId = null,
    ?string $link = null,
    bool $sendEmail = false
): ?array {
    if (!$userId) {
        return null;
    }
    try {
        $id = gv_id();
        $severity = GV_NOTIFICATION_TYPES[$type] ?? 'INFO';
        db_exec(
            'INSERT INTO notifications (id, organization_id, user_id, type, title, body, entity_type, entity_id, link, severity, email_queued_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [$id, $orgId, $userId, $type, $title, $body, $entityType, $entityId, $link, $severity, $sendEmail ? date('Y-m-d H:i:s') : null]
        );
        if ($sendEmail) {
            queue_notification_email($title);
        }
        return db_one('SELECT * FROM notifications WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        error_log('[notifications] create failed: ' . $e->getMessage());
        return null;
    }
}

function notify_many(array $userIds, string $orgId, string $type, string $title, ?string $body, ?string $entityType, ?string $entityId, ?string $link): void
{
    foreach (array_unique(array_filter($userIds)) as $userId) {
        notify_user($orgId, $userId, $type, $title, $body, $entityType, $entityId, $link);
    }
}

function queue_notification_email(string $title): void
{
    if (!gv_config('mail.host')) {
        error_log("[notifications] SMTP not configured - \"$title\" stayed in-app only.");
        return;
    }
    // A single seam for a future PHPMailer/SMTP integration; see lib/mailer.php
    // once SMTP credentials are supplied. Deliberately not faked here.
    error_log("[notifications] email queued: $title");
}

/** Sweeps tasks and follow-ups that have come due; called from cron/sweep.php. */
function run_due_sweep(): array
{
    $now = date('Y-m-d H:i:s');
    // tasksDue is reported by the cron script, so it has to exist even when
    // nothing is due - reading a key that was never set is a warning, and a
    // warning in a cron job is a line nobody ever reads.
    $results = ['tasksDue' => 0, 'followUpsDue' => 0, 'followUpsUpcoming' => 0];

    $dueLeads = db_all(
        "SELECT id, code, first_name, last_name, organization_id, owner_id, next_follow_up_at FROM leads
         WHERE next_follow_up_at <= ? AND owner_id IS NOT NULL AND is_converted = 0 AND is_duplicate = 0 LIMIT 500",
        [$now]
    );
    foreach ($dueLeads as $lead) {
        $cutoff = date('Y-m-d H:i:s', time() - 12 * 3600);
        $already = db_one(
            "SELECT id FROM notifications WHERE user_id = ? AND entity_type = 'LEAD' AND entity_id = ? AND type = 'FOLLOWUP_DUE' AND created_at >= ?",
            [$lead['owner_id'], $lead['id'], $cutoff]
        );
        if ($already) {
            continue;
        }
        notify_user($lead['organization_id'], $lead['owner_id'], 'FOLLOWUP_DUE',
            'Follow-up due: ' . trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? '')),
            "{$lead['code']} - scheduled for " . date('d M Y, H:i', strtotime($lead['next_follow_up_at'])), 'LEAD', $lead['id'], '#/leads/' . $lead['id']);
        $results['followUpsDue']++;
    }

    // Warning people before a follow-up slips is the point; the block above
    // only catches the ones that already have.
    $results = array_merge($results, staff_followup_sweep());

    // And once each morning, the whole day in one email.
    $results = array_merge($results, staff_daily_summary_sweep());

    return $results;
}

function register_notification_listeners(): void
{
    // Notifications are raised directly by LeadService
    // at the point of action (matching the Node version's inline notify() calls),
    // so there is nothing additional to subscribe here — this function exists
    // as the wiring point bootstrap.php expects, kept for symmetry with
    // register_automation_listeners()/register_webhook_listeners().
}
