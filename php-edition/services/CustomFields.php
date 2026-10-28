<?php
declare(strict_types=1);

/** Admin-defined custom fields — ported from src/services/customFields.js. */

const GV_CUSTOM_FIELD_TYPES = ['TEXT', 'TEXTAREA', 'NUMBER', 'DECIMAL', 'DATE', 'BOOLEAN', 'SELECT', 'MULTISELECT', 'EMAIL', 'PHONE', 'URL'];

function custom_field_definitions(string $orgId, string $entity): array
{
    return db_all(
        'SELECT * FROM custom_fields WHERE organization_id = ? AND entity = ? AND is_active = 1 ORDER BY sort_order ASC, created_at ASC',
        [$orgId, $entity]
    );
}

function custom_field_coerce(array $def, $raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }
    switch ($def['type']) {
        case 'NUMBER':
            if (!is_numeric($raw) || (int) $raw != $raw) {
                throw new InvalidArgumentException("{$def['label']} must be a whole number");
            }
            return (int) $raw;
        case 'DECIMAL':
            if (!is_numeric($raw)) {
                throw new InvalidArgumentException("{$def['label']} must be a number");
            }
            return (float) $raw;
        case 'BOOLEAN':
            return in_array($raw, [true, 'true', 1, '1', 'yes'], true);
        case 'DATE':
            $ts = strtotime((string) $raw);
            if ($ts === false) {
                throw new InvalidArgumentException("{$def['label']} must be a valid date");
            }
            return date('Y-m-d', $ts);
        case 'SELECT':
            $options = safe_json($def['options'], []);
            $value = (string) $raw;
            if ($options && !in_array($value, $options, true)) {
                throw new InvalidArgumentException("{$def['label']} must be one of: " . implode(', ', $options));
            }
            return $value;
        case 'MULTISELECT':
            $options = safe_json($def['options'], []);
            $values = is_array($raw) ? array_map('strval', $raw) : array_filter(array_map('trim', explode(',', (string) $raw)));
            if ($options) {
                $bad = array_diff($values, $options);
                if ($bad) {
                    throw new InvalidArgumentException("{$def['label']}: unsupported option(s) " . implode(', ', $bad));
                }
            }
            return array_values($values);
        case 'EMAIL':
            $value = trim((string) $raw);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("{$def['label']} must be a valid email");
            }
            return strtolower($value);
        case 'URL':
            $value = trim((string) $raw);
            if (!preg_match('#^https?://#i', $value)) {
                throw new InvalidArgumentException("{$def['label']} must start with http:// or https://");
            }
            return $value;
        default:
            return substr((string) $raw, 0, 2000);
    }
}

/** @return string JSON-encoded value ready for the custom_fields column. */
function validate_custom_field_values(string $orgId, string $entity, $input, $existing = '{}'): string
{
    $defs = custom_field_definitions($orgId, $entity);
    $incoming = is_array($input) ? $input : safe_json($input, []);
    $current = is_array($existing) ? $existing : safe_json($existing, []);
    $out = $current;
    $errors = [];

    foreach ($defs as $def) {
        $key = $def['key'];
        $provided = array_key_exists($key, $incoming);
        if (!$provided) {
            if (gv_bool($def['is_required']) && !isset($out[$key])) {
                if ($def['default_value']) {
                    $out[$key] = $def['default_value'];
                } else {
                    $errors[] = ['field' => "customFields.$key", 'message' => "{$def['label']} is required"];
                }
            }
            continue;
        }
        try {
            $value = custom_field_coerce($def, $incoming[$key]);
            if (gv_bool($def['is_required']) && ($value === null || (is_array($value) && !$value))) {
                $errors[] = ['field' => "customFields.$key", 'message' => "{$def['label']} is required"];
            }
            if ($value === null) {
                unset($out[$key]);
            } else {
                $out[$key] = $value;
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = ['field' => "customFields.$key", 'message' => $e->getMessage()];
        }
    }

    $allowed = array_column($defs, 'key');
    foreach (array_keys($out) as $key) {
        if (!in_array($key, $allowed, true)) {
            unset($out[$key]);
        }
    }

    if ($errors) {
        throw validation_error($errors);
    }
    return to_json($out);
}
