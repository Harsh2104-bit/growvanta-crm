<?php
declare(strict_types=1);

/**
 * Public lead capture — ported from src/routes/publicCapture.js: the hosted
 * form page, the embeddable script, and the submit endpoint they both post to.
 *
 * These routes are deliberately unauthenticated — they are the front door for
 * website traffic. Protection comes from: a per-form origin allow-list, a
 * per-IP rate limit, a honeypot field, and the fact that a submission can only
 * ever write the lead columns a form has explicitly mapped (FORMS_MAPPABLE).
 */

require_once __DIR__ . '/forms.inc.php';

function capture_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function capture_load_active_form(string $publicKey): array
{
    $form = db_one(
        'SELECT f.*, o.name AS org_name, o.is_active AS org_is_active, o.logo_url AS org_logo_url
         FROM web_forms f JOIN organizations o ON o.id = f.organization_id WHERE f.public_key = ?',
        [$publicKey]
    );
    if (!$form || !gv_bool($form['is_active']) || !gv_bool($form['org_is_active'])) {
        throw not_found('This form is no longer available.');
    }
    return $form;
}

/** Applies the form's origin allow-list. An empty list means "any origin". */
function capture_origin_allowed(array $form, ?string $origin): bool
{
    $list = array_values(array_filter(array_map('trim', explode(',', (string) ($form['allowed_origins'] ?? '')))));
    if (!$list) {
        return true;
    }
    if (!$origin) {
        return false;
    }
    $originHost = parse_url($origin, PHP_URL_SCHEME) . '://' . parse_url($origin, PHP_URL_HOST);
    foreach ($list as $allowed) {
        if ($allowed === '*') {
            return true;
        }
        $allowedParts = parse_url($allowed);
        if (!empty($allowedParts['host'])) {
            if (($allowedParts['scheme'] ?? 'https') . '://' . $allowedParts['host'] === $originHost) {
                return true;
            }
        } elseif ($allowed === $origin) {
            return true;
        }
    }
    return false;
}

function capture_apply_cors(array $form): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (capture_origin_allowed($form, $origin)) {
        header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: content-type');
}

function capture_record_submission(array $form, array $payload, string $status, ?string $message, ?string $leadId = null): void
{
    db_exec(
        'INSERT INTO form_submissions (id, form_id, organization_id, payload, lead_id, status, message, ip, user_agent, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())',
        [gv_id(), $form['id'], $form['organization_id'], to_json($payload), $leadId, $status, $message ? substr($message, 0, 300) : null, client_ip(), user_agent()]
    );
}

$section = $rest[0] ?? null;
$publicKey = $rest[1] ?? null;
$action = $rest[2] ?? null;

