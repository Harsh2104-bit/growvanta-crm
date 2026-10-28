<?php
declare(strict_types=1);

/**
 * Email aimed at the sales team, not the buyer.
 *
 * Two moments matter to the person who owns a lead: it arrived, and its
 * follow-up is about to come due. The in-app notification centre already
 * records both - but nobody keeps a CRM tab open all day, which is the whole
 * reason a lead goes cold.
 *
 * Three rules this file keeps:
 *
 *   1. It sends from the platform's own mailbox, configured once in .env, for
 *      every company. A staff alert is not a customer-facing message, so it
 *      must not wait on a tenant setting up SMTP - and most never will. The
 *      tenant's own channel is untouched; that one still speaks to buyers.
 *   2. It refuses to pretend. If the platform mailer is not configured, or the
 *      company has the alert switched off, nothing is "sent" - the reason is
 *      written to the message log exactly like a skipped welcome message.
 *   3. It is a copy, never the record. A failure here is logged and swallowed;
 *      it must not roll back the lead that has just been created.
 */

require_once __DIR__ . '/Comms.php';

/**
 * The platform's own mailer, used for every staff alert in every company.
 *
 * These messages go to the salesperson, not the buyer - so they must not wait
 * on a customer configuring SMTP. A company that has never opened the WhatsApp
 * & Email screen still gets told when a lead lands. One mailbox, set once in
 * .env, sends for everybody.
 *
 * A tenant's own channel stays exactly where it was: it is what speaks to
 * buyers, and nothing here touches it.
 */
function staff_mailer_settings(): array
{
    $from = (string) gv_config('mail.from');
    // SMTP_FROM may be either "Name <addr>" or a bare address.
    $fromEmail = $from;
    $fromName = 'Growvanta CRM';
    if (preg_match('/^\s*(.*?)\s*<\s*([^>]+)\s*>\s*$/', $from, $m)) {
        $fromName = trim($m[1], " \"'") ?: $fromName;
        $fromEmail = trim($m[2]);
    }

    return [
        'smtp_host' => (string) gv_config('mail.host'),
        'smtp_port' => (int) gv_config('mail.port'),
        'smtp_encryption' => strtoupper((string) gv_config('mail.encryption')),
        'smtp_username' => (string) gv_config('mail.user'),
        'smtp_password_plain' => (string) gv_config('mail.pass'),
        'from_email' => $fromEmail,
        'from_name' => $fromName,
    ];
}

/**
 * Can the platform mailer send right now?
 *
 * Nothing here asks about the tenant. The only question is whether the one
 * shared mailbox is configured, so the answer is the same for every company.
 */
function staff_email_ready(array $settings): array
{
    if (empty($settings['smtp_host'])) {
        return [
            'ready' => false,
            'reason' => 'The platform mailer is not configured - set SMTP_HOST, SMTP_USER, SMTP_PASS and SMTP_FROM in .env.',
        ];
    }
    if (empty($settings['from_email'])) {
        return ['ready' => false, 'reason' => 'SMTP_FROM is missing from .env.'];
    }
    return ['ready' => true, 'reason' => null];
}

/** Shared chrome so both alerts look like they came from the same system. */
function staff_email_shell(string $heading, string $introLine, array $rows, string $ctaLabel, string $ctaUrl): string
{
    $cells = '';
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $cells .= '<tr>'
            . '<td style="padding:7px 16px 7px 0;color:#5A6B62;font-size:13px;white-space:nowrap;vertical-align:top">'
            . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:7px 0;color:#101A14;font-size:14px;font-weight:600">'
            . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $intro = htmlspecialchars($introLine, ENT_QUOTES, 'UTF-8');
    $cta = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div style="background:#F7F8F6;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
  <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #DCE2DB;border-radius:10px;overflow:hidden">
    <div style="padding:20px 24px;border-bottom:1px solid #E9EDE7">
      <div style="font-size:17px;font-weight:700;color:#101A14">$h</div>
      <div style="font-size:14px;color:#5A6B62;margin-top:4px">$intro</div>
    </div>
    <div style="padding:16px 24px">
      <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">$cells</table>
    </div>
    <div style="padding:4px 24px 24px">
      <a href="$url" style="display:inline-block;background:#0E7C4A;color:#fff;text-decoration:none;padding:11px 20px;border-radius:7px;font-size:14px;font-weight:600">$cta</a>
    </div>
  </div>
  <div style="max-width:560px;margin:14px auto 0;font-size:12px;color:#8A9990;text-align:center">
    Sent by your CRM because you own this lead.
  </div>
</div>
HTML;
}

