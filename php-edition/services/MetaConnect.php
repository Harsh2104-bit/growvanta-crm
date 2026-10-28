<?php
declare(strict_types=1);

/**
 * "Connect Facebook" - one app, many client companies.
 *
 * A CRM that serves several property firms cannot hold one Facebook page's
 * token. Each company has its own page and its own lead forms, so each company
 * authorises this app on its own account and the CRM keeps that company's token
 * against that company's row.
 *
 * The round trip:
 *
 *   1. meta_connect_start()     - mints a state, returns the Facebook URL
 *   2. the admin authorises on facebook.com and picks their page
 *   3. webhooks/meta_oauth.php  - Facebook returns a code to a fixed callback
 *   4. meta_connect_complete()  - code -> user token -> LONG-LIVED user token
 *   5. meta_connect_pages()     - lists the pages that person administers
 *   6. meta_connect_attach()    - stores the page token and SUBSCRIBES the page
 *
 * Step 6's subscription is the one that is easy to leave out and impossible to
 * notice: a page can be connected, its token valid, its forms listed, and not
 * one lead will ever arrive until the page is subscribed to the app's leadgen
 * field. So it is done here, and recorded, rather than assumed.
 */

require_once __DIR__ . '/MetaLeads.php';

// business_management is what lets /me/businesses return pages a person reaches through a
// business portfolio. Without it, a page owned by a portfolio never appears in the picker.
// pages_manage_ads is what /{page}/leadgen_forms requires; without it the form list comes
// back empty and the page is stored as a single "all forms" entry, so forms cannot be told
// apart for project routing.
const META_CONNECT_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_metadata,leads_retrieval,business_management,pages_manage_ads';

/** Where Facebook sends the browser back. Must match the app's whitelist exactly. */
function meta_redirect_uri(): string
{
    return rtrim((string) gv_config('app_url'), '/') . '/webhooks/meta_oauth.php';
}

function meta_graph_version(): string
{
    return (string) (gv_config('meta.graph_version') ?: 'v19.0');
}

/** One POST to the Graph API. */
function meta_graph_post(string $url, array $fields): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[meta-connect] POST failed: $error");
        return null;
    }
    $decoded = json_decode((string) $response, true);
    if ($status >= 400) {
        error_log("[meta-connect] POST HTTP $status: " . substr((string) $response, 0, 300));
    }
    return is_array($decoded) ? $decoded : null;
}

/**
 * Step 1. Returns the URL to send the admin to.
 *
 * The state is random, hashed at rest, tied to one organisation and one user,
 * and dies in fifteen minutes - long enough to sign in to Facebook, short
 * enough that an abandoned attempt cannot be picked up later.
 */
function meta_connect_start(string $orgId, string $userId): array
{
    $appId = (string) gv_config('meta.app_id');
    if ($appId === '' || (string) gv_config('meta.app_secret') === '') {
        throw bad_request('Facebook is not set up on this server yet - META_APP_ID and META_APP_SECRET are missing from .env.');
    }

    // One live attempt per user; a second press replaces the first.
    db_exec("DELETE FROM meta_oauth_states WHERE user_id = ? AND status <> 'USED'", [$userId]);

    $state = random_token(32);
    db_exec(
        'INSERT INTO meta_oauth_states (id, organization_id, user_id, state_hash, status, expires_at, created_at)
         VALUES (?,?,?,?,?,?,NOW())',
        [gv_id(), $orgId, $userId, sha256($state), 'PENDING', date('Y-m-d H:i:s', time() + 900)]
    );

    $url = 'https://www.facebook.com/' . meta_graph_version() . '/dialog/oauth?' . http_build_query([
        'client_id' => $appId,
        'redirect_uri' => meta_redirect_uri(),
        'scope' => META_CONNECT_SCOPES,
        'response_type' => 'code',
        'state' => $state,
    ]);

    return ['url' => $url, 'expiresIn' => 900];
}

/**
 * Step 4. Turns the returned code into a long-lived user token.
 *
 * Both exchanges matter. The first gives a token that dies within the hour; the
 * second is what makes the page tokens derived from it permanent. Skipping it
 * produces an integration that works all afternoon and is dead by morning.
 */
