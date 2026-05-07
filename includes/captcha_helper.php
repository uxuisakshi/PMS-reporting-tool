<?php
/**
 * CAPTCHA helper — server-side generation and validation
 * OWASP: validate on server, never trust client
 */

/**
 * Generate a new CAPTCHA token, store in session, return [token, signed_url_params].
 */
function captcha_generate(): array {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    for ($i = 0; $i < 5; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_answer'] = strtolower($token);

    // Generate HMAC secret per session
    if (empty($_SESSION['captcha_hmac_secret'])) {
        $_SESSION['captcha_hmac_secret'] = bin2hex(random_bytes(16));
    }
    $sig = hash_hmac('sha256', strtolower($token), $_SESSION['captcha_hmac_secret']);

    return [
        'token'  => $token,
        'signed' => $sig,
    ];
}

/**
 * Validate submitted CAPTCHA answer against session token.
 * Case-insensitive. Clears token after validation (one-time use).
 */
function captcha_validate(string $submitted): bool {
    $expected = $_SESSION['captcha_answer'] ?? '';
    if ($expected === '') {
        return false;
    }
    $result = hash_equals($expected, strtolower(trim($submitted)));
    // Always clear after attempt — prevents replay attacks (OWASP)
    unset($_SESSION['captcha_answer']);
    return $result;
}
