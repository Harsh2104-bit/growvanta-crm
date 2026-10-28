<?php
declare(strict_types=1);

/**
 * Web-form definitions shared between the admin route (api/forms.php) and the
 * public capture surface (api/publicCapture.php). Kept in its own include
 * because requiring a route file would execute its dispatch logic.
 */

const FORMS_FIELD_TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox', 'hidden'];

/**
 * Lead columns a form field may map onto. Anything else becomes a custom field
 * value or is ignored — a form can never write to ownerId, score, etc.
 */
const FORMS_MAPPABLE = [
    'firstName', 'lastName', 'email', 'phone', 'altPhone', 'company', 'jobTitle', 'website',
    'country', 'state', 'city', 'address', 'pincode', 'notes', 'expectedValue',
    'utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'medium', 'landingPage',
];

function forms_validate_fields(array $fields): void
{
    if (!$fields) {
        throw bad_request('A form needs at least one field');
    }
    $seen = [];
    foreach ($fields as $field) {
        if (empty($field['key']) || empty($field['label']) || !in_array($field['type'] ?? null, FORMS_FIELD_TYPES, true)) {
            throw bad_request('Every field needs a key, label, and a supported type');
        }
        if (isset($seen[$field['key']])) {
            throw bad_request("Duplicate field key: {$field['key']}");
        }
        $seen[$field['key']] = true;
        if (!empty($field['mapTo']) && !in_array($field['mapTo'], FORMS_MAPPABLE, true)) {
            throw bad_request("\"{$field['mapTo']}\" is not a field a form may write to.");
        }
        if (($field['type'] ?? null) === 'select' && empty($field['options'])) {
            throw bad_request("Field \"{$field['label']}\" is a dropdown but has no options.");
        }
    }
    $mapped = array_filter(array_column($fields, 'mapTo'));
    if (!in_array('firstName', $mapped, true)) {
        throw bad_request('One field must map to firstName.');
    }
    if (!in_array('email', $mapped, true) && !in_array('phone', $mapped, true)) {
        throw bad_request('A form must collect at least an email address or a phone number.');
    }
}

/** Adds the embed/hosted/API snippets the admin UI shows next to each form. */
function forms_shape(array $form): array
{
    $f = camelize($form);
    $f['fields'] = safe_json($form['fields'] ?? '[]', []);
    $appUrl = gv_config('app_url');
    $f['embedScript'] = "<script src=\"$appUrl/embed/{$form['public_key']}.js\" async></script>\n<div id=\"growvanta-form-{$form['public_key']}\"></div>";
    $f['iframeEmbed'] = "<iframe src=\"$appUrl/f/{$form['public_key']}\" style=\"width:100%;max-width:560px;height:640px;border:0\" title=\"{$form['name']}\"></iframe>";
    $f['hostedUrl'] = "$appUrl/f/{$form['public_key']}";
    $f['apiEndpoint'] = "$appUrl/api/public/forms/{$form['public_key']}/submit";
    return $f;
}