function meta_connect_complete(string $state, string $code): array
{
    $row = db_one(
        "SELECT * FROM meta_oauth_states WHERE state_hash = ? AND status = 'PENDING' AND expires_at > NOW()",
        [sha256($state)]
    );
    if (!$row) {
        throw bad_request('That Facebook link has expired. Start the connection again from the CRM.');
    }

    $v = meta_graph_version();
    $appId = (string) gv_config('meta.app_id');
    $appSecret = (string) gv_config('meta.app_secret');

    $short = meta_graph_get("https://graph.facebook.com/$v/oauth/access_token?" . http_build_query([
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'redirect_uri' => meta_redirect_uri(),
        'code' => $code,
    ]));
    if (empty($short['access_token'])) {
        $why = $short['error']['message'] ?? 'Facebook did not return an access token.';
        db_exec('UPDATE meta_oauth_states SET status = ?, error = ? WHERE id = ?', ['FAILED', substr($why, 0, 400), $row['id']]);
        throw bad_request($why);
    }

    $long = meta_graph_get("https://graph.facebook.com/$v/oauth/access_token?" . http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'fb_exchange_token' => $short['access_token'],
    ]));
    // If the exchange fails we keep going with the short-lived token rather
    // than losing the whole attempt - but the page tokens it yields will expire,
    // so the failure is recorded where support can find it.
    $userToken = $long['access_token'] ?? $short['access_token'];
    if (empty($long['access_token'])) {
        error_log('[meta-connect] long-lived exchange failed; page tokens will expire');
    }

    db_exec(
        'UPDATE meta_oauth_states SET status = ?, user_token_enc = ?, expires_at = ? WHERE id = ?',
        ['AUTHORISED', gv_encrypt($userToken), date('Y-m-d H:i:s', time() + 900), $row['id']]
    );

    return ['organizationId' => $row['organization_id'], 'userId' => $row['user_id'], 'stateId' => $row['id']];
}

/** The pending authorisation for this user, if one is waiting for page choice. */
function meta_connect_pending(string $userId): ?array
{
    return db_one(
        "SELECT * FROM meta_oauth_states WHERE user_id = ? AND status = 'AUTHORISED' AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1",
        [$userId]
    );
}

/**
 * Every page this person can manage, keyed by page id, each with its page token.
 *
 * /me/accounts only lists pages where the person holds a role on the page itself.
 * Pages owned by a business portfolio, which the person manages through that
 * portfolio, are missing from it - so the portfolio's owned and client pages are
 * merged in. A page found both ways keeps the /me/accounts entry.
 */
function meta_user_pages(string $userToken): array
{
    $v = meta_graph_version();
    $byId = [];

    $res = meta_graph_get("https://graph.facebook.com/$v/me/accounts?" . http_build_query([
        'fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => $userToken,
    ]));
    if (!isset($res['data'])) {
        $why = $res['error']['message'] ?? 'Facebook did not return any pages.';
        throw bad_request($why);
    }
    foreach ($res['data'] as $p) {
        $byId[$p['id']] = $p;
    }

    // Best effort: an authorisation made before business_management was requested
    // simply returns an error here, and the /me/accounts pages still work.
    $biz = meta_graph_get("https://graph.facebook.com/$v/me/businesses?" . http_build_query([
        'fields' => 'id,name,owned_pages.limit(100){id,name,access_token},client_pages.limit(100){id,name,access_token}',
        'limit' => 50,
        'access_token' => $userToken,
    ]));
    if (!isset($biz['data'])) {
        error_log('[meta-connect] business pages unavailable: ' . ($biz['error']['message'] ?? 'no data'));
    }
    foreach ($biz['data'] ?? [] as $b) {
        foreach (['owned_pages', 'client_pages'] as $edge) {
            foreach ($b[$edge]['data'] ?? [] as $p) {
                if (!isset($byId[$p['id']]) && !empty($p['access_token'])) {
                    $byId[$p['id']] = $p;
                }
            }
        }
    }

    return $byId;
}

/**
 * Step 5. Every page this person administers, with what is already connected
 * marked, so the picker can show the truth rather than a blank list.
 */