// -----------------------------------------------------------------------------
// Demo accounts for the sign-in page.
//
// Read live rather than hard-coded, because a hard-coded list drifts the moment
// the seed changes and then offers accounts that do not exist. Refuses outright
// outside a local/dev environment: this lists real email addresses, and it must
// never be reachable on a production install.
// -----------------------------------------------------------------------------
if ($method === 'GET' && $section === 'demo-accounts') {
    // Fail closed: only an explicitly local/development install exposes this.
    // An unset or unrecognised APP_ENV is treated as production.
    if (!in_array((string) gv_config('env'), ['local', 'development'], true)) {
        throw not_found('Not available.');
    }
    $rows = db_all(
        "SELECT users.email, roles.name AS role_name, roles.level,
                organizations.name AS org_name, organizations.company_type, organizations.is_platform,
                users.is_platform_admin
           FROM users
           JOIN roles ON roles.id = users.role_id
           JOIN organizations ON organizations.id = users.organization_id
          WHERE users.is_active = 1
          ORDER BY users.is_platform_admin DESC, organizations.is_platform DESC,
                   organizations.name ASC, roles.level ASC
          LIMIT 30"
    );
    json_ok(array_map(fn($r) => [
        'email' => $r['email'],
        'role' => gv_bool($r['is_platform_admin']) ? 'Platform Super Admin' : $r['role_name'],
        'company' => $r['org_name'],
        'companyType' => gv_bool($r['is_platform']) ? 'PLATFORM' : $r['company_type'],
    ], $rows));

// -----------------------------------------------------------------------------
// Form definition (used by the embed script)
// -----------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'forms' && $publicKey && $action === null) {
    $form = capture_load_active_form($publicKey);
    capture_apply_cors($form);
    $fields = array_values(array_filter(safe_json($form['fields'] ?? '[]', []), fn($f) => empty($f['hidden']) || !empty($f['defaultValue'])));
    json_ok([
        'name' => $form['name'], 'description' => $form['description'], 'fields' => $fields,
        'submitLabel' => $form['submit_label'], 'successMessage' => $form['success_message'],
        'redirectUrl' => $form['redirect_url'], 'organization' => $form['org_name'],
    ]);
} elseif ($method === 'OPTIONS' && $section === 'forms' && $publicKey && $action === 'submit') {
    $form = capture_load_active_form($publicKey);
    capture_apply_cors($form);
    http_response_code(204);

// -----------------------------------------------------------------------------
// Submission
// -----------------------------------------------------------------------------
} elseif ($method === 'POST' && $section === 'forms' && $publicKey && $action === 'submit') {
    rate_limit_capture();
    $form = capture_load_active_form($publicKey);
    capture_apply_cors($form);

    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
    if (!capture_origin_allowed($form, $origin)) {
        json_error(403, 'ORIGIN_NOT_ALLOWED', 'This form is not enabled for that website.');
        return;
    }

    // Accept JSON (embed script / fetch) and classic form posts alike.
    $body = request_body();
    if (!$body && $_POST) {
        $body = $_POST;
    }

    // Honeypot: real users never fill a hidden field. Answer 200 so bots learn nothing.
    if (!empty($body['_gv_hp'])) {
        capture_record_submission($form, $body, 'REJECTED', 'Honeypot triggered');
        json_ok(['received' => true, 'message' => $form['success_message']]);
        return;
    }

    $definitions = safe_json($form['fields'] ?? '[]', []);
    $leadInput = ['customFields' => []];
    $errors = [];

    foreach ($definitions as $field) {
        $raw = $body[$field['key']] ?? ($field['defaultValue'] ?? '');
        $value = is_string($raw) ? trim($raw) : $raw;
        if (!empty($field['required']) && ($value === '' || $value === null)) {
            $errors[] = ['field' => $field['key'], 'message' => "{$field['label']} is required"];
            continue;
        }
        if ($value === '' || $value === null) {
            continue;
        }
        if (!empty($field['mapTo']) && in_array($field['mapTo'], FORMS_MAPPABLE, true)) {
            $leadInput[$field['mapTo']] = $value;
        } else {
            $leadInput['customFields'][$field['key']] = $value;
        }
    }
    if ($errors) {
        json_error(422, 'VALIDATION_ERROR', 'Please check the form', $errors);
        return;
    }

    // Tracking parameters the page passes through, plus what we can observe.
    foreach (['utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'gclid', 'fbclid', 'landingPage'] as $key) {
        if (empty($leadInput[$key]) && !empty($body[$key])) {
            $leadInput[$key] = substr((string) $body[$key], 0, 400);
        }
    }
    if (empty($leadInput['landingPage']) && $origin) {
        $leadInput['landingPage'] = substr((string) $origin, 0, 400);
    }
    $leadInput['referrerUrl'] = isset($_SERVER['HTTP_REFERER']) ? substr($_SERVER['HTTP_REFERER'], 0, 400) : null;

    // The form, not the visitor, decides source and campaign. Setting them on the
    // input (rather than patching after creation, as the Node version did) means
    // scoring and automation see the real source on the very first evaluation.
    if ($form['source_id']) {
        $leadInput['sourceId'] = $form['source_id'];
    }
    // A form tied to a project produces leads already attributed to it, which
    // is the whole point of a per-project landing page.
    if ($form['project_id']) {
        $leadInput['projectId'] = $form['project_id'];
    }

    try {
        $result = create_lead($leadInput, [
            'organizationId' => $form['organization_id'],
            'origin' => 'FORM',
            'duplicatePolicy' => 'FLAG',
            'ownerId' => $form['assign_to_user_id'] ?: null,
            'teamId' => $form['assign_to_team_id'] ?: null,
            'skipAssignment' => !gv_bool($form['use_assignment_rules']) && !$form['assign_to_user_id'],
        ]);
    } catch (AppException $e) {
        capture_record_submission($form, $body, 'REJECTED', $e->getMessage());
        if ($e->status === 400 || $e->status === 409) {
            json_error($e->status, $e->errorCode, $e->getMessage());
            return;
        }
        throw $e;
    }

    $duplicateCount = count($result['duplicates']);
    capture_record_submission(
        $form,
        $body,
        $duplicateCount ? 'DUPLICATE' : 'ACCEPTED',
        $duplicateCount ? "Matched $duplicateCount existing lead(s)" : null,
        $result['lead']['id']
    );
    db_exec('UPDATE web_forms SET submission_count = submission_count + 1 WHERE id = ?', [$form['id']]);

    json_ok(['received' => true, 'message' => $form['success_message'], 'redirectUrl' => $form['redirect_url'] ?: null]);

// -----------------------------------------------------------------------------
// Hosted form page
// -----------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'hosted' && $publicKey) {
    $form = capture_load_active_form($publicKey);
    $fields = safe_json($form['fields'] ?? '[]', []);

    $inputs = [];
    foreach ($fields as $field) {
        if (!empty($field['hidden'])) {
            continue;
        }
        $id = 'gv_' . capture_escape($field['key']);
        $required = !empty($field['required']) ? 'required' : '';
        $label = '<label for="' . $id . '">' . capture_escape($field['label']) . (!empty($field['required']) ? ' <span class="req">*</span>' : '') . '</label>';
        $key = capture_escape($field['key']);
        $placeholder = capture_escape($field['placeholder'] ?? '');

        if (($field['type'] ?? '') === 'textarea') {
            $inputs[] = "<div class=\"field\">$label<textarea id=\"$id\" name=\"$key\" rows=\"4\" $required placeholder=\"$placeholder\"></textarea></div>";
        } elseif (($field['type'] ?? '') === 'select') {
            $options = '';
            foreach ($field['options'] ?? [] as $o) {
                $escaped = capture_escape((string) $o);
                $options .= "<option value=\"$escaped\">$escaped</option>";
            }
            $inputs[] = "<div class=\"field\">$label<select id=\"$id\" name=\"$key\" $required><option value=\"\">Select...</option>$options</select></div>";
        } elseif (($field['type'] ?? '') === 'checkbox') {
            $inputs[] = "<div class=\"field field--check\"><input type=\"checkbox\" id=\"$id\" name=\"$key\" value=\"yes\" $required>$label</div>";
        } else {
            $type = capture_escape($field['type'] ?? 'text');
            $default = capture_escape($field['defaultValue'] ?? '');
            $inputs[] = "<div class=\"field\">$label<input type=\"$type\" id=\"$id\" name=\"$key\" $required placeholder=\"$placeholder\" value=\"$default\"></div>";
        }
    }

    $name = capture_escape($form['name']);
    $sub = capture_escape($form['description'] ?: ('Submitted to ' . $form['org_name']));
    $submitLabel = capture_escape($form['submit_label']);
    $successMessage = capture_escape($form['success_message']);
    $redirectUrl = capture_escape($form['redirect_url'] ?? '');
    $keyEscaped = capture_escape($form['public_key']);
    $inputsHtml = implode("\n      ", $inputs);

    header('Content-Type: text/html; charset=utf-8');
    // The hosted page is meant to be embedded in an iframe on customer sites.
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'self'; frame-ancestors *");
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$name</title>
<style>
  :root { color-scheme: light dark; --bg:#f6f7fb; --card:#fff; --ink:#101828; --muted:#667085; --line:#e4e7ec; --brand:#1d4ed8; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b1020; --card:#141a2e; --ink:#e8ecf6; --muted:#98a2b3; --line:#242c45; --brand:#6d8bff; } }
  * { box-sizing:border-box; }
  body { margin:0; padding:24px; background:var(--bg); color:var(--ink); font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  .card { max-width:560px; margin:0 auto; background:var(--card); border:1px solid var(--line); border-radius:14px; padding:28px; }
  h1 { margin:0 0 4px; font-size:20px; }
  p.sub { margin:0 0 20px; color:var(--muted); font-size:14px; }
  .field { margin-bottom:14px; display:flex; flex-direction:column; gap:6px; }
  .field--check { flex-direction:row; align-items:center; gap:8px; }
  label { font-size:13px; font-weight:600; }
  .req { color:#dc2626; }
  input, select, textarea { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:transparent; color:inherit; font:inherit; }
  input:focus, select:focus, textarea:focus { outline:2px solid var(--brand); outline-offset:1px; }
  button { width:100%; margin-top:8px; padding:12px; border:0; border-radius:8px; background:var(--brand); color:#fff; font-weight:600; font-size:15px; cursor:pointer; }
  button[disabled] { opacity:.6; cursor:progress; }
  .msg { margin-top:14px; padding:12px; border-radius:8px; font-size:14px; display:none; }
  .msg.ok { background:#dcfce7; color:#14532d; display:block; }
  .msg.err { background:#fee2e2; color:#7f1d1d; display:block; }
  .hp { position:absolute; left:-9999px; }
  footer { margin-top:18px; text-align:center; font-size:12px; color:var(--muted); }
</style>
</head>
<body>
  <div class="card">
    <h1>$name</h1>
    <p class="sub">$sub</p>
    <form id="gvForm" novalidate>
      $inputsHtml
      <input class="hp" type="text" name="_gv_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button type="submit">$submitLabel</button>
    </form>
    <div class="msg" id="gvMsg" role="status"></div>
    <footer>Powered by Growvanta CRM</footer>
  </div>
<script src="/public-form.js" data-endpoint="/api/public/forms/$keyEscaped/submit" data-success="$successMessage" data-redirect="$redirectUrl"></script>
</body>
</html>
HTML;

// -----------------------------------------------------------------------------
// Embeddable script: injects a styled form into the host page.
// -----------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'embed' && $publicKey) {
    $key = preg_replace('/\.js$/', '', $publicKey);
    $form = capture_load_active_form($key);
    $fields = safe_json($form['fields'] ?? '[]', []);

    $payload = [
        'key' => $form['public_key'],
        'name' => $form['name'],
        'description' => $form['description'] ?: '',
        'submitLabel' => $form['submit_label'],
        'successMessage' => $form['success_message'],
        'redirectUrl' => $form['redirect_url'] ?: '',
        'endpoint' => gv_config('app_url') . '/api/public/forms/' . $form['public_key'] . '/submit',
        'fields' => array_values(array_map(fn($f) => [
            'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => !empty($f['required']),
            'placeholder' => $f['placeholder'] ?? '', 'options' => $f['options'] ?? [], 'defaultValue' => $f['defaultValue'] ?? '',
        ], array_filter($fields, fn($f) => empty($f['hidden'])))),
    ];
    $configJson = to_json($payload);
    $formName = $form['name'];

    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('Access-Control-Allow-Origin: *');
    echo <<<JS
/* Growvanta CRM embeddable form - $formName */
(function () {
  var CONFIG = $configJson;
  var mount = document.getElementById('growvanta-form-' + CONFIG.key)
    || document.querySelector('[data-growvanta-form="' + CONFIG.key + '"]');
  if (!mount) {
    console.warn('[Growvanta] No mount element found. Add <div id="growvanta-form-' + CONFIG.key + '"></div>');
    return;
  }

  var style = document.createElement('style');
  style.textContent = '.gv-form{font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:560px}'
    + '.gv-form h3{margin:0 0 4px;font-size:18px}.gv-form .gv-sub{margin:0 0 16px;opacity:.7;font-size:14px}'
    + '.gv-f{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}'
    + '.gv-f label{font-size:13px;font-weight:600}'
    + '.gv-f input,.gv-f select,.gv-f textarea{padding:10px 12px;border:1px solid rgba(128,128,128,.35);border-radius:8px;font:inherit;background:transparent;color:inherit;width:100%;box-sizing:border-box}'
    + '.gv-form button{width:100%;padding:12px;border:0;border-radius:8px;background:#1d4ed8;color:#fff;font-weight:600;font-size:15px;cursor:pointer}'
    + '.gv-form button[disabled]{opacity:.6;cursor:progress}'
    + '.gv-msg{margin-top:12px;padding:10px 12px;border-radius:8px;font-size:14px}'
    + '.gv-msg.ok{background:#dcfce7;color:#14532d}.gv-msg.err{background:#fee2e2;color:#7f1d1d}'
    + '.gv-hp{position:absolute!important;left:-9999px!important}';
  document.head.appendChild(style);

  var wrap = document.createElement('div');
  wrap.className = 'gv-form';
  var heading = document.createElement('h3');
  heading.textContent = CONFIG.name;
  wrap.appendChild(heading);
  if (CONFIG.description) {
    var sub = document.createElement('p');
    sub.className = 'gv-sub';
    sub.textContent = CONFIG.description;
    wrap.appendChild(sub);
  }

  var form = document.createElement('form');
  form.noValidate = true;

  CONFIG.fields.forEach(function (field) {
    var row = document.createElement('div');
    row.className = 'gv-f';
    var label = document.createElement('label');
    label.textContent = field.label + (field.required ? ' *' : '');
    var input;
    if (field.type === 'textarea') {
      input = document.createElement('textarea');
      input.rows = 4;
    } else if (field.type === 'select') {
      input = document.createElement('select');
      var blank = document.createElement('option');
      blank.value = '';
      blank.textContent = 'Select...';
      input.appendChild(blank);
      field.options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt;
        o.textContent = opt;
        input.appendChild(o);
      });
    } else {
      input = document.createElement('input');
      input.type = field.type;
      if (field.placeholder) input.placeholder = field.placeholder;
      if (field.defaultValue) input.value = field.defaultValue;
    }
    input.name = field.key;
    input.id = 'gv-' + CONFIG.key + '-' + field.key;
    label.setAttribute('for', input.id);
    if (field.required) input.required = true;
    row.appendChild(label);
    row.appendChild(input);
    form.appendChild(row);
  });

  var honey = document.createElement('input');
  honey.type = 'text';
  honey.name = '_gv_hp';
  honey.className = 'gv-hp';
  honey.tabIndex = -1;
  honey.setAttribute('autocomplete', 'off');
  honey.setAttribute('aria-hidden', 'true');
  form.appendChild(honey);

  var button = document.createElement('button');
  button.type = 'submit';
  button.textContent = CONFIG.submitLabel;
  form.appendChild(button);

  var msg = document.createElement('div');
  wrap.appendChild(form);
  wrap.appendChild(msg);
  mount.appendChild(wrap);

  function utm() {
    var params = new URLSearchParams(window.location.search);
    var map = { utm_source: 'utmSource', utm_medium: 'utmMedium', utm_campaign: 'utmCampaign', utm_term: 'utmTerm', utm_content: 'utmContent', gclid: 'gclid', fbclid: 'fbclid' };
    var out = { landingPage: window.location.href.slice(0, 400) };
    Object.keys(map).forEach(function (k) { if (params.get(k)) out[map[k]] = params.get(k); });
    return out;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    msg.className = '';
    msg.textContent = '';
    var missing = CONFIG.fields.filter(function (f) {
      return f.required && !String(new FormData(form).get(f.key) || '').trim();
    });
    if (missing.length) {
      msg.className = 'gv-msg err';
      msg.textContent = 'Please fill in: ' + missing.map(function (f) { return f.label; }).join(', ');
      return;
    }

    var data = utm();
    new FormData(form).forEach(function (value, key) { data[key] = value; });
    button.disabled = true;
    button.textContent = 'Sending...';

    fetch(CONFIG.endpoint, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (r) {
      return r.json().then(function (body) { return { okay: r.ok, body: body }; });
    }).then(function (result) {
      button.disabled = false;
      button.textContent = CONFIG.submitLabel;
      if (!result.okay) {
        msg.className = 'gv-msg err';
        msg.textContent = (result.body && result.body.error && result.body.error.message) || 'Something went wrong. Please try again.';
        return;
      }
      form.reset();
      msg.className = 'gv-msg ok';
      msg.textContent = (result.body.data && result.body.data.message) || CONFIG.successMessage;
      var redirect = (result.body.data && result.body.data.redirectUrl) || CONFIG.redirectUrl;
      if (redirect) setTimeout(function () { window.location.href = redirect; }, 900);
    }).catch(function () {
      button.disabled = false;
      button.textContent = CONFIG.submitLabel;
      msg.className = 'gv-msg err';
      msg.textContent = 'Network error. Please try again.';
    });
  });
})();
JS;
} else {
    throw not_found('Unknown public capture route');
}
