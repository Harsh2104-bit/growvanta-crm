<?php
declare(strict_types=1);

/**
 * Passwords, tokens, HMAC signatures, and symmetric encryption for secrets
 * that must be readable again (Meta page access tokens) rather than hashed.
 */

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verify_password(string $plain, ?string $hash): bool
{
    if (!$hash) {
        return false;
    }
    return password_verify($plain, $hash);
}

/** Same policy as the Node version: shared across registration, reset, admin-set. */
function check_password_strength(string $password): array
{
    $problems = [];
    if (strlen($password) < 8) {
        $problems[] = 'at least 8 characters';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $problems[] = 'a lowercase letter';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $problems[] = 'an uppercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $problems[] = 'a number';
    }
    if ($problems) {
        return ['ok' => false, 'message' => 'Password must contain ' . implode(', ', $problems) . '.'];
    }
    return ['ok' => true];
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function sha256(string $value): string
{
    return hash('sha256', $value);
}

/** Constant-time comparison. */
function safe_equal(string $a, string $b): bool
{
    return hash_equals($a, $b);
}

function sign_webhook_payload(string $secret, string $body): string
{
    return hash_hmac('sha256', $body, $secret);
}

/** API keys: `gv_<random>`; only the SHA-256 hash is stored. */
function generate_api_key(): array
{
    $raw = 'gv_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    return ['raw' => $raw, 'prefix' => substr($raw, 0, 11), 'hash' => sha256($raw)];
}

// ---------------------------------------------------------------------------
// Symmetric encryption for stored integration secrets (Meta page access
// tokens). AES-256-GCM keyed off APP_KEY — never store these in plain text,
// unlike a hash they must be reversible to call the Graph API later.
// ---------------------------------------------------------------------------
function gv_encrypt(string $plaintext): string
{
    $key = hash('sha256', (string) gv_config('auth.app_key'), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed');
    }
    return base64_encode($iv . $tag . $cipher);
}

function gv_decrypt(?string $encoded): ?string
{
    if (!$encoded) {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $key = hash('sha256', (string) gv_config('auth.app_key'), true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

// ---------------------------------------------------------------------------
// TOTP (RFC 6238) — no external dependency, same algorithm as the Node lib.
// ---------------------------------------------------------------------------
const GV_TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_generate_secret(int $length = 20): string
{
    $bytes = random_bytes($length);
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $secret = '';
    for ($i = 0; $i + 5 <= strlen($bits); $i += 5) {
        $secret .= GV_TOTP_ALPHABET[bindec(substr($bits, $i, 5))];
    }
    return $secret;
}

function totp_base32_decode(string $input): string
{
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input));
    $bits = '';
    foreach (str_split($clean) as $char) {
        $index = strpos(GV_TOTP_ALPHABET, $char);
        if ($index === false) {
            continue;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function totp_hotp(string $secret, int $counter): string
{
    $key = totp_base32_decode($secret);
    $bin = pack('J', $counter); // 64-bit big-endian
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $code = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verify(?string $secret, ?string $token, int $window = 1): bool
{
    if (!$secret || !$token || !preg_match('/^\d{6}$/', trim($token))) {
        return false;
    }
    $counter = (int) floor(time() / 30);
    $candidate = trim($token);
    for ($drift = -$window; $drift <= $window; $drift++) {
        if (hash_equals(totp_hotp($secret, $counter + $drift), $candidate)) {
            return true;
        }
    }
    return false;
}

function totp_otpauth_uri(string $secret, string $account, string $issuer = 'Growvanta CRM'): string
{
    $label = rawurlencode("$issuer:$account");
    $params = http_build_query([
        'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30',
    ]);
    return "otpauth://totp/$label?$params";
}