function meta_connect_pages(string $orgId, string $userId): array
{
    $pending = meta_connect_pending($userId);
    if (!$pending) {
        throw bad_request('Connect Facebook first - there is no authorisation waiting.');
    }
    $token = (string) gv_decrypt($pending['user_token_enc']);
    $v = meta_graph_version();

    $userPages = meta_user_pages($token);

    $connected = array_column(
        db_all('SELECT DISTINCT meta_page_id FROM meta_lead_forms WHERE organization_id = ?', [$orgId]),
        'meta_page_id'
    );

    $pages = [];
    foreach ($userPages as $p) {
        // The form count is what tells an admin they picked the right page.
        $forms = meta_graph_get("https://graph.facebook.com/$v/{$p['id']}/leadgen_forms?" . http_build_query([
            'fields' => 'id,name,status',
            'limit' => 100,
            'access_token' => $p['access_token'] ?? $token,
        ]));
        $pages[] = [
            'id' => $p['id'],
            'name' => $p['name'] ?? $p['id'],
            'formCount' => isset($forms['data']) ? count($forms['data']) : 0,
            'alreadyConnected' => in_array($p['id'], $connected, true),
        ];
    }
    return $pages;
}

/**
 * Step 6. Stores the chosen pages and subscribes each one.
 *
 * Every lead form on the page is recorded, so a form the customer adds next
 * month arrives without anybody touching the CRM - the webhook carries the form
 * id and the page is what was subscribed.
 */
function meta_connect_attach(string $orgId, string $userId, array $pageIds, ?array $actor = null): array
{
    $pending = meta_connect_pending($userId);
    if (!$pending) {
        throw bad_request('Connect Facebook first - there is no authorisation waiting.');
    }
    $userToken = (string) gv_decrypt($pending['user_token_enc']);
    $v = meta_graph_version();

    $byId = meta_user_pages($userToken);

    $sourceId = db_scalar(
        "SELECT id FROM lead_sources WHERE organization_id = ? AND `key` = 'meta_ads'",
        [$orgId]
    );

    $result = ['pages' => 0, 'forms' => 0, 'problems' => []];

    foreach ($pageIds as $pageId) {
        $page = $byId[$pageId] ?? null;
        if (!$page || empty($page['access_token'])) {
            $result['problems'][] = "No access to page $pageId.";
            continue;
        }
        $pageToken = (string) $page['access_token'];

        // THE step that makes leads arrive. Without it everything below looks
        // correct and the webhook is never called.
        $sub = meta_graph_post(
            "https://graph.facebook.com/$v/$pageId/subscribed_apps",
            ['subscribed_fields' => 'leadgen', 'access_token' => $pageToken]
        );
        $subscribed = !empty($sub['success']);
        // Why it failed has to survive the request. A toast that fades after a
        // few seconds is no use to someone asking a week later why a page that
        // looks connected has never delivered a lead, so the reason is stored
        // against the page and shown on the card until it is fixed.
        $subError = $subscribed ? null : substr(
            $sub['error']['message'] ?? 'Could not subscribe this page to lead notifications.',
            0,
            400
        );
        if (!$subscribed) {
            $result['problems'][] = ($page['name'] ?? $pageId) . ': ' . $subError;
        }

        $forms = meta_graph_get("https://graph.facebook.com/$v/$pageId/leadgen_forms?" . http_build_query([
            'fields' => 'id,name,status', 'limit' => 100, 'access_token' => $pageToken,
        ]));
        $formList = $forms['data'] ?? [];

        // A page with no form yet is still worth connecting: it is subscribed,
        // so the first form the customer publishes starts delivering at once.
        if (!$formList) {
            $formList = [['id' => 'page-' . $pageId, 'name' => ($page['name'] ?? $pageId) . ' (all forms)']];
        }

        foreach ($formList as $f) {
            $existing = db_one(
                'SELECT id FROM meta_lead_forms WHERE meta_page_id = ? AND meta_form_id = ?',
                [$pageId, $f['id']]
            );
            if ($existing) {
                db_exec(
                    'UPDATE meta_lead_forms SET organization_id = ?, page_name = ?, form_name = ?,
                            page_access_token = ?, source_id = COALESCE(source_id, ?), is_active = 1,
                            subscribed_at = ?, last_error = ?, updated_at = NOW()
                      WHERE id = ?',
                    [$orgId, $page['name'] ?? null, $f['name'] ?? null, gv_encrypt($pageToken),
                        $sourceId, $subscribed ? date('Y-m-d H:i:s') : null, $subError, $existing['id']]
                );
            } else {
                db_exec(
                    'INSERT INTO meta_lead_forms
                       (id, organization_id, meta_page_id, page_name, meta_form_id, form_name,
                        page_access_token, source_id, is_active, subscribed_at, last_error, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,1,?,?,NOW(),NOW())',
                    [gv_id(), $orgId, $pageId, $page['name'] ?? null, $f['id'], $f['name'] ?? null,
                        gv_encrypt($pageToken), $sourceId, $subscribed ? date('Y-m-d H:i:s') : null, $subError]
                );
            }
            $result['forms']++;
        }
        $result['pages']++;
    }

    // The user token could list every page this person administers, including
    // ones they did not connect. It has done its job.
    db_exec("UPDATE meta_oauth_states SET status = 'USED', user_token_enc = NULL WHERE id = ?", [$pending['id']]);

    if ($actor) {
        audit_record($orgId, $actor, 'meta.pages_connected', [
            'entityType' => 'INTEGRATION', 'entityLabel' => 'Meta Lead Ads',
            'changes' => ['pages' => $result['pages'], 'forms' => $result['forms']],
        ]);
    }
    return $result;
}

