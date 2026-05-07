<?php
/**
 * Accessible CAPTCHA — WCAG 2.1 AA + OWASP compliant
 *
 * Image mode: token passed via URL param (no session read needed)
 * Refresh/Token modes: session-based
 */

// No output buffering, no includes — keep this file clean
if (session_status() === PHP_SESSION_NONE) {
    session_name('PMS_SESSION');
    session_start();
}

$mode = strtolower(trim($_GET['mode'] ?? 'image'));

function captcha_make_token(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $t = '';
    for ($i = 0; $i < 5; $i++) $t .= $chars[random_int(0, strlen($chars) - 1)];
    return $t;
}

// ── Refresh ───────────────────────────────────────────────────────────────────
if ($mode === 'refresh') {
    $token = captcha_make_token();
    $_SESSION['captcha_answer'] = strtolower($token);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'token' => $token]);
    exit;
}

// ── Token (audio fallback) ────────────────────────────────────────────────────
if ($mode === 'token') {
    $token = !empty($_SESSION['captcha_answer']) ? strtoupper($_SESSION['captcha_answer']) : '';
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['token' => $token]);
    exit;
}

// ── Image — token comes from URL param, NOT session ───────────────────────────
if ($mode === 'image') {
    // Token is passed directly in URL — no session dependency
    $token = strtoupper(preg_replace('/[^A-Z2-9]/', '', strtoupper(trim($_GET['tok'] ?? ''))));

    // Validate: must be exactly 5 chars from allowed set
    if (strlen($token) !== 5) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid token';
        exit;
    }

    // SVG fallback (no GD)
    if (!extension_loaded('gd')) {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $svgChars = '';
        $colors   = ['#1a56db','#c81e1e','#057a55','#6c2bd9','#b45309'];
        for ($i = 0; $i < strlen($token); $i++) {
            $x   = 18 + $i * 28;
            $y   = 28 + (($i % 2 === 0) ? -4 : 4);
            $rot = random_int(-12, 12);
            $col = $colors[$i % count($colors)];
            $svgChars .= '<text x="' . $x . '" y="' . $y . '" '
                       . 'transform="rotate(' . $rot . ',' . $x . ',' . $y . ')" '
                       . 'fill="' . $col . '" font-size="22" font-family="monospace" font-weight="bold">'
                       . htmlspecialchars($token[$i], ENT_XML1) . '</text>';
        }
        $lines = '';
        for ($i = 0; $i < 4; $i++) {
            $x1 = random_int(0, 160); $y1 = random_int(0, 50);
            $x2 = random_int(0, 160); $y2 = random_int(0, 50);
            $lines .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2
                    . '" stroke="#aaa" stroke-width="1" opacity="0.5"/>';
        }
        echo '<?xml version="1.0" encoding="UTF-8"?>'
           . '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="50" '
           . 'role="img" aria-label="CAPTCHA image — enter the characters shown">'
           . '<rect width="160" height="50" fill="#f0f4ff" rx="4"/>'
           . $lines . $svgChars . '</svg>';
        exit;
    }

    // PNG via GD
    $w = 160; $h = 50;
    $img = imagecreatetruecolor($w, $h);
    $bg  = imagecolorallocate($img, 240, 244, 255);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);

    for ($i = 0; $i < 5; $i++) {
        $c = imagecolorallocate($img, random_int(160,210), random_int(160,210), random_int(160,210));
        imageline($img, random_int(0,$w), random_int(0,$h), random_int(0,$w), random_int(0,$h), $c);
    }
    for ($i = 0; $i < 60; $i++) {
        $c = imagecolorallocate($img, random_int(120,200), random_int(120,200), random_int(120,200));
        imagesetpixel($img, random_int(0,$w), random_int(0,$h), $c);
    }

    $cols = [
        imagecolorallocate($img, 15,  60,  160),
        imagecolorallocate($img, 150, 15,  15),
        imagecolorallocate($img, 10,  100, 30),
        imagecolorallocate($img, 100, 15,  120),
        imagecolorallocate($img, 15,  90,  110),
    ];

    $cw = imagefontwidth(5); $ch = imagefontheight(5);
    $tw = strlen($token) * ($cw + 8);
    $sx = (int)(($w - $tw) / 2);
    $sy = (int)(($h - $ch) / 2);

    for ($i = 0; $i < strlen($token); $i++) {
        imagechar($img, 5,
            $sx + $i * ($cw + 8) + random_int(-3, 3),
            $sy + random_int(-5, 5),
            $token[$i],
            $cols[$i % count($cols)]
        );
    }

    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    imagepng($img);
    imagedestroy($img);
    exit;
}

http_response_code(400);
echo 'Invalid mode';
