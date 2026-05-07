<?php
/**
 * CAPTCHA helper — inline SVG approach.
 * No separate HTTP request = no session race condition.
 * Token stored only in session — never in HTML/URL.
 */

/**
 * Generate a new CAPTCHA token, store in session.
 * Returns the token (for rendering inline SVG).
 */
function captcha_generate(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    for ($i = 0; $i < 5; $i++) {
        $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_answer'] = strtolower($token);
    return $token;
}

/**
 * Render CAPTCHA as inline SVG — no separate HTTP request, no session race.
 * Returns a data URI string safe to use in <img src="...">
 */
function captcha_render_svg(string $token): string {
    $w = 160; $h = 50;
    $colors = ['#1a56db','#c81e1e','#057a55','#6c2bd9','#b45309'];

    // Noise lines
    $lines = '';
    for ($i = 0; $i < 5; $i++) {
        $x1 = random_int(0, $w); $y1 = random_int(0, $h);
        $x2 = random_int(0, $w); $y2 = random_int(0, $h);
        $lines .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2
                . '" stroke="#ccc" stroke-width="1"/>';
    }

    // Noise dots
    $dots = '';
    for ($i = 0; $i < 40; $i++) {
        $cx = random_int(0, $w); $cy = random_int(0, $h);
        $dots .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="1" fill="#bbb"/>';
    }

    // Characters
    $chars_svg = '';
    $charW = 24; // approximate char width
    $totalW = strlen($token) * ($charW + 6);
    $startX = (int)(($w - $totalW) / 2);

    for ($i = 0; $i < strlen($token); $i++) {
        $x   = $startX + $i * ($charW + 6) + random_int(-3, 3);
        $y   = 32 + random_int(-5, 5);
        $rot = random_int(-15, 15);
        $col = $colors[$i % count($colors)];
        $chars_svg .= '<text x="'.$x.'" y="'.$y.'" '
                    . 'transform="rotate('.$rot.','.$x.','.$y.')" '
                    . 'fill="'.$col.'" font-size="22" font-family="monospace" '
                    . 'font-weight="bold" letter-spacing="2">'
                    . htmlspecialchars($token[$i], ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '</text>';
    }

    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'">'
         . '<rect width="'.$w.'" height="'.$h.'" fill="#f0f4ff" rx="4"/>'
         . $lines . $dots . $chars_svg
         . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
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
    unset($_SESSION['captcha_answer']); // one-time use
    return $result;
}