/**
 * Ask Facebook what is actually wrong.
 *
 * Every failure in this integration looks identical from the CRM: a page that
 * is connected, a card that looks healthy, and no leads. The cause is always on
 * Facebook's side - a permission that was never granted, an app whose webhook
 * was never subscribed, a page token that has expired - and none of it is
 * visible from here unless something asks.
 *
 * So this asks, using the app credentials already in .env. Nobody has to paste
 * a secret anywhere, and the answer comes back in words rather than as a
 * missing lead three weeks later.
 *
 * Read-only. It changes nothing on Facebook and nothing in the database.
 */
function meta_connect_diagnose(string $orgId): array
{
    $v       = meta_graph_version();
    $appId   = (string) gv_config('meta.app_id');
    $secret  = (string) gv_config('meta.app_secret');
    $verify  = (string) gv_config('meta.verify_token');
    $appUrl  = rtrim((string) gv_config('app_url'), '/');
    $checks  = [];

    $add = function (string $name, string $state, string $detail, string $fix = '') use (&$checks) {
        $checks[] = ['name' => $name, 'state' => $state, 'detail' => $detail, 'fix' => $fix];
    };

    // ---- 1. is the server configured at all ----------------------------
    if ($appId === '' || $secret === '') {
        $add('Server configuration', 'FAIL',
            'META_APP_ID or META_APP_SECRET is missing from .env.',
            'Add both from developers.facebook.com > your app > Settings > Basic.');
        return ['checks' => $checks, 'pages' => [], 'ranAt' => gmdate('c')];
    }
    $add('Server configuration', 'PASS', "App ID $appId is configured, and the app secret is present.");

    $add('Webhook verify token',
        $verify === '' ? 'FAIL' : 'PASS',
        $verify === ''
            ? 'META_VERIFY_TOKEN is empty, so Facebook cannot verify the webhook.'
            : 'META_VERIFY_TOKEN is set.',
        $verify === '' ? 'Set any string in .env and paste the same value into Meta > Webhooks.' : '');

    // ---- 2. does the app itself listen for leadgen ----------------------
    // The step everyone forgets. A page can be subscribed to the app perfectly
    // while the APP is not subscribed to the leadgen field - and then Facebook
    // has nowhere to deliver to. It looks like nothing is wrong.
    $appToken = $appId . '|' . $secret;
    $subs = meta_graph_get("https://graph.facebook.com/$v/$appId/subscriptions?"
        . http_build_query(['access_token' => $appToken]));

    if (!isset($subs['data'])) {
        $add('App webhook subscription', 'FAIL',
            'Facebook refused the request: ' . ($subs['error']['message'] ?? 'no answer from the Graph API.'),
            'Usually the app secret in .env does not match the app. Check Settings > Basic.');
    } else {
        $page = null;
        foreach ($subs['data'] as $row) {
            if (($row['object'] ?? '') === 'page') {
                $page = $row;
            }
        }
        if (!$page) {
            $add('App webhook subscription', 'FAIL',
                'This app has no Page webhook at all, so Facebook has nowhere to send leads.',
                'Meta app dashboard > Webhooks > Page > Subscribe, callback '
                . "$appUrl/webhooks/meta_leads.php, and tick the leadgen field.");
        } else {
            $fields = array_column($page['fields'] ?? [], 'name');
            $hasLeadgen = in_array('leadgen', $fields, true);
            $callback = (string) ($page['callback_url'] ?? '');
            $expected = "$appUrl/webhooks/meta_leads.php";

            $add('App webhook subscription',
                $hasLeadgen ? 'PASS' : 'FAIL',
                $hasLeadgen
                    ? 'The app is subscribed to the Page leadgen field.'
                    : 'The Page webhook exists but leadgen is NOT among its fields ('
                      . (implode(', ', $fields) ?: 'none') . ').',
                $hasLeadgen ? '' : 'Meta app dashboard > Webhooks > Page > Subscribe to the leadgen field.');

            $add('Webhook callback URL',
                $callback === $expected ? 'PASS' : 'FAIL',
                $callback === $expected
                    ? "Facebook will deliver to $callback"
                    : "Facebook is delivering to \"$callback\", but this server answers at \"$expected\".",
                $callback === $expected ? '' : "Change the callback URL in the Meta app dashboard to $expected");

            if (array_key_exists('active', $page) && !$page['active']) {
                $add('Webhook status', 'FAIL',
                    'Facebook has marked this webhook inactive, which it does after repeated delivery failures.',
                    'Fix the callback URL, then re-subscribe in the Meta app dashboard.');
            }
        }
    }

    // ---- 2b. has Facebook ever actually called this server --------------
    // The subscription can be perfect and the deliveries still not arrive.
    // Only the receiving end knows, so ask it.
    // Scoped to this company, and to the pages it has connected.
    //
    // An unmapped delivery carries no organisation_id, so counting every row
    // would have shown one customer how much traffic another customer's pages
    // receive. Matching on their own page ids as well keeps their own dropped
    // deliveries visible without exposing anyone else's.
    $ownPages = array_column(
        db_all('SELECT DISTINCT meta_page_id FROM meta_lead_forms WHERE organization_id = ?', [$orgId]),
        'meta_page_id'
    );
    $params = [$orgId];
    $pageClause = '';
    if ($ownPages) {
        $pageClause = ' OR meta_page_id IN (' . implode(',', array_fill(0, count($ownPages), '?')) . ')';
        $params = array_merge($params, $ownPages);
    }
    $recent = db_all(
        "SELECT status, COUNT(*) AS n, MAX(created_at) AS last_at
           FROM meta_webhook_events
          WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND (organization_id = ?$pageClause)
       GROUP BY status",
        $params
    );
    $total = array_sum(array_column($recent, 'n'));
    $byStatus = [];
    foreach ($recent as $r) {
        $byStatus[] = $r['status'] . ': ' . $r['n'];
    }
    $bad = 0;
    foreach ($recent as $r) {
        if (in_array($r['status'], ['BAD_SIGNATURE', 'NO_SIGNATURE'], true)) {
            $bad += (int) $r['n'];
        }
    }

    if ($total === 0) {
        $add('Deliveries received', 'FAIL',
            'Facebook has not called this server once in the last 7 days.',
            'If a lead was submitted in that time, the delivery is not reaching the '
            . 'server at all. Check that ' . $appUrl . '/webhooks/meta_leads.php is '
            . 'reachable from outside, and that no firewall or security rule is '
            . 'blocking POST requests to it.');
    } elseif ($bad > 0) {
        $add('Deliveries received', 'FAIL',
            "$total delivery attempt(s) in the last 7 days, but $bad were refused for a bad signature ("
            . implode(', ', $byStatus) . ').',
            'META_APP_SECRET in .env does not match the app that is sending. Copy it '
            . 'again from Settings > Basic and paste it into .env.');
    } else {
        $add('Deliveries received', 'PASS',
            "$total delivery(ies) reached this server in the last 7 days (" . implode(', ', $byStatus) . ').');
    }

    // ---- 3. is the OAuth redirect URI going to match --------------------
    $add('OAuth redirect URI', 'INFO',
        'This exact value must be listed under Facebook Login > Settings > Valid OAuth Redirect URIs: '
        . meta_redirect_uri());

    // ---- 4. each connected page, as Facebook sees it --------------------
    $rows = db_all(
        'SELECT meta_page_id, page_name, page_access_token, subscribed_at, last_error,
                COUNT(*) AS form_count
           FROM meta_lead_forms
          WHERE organization_id = ?
       GROUP BY meta_page_id, page_name, page_access_token, subscribed_at, last_error',
        [$orgId]
    );

    $pages = [];
    foreach ($rows as $r) {
        $pageId = (string) $r['meta_page_id'];
        $token  = gv_decrypt($r['page_access_token']);
        $entry  = [
            'pageId'    => $pageId,
            'name'      => $r['page_name'] ?: $pageId,
            'formCount' => (int) $r['form_count'],
            'storedError' => $r['last_error'] ?: null,
            'problems'  => [],
        ];

        if (!$token) {
            $entry['problems'][] = 'No usable access token is stored for this page. Connect it again.';
            $pages[] = $entry;
            continue;
        }

        // Is the token still alive? An expired page token is silent: every call
        // just fails, and the CRM has no reason to notice.
        $me = meta_graph_get("https://graph.facebook.com/$v/$pageId?"
            . http_build_query(['fields' => 'id,name', 'access_token' => $token]));
        if (!isset($me['id'])) {
            $entry['problems'][] = 'The stored access token no longer works: '
                . ($me['error']['message'] ?? 'Facebook rejected it.')
                . ' Press Connect Facebook again.';
            $pages[] = $entry;
            continue;
        }
        $entry['name'] = $me['name'] ?? $entry['name'];

        // THE check. Is this page actually subscribed to THIS app for leadgen?
        $sub = meta_graph_get("https://graph.facebook.com/$v/$pageId/subscribed_apps?"
            . http_build_query(['access_token' => $token]));
        if (!isset($sub['data'])) {
            $entry['problems'][] = 'Could not read this page\'s app subscriptions: '
                . ($sub['error']['message'] ?? 'no answer from Facebook.')
                . ' This normally means the pages_manage_metadata permission was not granted.';
        } else {
            $mine = null;
            foreach ($sub['data'] as $app) {
                if ((string) ($app['id'] ?? '') === $appId) {
                    $mine = $app;
                }
            }
            if (!$mine) {
                $entry['problems'][] = 'This page is NOT subscribed to the app. '
                    . 'Leads will never arrive, however healthy the page looks here.';
            } else {
                $f = $mine['subscribed_fields'] ?? [];
                $entry['subscribedFields'] = $f;
                if (!in_array('leadgen', $f, true)) {
                    $entry['problems'][] = 'The page is subscribed to the app, but not for the '
                        . 'leadgen field (' . (implode(', ', $f) ?: 'no fields') . ').';
                }
            }
        }

        $entry['healthy'] = empty($entry['problems']);
        $pages[] = $entry;
    }

    if (!$pages) {
        $add('Connected pages', 'INFO', 'No Facebook page is connected to this company yet.',
            'Press Connect Facebook above.');
    }

    return ['checks' => $checks, 'pages' => $pages, 'ranAt' => gmdate('c')];
}

/**
 * Abandoned and spent attempts are rubbish; the sweep clears them.
 *
 * Runs inside the nightly cron, which must not die because migration 007 has
 * not been applied on a given install: tidying up Facebook state is the least
 * important thing that sweep does, and follow-up alerts are the most.
 */
function meta_connect_sweep(): array
{
    $where = "WHERE expires_at < NOW() OR status = 'USED'";
    try {
        $n = (int) db_scalar("SELECT COUNT(*) FROM meta_oauth_states $where");
        db_exec("DELETE FROM meta_oauth_states $where");
    } catch (Throwable $e) {
        error_log('[meta-connect] sweep skipped: ' . $e->getMessage());
        return ['metaOauthStatesCleared' => 0];
    }
    return ['metaOauthStatesCleared' => $n];
}