/** One place that actually pushes a staff message out and records the attempt. */
function staff_email_dispatch(string $orgId, string $toEmail, string $toName, string $subject, string $html, string $trigger, ?string $leadId): string
{
    $settings = staff_mailer_settings();
    $gate = staff_email_ready($settings);
    if (!$gate['ready']) {
        comms_log($orgId, 'EMAIL', [
            'leadId' => $leadId, 'triggeredBy' => $trigger, 'status' => 'SKIPPED',
            'recipient' => $toEmail, 'reason' => $gate['reason'],
        ]);
        return 'SKIPPED';
    }
    if (!$toEmail) {
        comms_log($orgId, 'EMAIL', [
            'leadId' => $leadId, 'triggeredBy' => $trigger, 'status' => 'SKIPPED',
            'reason' => 'The recipient has no email address on their profile.',
        ]);
        return 'SKIPPED';
    }

    $result = smtp_send($settings, $toEmail, $toName, $subject, $html);
    comms_log($orgId, 'EMAIL', [
        'leadId' => $leadId, 'triggeredBy' => $trigger,
        'recipient' => $toEmail, 'subject' => $subject,
        'status' => !empty($result['ok']) ? 'SENT' : 'FAILED',
        'reason' => !empty($result['ok']) ? null : ($result['error'] ?? 'Unknown SMTP error'),
    ]);
    return !empty($result['ok']) ? 'SENT' : 'FAILED';
}

/** Owner + the optional standing copy, de-duplicated. */
function staff_notify_recipients(array $settings, ?array $owner): array
{
    $out = [];
    if ($owner && !empty($owner['email'])) {
        $out[strtolower($owner['email'])] = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
    }
    $cc = trim((string) ($settings['notify_cc_email'] ?? ''));
    if ($cc !== '' && !isset($out[strtolower($cc)])) {
        $out[strtolower($cc)] = '';
    }
    return $out;
}

function staff_lead_rows(array $lead, ?array $project): array
{
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    return [
        'Lead'      => $name !== '' ? $name : ($lead['code'] ?? ''),
        'Reference' => $lead['code'] ?? null,
        'Phone'     => $lead['phone'] ?? null,
        'Email'     => $lead['email'] ?? null,
        'Project'   => $project['name'] ?? null,
        'City'      => $lead['city'] ?? null,
        'Notes'     => $lead['notes'] ? mb_substr((string) $lead['notes'], 0, 180) : null,
    ];
}

/**
 * "A new lead is yours."
 *
 * Fired on creation and on reassignment, because being handed somebody else's
 * lead is the same news to the person receiving it.
 */
