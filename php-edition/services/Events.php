<?php
declare(strict_types=1);

/**
 * Domain event bus — ported from src/services/events.js.
 *
 * There is no long-lived Node process here, so "fire and forget" means:
 * finish and flush the HTTP response first (via fastcgi_finish_request() when
 * running under PHP-FPM, which almost all modern cPanel accounts use), then
 * run automation/webhook/notification handlers in the same PHP process after
 * the client has already received their response. Under classic mod_php
 * (no fastcgi_finish_request), handlers simply run before the script ends —
 * the request takes a little longer, but nothing breaks.
 */

$GV_EVENT_LISTENERS = [];

// A real-estate CRM has one lifecycle: the lead. "Converted" means booked,
// which is a lead status - there is no separate deal object to raise events
// for, and no task, contact or account to create.
const GV_EVENTS = [
    'lead.created', 'lead.updated', 'lead.status_changed', 'lead.assigned',
    'lead.converted', 'lead.merged', 'lead.deleted',
    'project.created', 'project.updated',
];

function on_domain_event(string $name, callable $handler): void
{
    $GLOBALS['GV_EVENT_LISTENERS'][$name][] = $handler;
}

/** Queues a payload; actually runs once flush_domain_events() is called. */
function emit_domain_event(string $name, array $payload): void
{
    if (!in_array($name, GV_EVENTS, true)) {
        error_log("[events] unknown event emitted: $name");
    }
    $GLOBALS['GV_EVENT_QUEUE'][] = [$name, $payload];
}

/** Drains the queue, running every listener for every queued event. */
function flush_domain_events(): void
{
    $queue = $GLOBALS['GV_EVENT_QUEUE'] ?? [];
    $GLOBALS['GV_EVENT_QUEUE'] = [];
    foreach ($queue as [$name, $payload]) {
        foreach ($GLOBALS['GV_EVENT_LISTENERS'][$name] ?? [] as $handler) {
            try {
                $handler($payload);
            } catch (Throwable $e) {
                error_log("[events] handler threw for $name: " . $e->getMessage());
            }
        }
    }
}

/**
 * Call at the very end of a request: flush the HTTP response to the client,
 * then run domain-event side effects (automation, webhooks) without making
 * the user wait for them.
 */
function finish_request_and_flush_events(): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        // LiteSpeed (very common on cPanel) has its own equivalent.
        litespeed_finish_request();
    }
    flush_domain_events();
}
