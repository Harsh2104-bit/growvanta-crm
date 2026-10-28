<?php
declare(strict_types=1);

/**
 * Minimal request validator — no Composer dependency (Zod's PHP equivalents
 * all require Composer, which many shared hosts can't run). Not as expressive
 * as Zod, but every route only needs a handful of checks, spelled out inline.
 */
class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function required(string $key, ?string $label = null): self
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $this->errors[] = ['field' => $key, 'message' => ($label ?? $key) . ' is required'];
        }
        return $this;
    }

    public function email(string $key): self
    {
        $v = $this->data[$key] ?? null;
        if ($v !== null && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = ['field' => $key, 'message' => 'Enter a valid email address'];
        }
        return $this;
    }

    public function in(string $key, array $allowed): self
    {
        $v = $this->data[$key] ?? null;
        if ($v !== null && $v !== '' && !in_array($v, $allowed, true)) {
            $this->errors[] = ['field' => $key, 'message' => 'Must be one of: ' . implode(', ', $allowed)];
        }
        return $this;
    }

    public function maxLength(string $key, int $max): self
    {
        $v = $this->data[$key] ?? null;
        if (is_string($v) && strlen($v) > $max) {
            $this->errors[] = ['field' => $key, 'message' => "Must be $max characters or fewer"];
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validate(): void
    {
        if ($this->fails()) {
            throw validation_error($this->errors());
        }
    }
}

function validate(array $data): Validator
{
    return new Validator($data);
}

/** Trims a string field, or returns null for empty/absent — the common "optional text" pattern. */
function str_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
}

function num_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float) $value : null;
}

function bool_val($value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
    return $value === true || $value === 'true' || $value === 1 || $value === '1';
}