function staff_notify_new_lead(string $orgId, string $leadId, string $trigger = 'STAFF_NEW_LEAD'): string
{
    try {
        $settings = email_settings($orgId);
        if (!gv_bool($settings['notify_new_lead'] ?? 0)) {
            return 'DISABLED';
        }
        $lead = db_one('SELECT * FROM leads WHERE id = ? AND organization_id = ?', [$leadId, $orgId]);
        if (!$lead || !$lead['owner_id']) {
            return 'SKIPPED';
        }
        $owner = db_one('SELECT id, first_name, last_name, email FROM users WHERE id = ?', [$lead['owner_id']]);
        $project = $lead['project_id']
            ? db_one('SELECT name FROM projects WHERE id = ?', [$lead['project_id']])
            : null;
        $source = $lead['source_id']
            ? db_scalar('SELECT name FROM lead_sources WHERE id = ?', [$lead['source_id']])
            : null;

        $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $rows = staff_lead_rows($lead, $project);
        $rows['Source'] = $source;
        $html = staff_email_shell(
            'New lead assigned to you',
            $source ? "Came in through $source." : 'A new enquiry has landed.',
            $rows,
            'Open the lead',
            rtrim((string) gv_config('app_url'), '/') . '/index.html#/leads/' . $lead['id']
        );

        $last = 'SKIPPED';
        foreach (staff_notify_recipients($settings, $owner) as $email => $name) {
            $last = staff_email_dispatch(
                $orgId, $email, $name,
                'New lead: ' . ($leadName !== '' ? $leadName : $lead['code']),
                $html, $trigger, $lead['id']
            );
        }
        return $last;
    } catch (Throwable $e) {
        // Never let an alert take the lead down with it.
        error_log('[staff-notify] new lead: ' . $e->getMessage());
        return 'FAILED';
    }
}

/** "Your follow-up is coming up." Sent ahead of time, not after it slipped. */
function staff_notify_followup(string $orgId, array $lead, array $settings): string
{
    try {
        $owner = db_one('SELECT id, first_name, last_name, email FROM users WHERE id = ?', [$lead['owner_id']]);
        $project = !empty($lead['project_id'])
            ? db_one('SELECT name FROM projects WHERE id = ?', [$lead['project_id']])
            : null;

        $due = strtotime((string) $lead['next_follow_up_at']);
        $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));

        $rows = staff_lead_rows($lead, $project);
        $rows['Due at'] = date('d M Y, g:i a', $due);

        $html = staff_email_shell(
            'Follow-up coming up',
            'Scheduled for ' . date('g:i a', $due) . ' today.',
            $rows,
            'Open the lead',
            rtrim((string) gv_config('app_url'), '/') . '/index.html#/leads/' . $lead['id']
        );

        $last = 'SKIPPED';
        foreach (staff_notify_recipients($settings, $owner) as $email => $name) {
            $last = staff_email_dispatch(
                $orgId, $email, $name,
                'Follow-up due: ' . ($leadName !== '' ? $leadName : $lead['code']),
                $html, 'STAFF_FOLLOWUP', $lead['id']
            );
        }
        return $last;
    } catch (Throwable $e) {
        error_log('[staff-notify] follow-up: ' . $e->getMessage());
        return 'FAILED';
    }
}

/**
 * Walks each organisation that wants follow-up warnings and emails the ones
 * falling inside its lead time.
 *
 * Called from the sweep. Deduplicated through the notifications table, so a
 * sweep running every fifteen minutes cannot send the same warning four times.
 */
function staff_followup_sweep(): array
{
    $sent = 0;
    $orgs = db_all(
        // No is_enabled / credentials_valid here: those describe the tenant's
        // own buyer-facing channel. Staff alerts ride the platform mailer, so a
        // company that has never configured email still gets them.
        'SELECT organization_id, followup_lead_minutes FROM email_settings
          WHERE notify_followup = 1'
    );

    foreach ($orgs as $row) {
        $orgId = $row['organization_id'];
        $minutes = max(15, (int) ($row['followup_lead_minutes'] ?: 60));
        $settings = email_settings($orgId);

        // One organisation at a time so idx_lead_org_followup does the work.
        $leads = db_all(
            "SELECT * FROM leads
              WHERE organization_id = ?
                AND next_follow_up_at IS NOT NULL
                AND next_follow_up_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? MINUTE)
                AND owner_id IS NOT NULL
                AND is_converted = 0 AND is_duplicate = 0
              LIMIT 200",
            [$orgId, $minutes]
        );

        foreach ($leads as $lead) {
            $already = db_one(
                "SELECT id FROM notifications
                  WHERE user_id = ? AND entity_type = 'LEAD' AND entity_id = ?
                    AND type = 'FOLLOWUP_UPCOMING' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)",
                [$lead['owner_id'], $lead['id']]
            );
            if ($already) {
                continue;
            }

            $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
            // The in-app note is written first and is what stops a repeat, so a
            // bounced email cannot turn into a message every fifteen minutes.
            notify_user(
                $orgId, $lead['owner_id'], 'FOLLOWUP_UPCOMING',
                'Follow-up soon: ' . ($name !== '' ? $name : $lead['code']),
                $lead['code'] . ' - due at ' . date('g:i a', strtotime((string) $lead['next_follow_up_at'])),
                'LEAD', $lead['id'], '#/leads/' . $lead['id']
            );
            staff_notify_followup($orgId, $lead, $settings);
            $sent++;
        }
    }

    return ['followUpsUpcoming' => $sent];
}

/**
 * Renders one section of the morning digest - a heading and a list of leads.
 *
 * Deliberately not the single-lead shell above: this email answers "what is on
 * me today", so the time comes first on every line and the name second, which
 * is the order somebody reads a day's plan in.
 */
function staff_digest_section(string $heading, array $rows, string $timeField, string $appUrl): string
{
    if (!$rows) {
        return '';
    }
    $items = '';
    foreach ($rows as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $name = $name !== '' ? $name : (string) $r['code'];
        $when = !empty($r[$timeField]) ? date('g:i a', strtotime((string) $r[$timeField])) : '';
        $meta = array_filter([
            $r['project_name'] ?? null,
            $r['phone'] ?? null,
            $r['status_name'] ?? null,
        ]);
        $items .= '<tr>'
            . '<td style="padding:9px 14px 9px 0;white-space:nowrap;color:#0E7C4A;font-size:13px;font-weight:700;vertical-align:top">'
            . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:9px 0;border-bottom:1px solid #EEF1EC">'
            . '<a href="' . htmlspecialchars($appUrl . '/index.html#/leads/' . $r['id'], ENT_QUOTES, 'UTF-8')
            . '" style="color:#101A14;font-size:14px;font-weight:600;text-decoration:none">'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>'
            . ($meta
                ? '<div style="color:#5A6B62;font-size:12.5px;margin-top:2px">'
                    . htmlspecialchars(implode(' · ', $meta), ENT_QUOTES, 'UTF-8') . '</div>'
                : '')
            . '</td></tr>';
    }

    return '<div style="padding:4px 24px 8px">'
        . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;'
        . 'color:#5A6B62;margin:14px 0 4px">'
        . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . ' (' . count($rows) . ')</div>'
        . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">'
        . $items . '</table></div>';
}

/**
 * The morning digest: today's follow-ups and site visits, one email per person.
 *
 * Sent once a day, per user, and only to people who actually have something on.
 * An empty digest every morning teaches the reader to ignore the one that
 * matters, so nobody is emailed a blank list.
 */
function staff_daily_summary_sweep(): array
{
    $sent = 0;
    $orgs = db_all(
        'SELECT organization_id, daily_summary_hour FROM email_settings
          WHERE notify_daily_summary = 1'
    );

    foreach ($orgs as $row) {
        $orgId = $row['organization_id'];
        $hour = max(0, min(23, (int) $row['daily_summary_hour']));

        // Nothing goes out before the hour the company chose. The sweep runs
        // every fifteen minutes, so the digest leaves within that of the hour.
        if ((int) date('G') < $hour) {
            continue;
        }

        $prefs = email_settings($orgId);
        $appUrl = rtrim((string) gv_config('app_url'), '/');

        $select = "SELECT l.id, l.code, l.first_name, l.last_name, l.phone, l.owner_id,
                          l.next_follow_up_at, l.site_visit_at,
                          p.name AS project_name, s.name AS status_name
                     FROM leads l
                     LEFT JOIN projects p ON p.id = l.project_id
                     LEFT JOIN lead_statuses s ON s.id = l.status_id
                    WHERE l.organization_id = ? AND l.owner_id IS NOT NULL
                      AND l.is_converted = 0 AND l.is_duplicate = 0";

        $followUps = db_all("$select AND DATE(l.next_follow_up_at) = CURDATE() ORDER BY l.next_follow_up_at ASC", [$orgId]);
        $visits    = db_all("$select AND DATE(l.site_visit_at) = CURDATE() ORDER BY l.site_visit_at ASC", [$orgId]);

        // Group by owner: one email each, not one per lead.
        $byUser = [];
        foreach ($followUps as $r) {
            $byUser[$r['owner_id']]['followUps'][] = $r;
        }
        foreach ($visits as $r) {
            $byUser[$r['owner_id']]['visits'][] = $r;
        }

        foreach ($byUser as $userId => $buckets) {
            $already = db_one(
                "SELECT id FROM notifications
                  WHERE user_id = ? AND type = 'DAILY_SUMMARY' AND DATE(created_at) = CURDATE()",
                [$userId]
            );
            if ($already) {
                continue;
            }

            $owner = db_one('SELECT id, first_name, last_name, email FROM users WHERE id = ? AND is_active = 1', [$userId]);
            if (!$owner) {
                continue;
            }

            $nFollow = count($buckets['followUps'] ?? []);
            $nVisit = count($buckets['visits'] ?? []);
            $parts = array_filter([
                $nFollow ? "$nFollow follow-up" . ($nFollow === 1 ? '' : 's') : null,
                $nVisit ? "$nVisit site visit" . ($nVisit === 1 ? '' : 's') : null,
            ]);

            $body = '<div style="background:#F7F8F6;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">'
                . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #DCE2DB;border-radius:10px;overflow:hidden">'
                . '<div style="padding:20px 24px;border-bottom:1px solid #E9EDE7">'
                . '<div style="font-size:17px;font-weight:700;color:#101A14">Your day — '
                . htmlspecialchars(date('D j M'), ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="font-size:14px;color:#5A6B62;margin-top:4px">'
                . htmlspecialchars(implode(' and ', $parts), ENT_QUOTES, 'UTF-8') . '.</div></div>'
                . staff_digest_section('Follow-ups due today', $buckets['followUps'] ?? [], 'next_follow_up_at', $appUrl)
                . staff_digest_section('Site visits today', $buckets['visits'] ?? [], 'site_visit_at', $appUrl)
                . '<div style="padding:16px 24px 24px">'
                . '<a href="' . htmlspecialchars($appUrl . '/index.html#/leads', ENT_QUOTES, 'UTF-8')
                . '" style="display:inline-block;background:#0E7C4A;color:#fff;text-decoration:none;'
                . 'padding:11px 20px;border-radius:7px;font-size:14px;font-weight:600">Open your leads</a>'
                . '</div></div>'
                . '<div style="max-width:560px;margin:14px auto 0;font-size:12px;color:#8A9990;text-align:center">'
                . 'Sent once each morning, only when you have something on.</div></div>';

            // The in-app note is written first and is what prevents a repeat, so
            // a bounced email cannot become a digest every fifteen minutes.
            notify_user(
                $orgId, $userId, 'DAILY_SUMMARY',
                'Your day: ' . implode(' and ', $parts),
                date('D j M'), null, null, '#/leads'
            );

            staff_email_dispatch(
                $orgId, (string) $owner['email'],
                trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? '')),
                'Your day: ' . implode(' and ', $parts),
                $body, 'STAFF_DAILY_SUMMARY', null
            );
            $sent++;
        }
    }

    return ['dailySummaries' => $sent];
}

function register_staff_notify_listeners(): void
{
    // A lead that arrives already owned - web form, import, assignment rule.
    on_domain_event('lead.created', function (array $payload) {
        $orgId = $payload['organizationId'] ?? null;
        $leadId = $payload['data']['id'] ?? null;
        if ($orgId && $leadId) {
            staff_notify_new_lead($orgId, $leadId);
        }
    });

    // Being handed someone else's lead is the same news to the receiver.
    on_domain_event('lead.assigned', function (array $payload) {
        $orgId = $payload['organizationId'] ?? null;
        $leadId = $payload['data']['id'] ?? null;
        if ($orgId && $leadId) {
            staff_notify_new_lead($orgId, $leadId, 'STAFF_LEAD_ASSIGNED');
        }
    });
}
