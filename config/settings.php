<?php
// Derive app URL dynamically when APP_URL is not provided.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$rawHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
$hostOnly = strtolower((string) (parse_url('http://' . ($rawHost !== '' ? $rawHost : 'localhost'), PHP_URL_HOST) ?? 'localhost'));
if ($hostOnly === '' || !preg_match('/^[a-z0-9.-]+$/', $hostOnly)) {
    $hostOnly = 'localhost';
}
$port = (int) (parse_url('http://' . ($rawHost !== '' ? $rawHost : 'localhost'), PHP_URL_PORT) ?? 0);
$host = $hostOnly;
if ($port > 0 && $port <= 65535) {
    $host .= ':' . $port;
}
// Derive app URL dynamically
$appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));

$webRoot = '';
if (!empty($docRoot) && strpos($appRoot, $docRoot) === 0) {
    $webRoot = substr($appRoot, strlen($docRoot));
}
$webRoot = str_replace('\\', '/', $webRoot);
$webRoot = '/' . trim($webRoot, '/');
if ($webRoot === '/') $webRoot = '';

$derivedAppUrl = rtrim($scheme . '://' . $host . $webRoot, '/');

// Application Settings
return [
    // Application
    'app_name' => 'Project Management System',
    'app_version' => '1.0.0',
    'app_url' => getenv('APP_URL') ?: (($_SERVER['HTTP_HOST'] ?? '') ? $derivedAppUrl : 'https://pms.athenaeumtransformation.com'),
    'company_name' => getenv('COMPANY_NAME') ?: 'Sakshi Infotech Solutions LLP',
    'company_logo' => getenv('COMPANY_LOGO') ?: 'https://pms.athenaeumtransformation.com/storage/SIS-Logo-3.png', // URL to logo

    // Security
    'session_timeout' => 1800, // 30 minutes
    'password_min_length' => 8,
    'max_login_attempts' => 5,
    'lockout_time' => 900, // 15 minutes

    // Upload Settings
    'upload_max_size' => 5242880, // 5MB
    'allowed_file_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
        'archive' => ['zip', 'rar']
    ],

    // Email Settings
    'mail_from' => getenv('MAIL_FROM') ?: 'project-management-system@athenaeumtransformation.com',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'SIS PMS',
    'smtp_host' => getenv('SMTP_HOST') ?: 'mail.athenaeumtransformation.com',
    'smtp_port' => (int) (getenv('SMTP_PORT') ?: 465),
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'ssl',
    'smtp_auth' => (function () {
        $v = getenv('SMTP_AUTH');
        if ($v === false || $v === '')
            return true;
        return !in_array(strtolower(trim((string) $v)), ['0', 'false', 'no', 'off'], true);
    })(),
    'smtp_username' => getenv('SMTP_USERNAME') ?: 'project-management-system@athenaeumtransformation.com',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: '^Sakshi^2026^',

    // Features
    'allow_registration' => false,
    'allow_file_uploads' => true,
    'enable_chat' => true,
    'enable_reports' => true,
    'enable_api' => true,

    // Display
    'items_per_page' => 25,
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'timezone' => 'UTC',

    // Notifications
    'notify_new_project' => true,
    'notify_assignment' => true,
    'notify_mention' => true,
    'notify_status_change' => true,
    // Trial mode: keep email notifications off by default.
    'email_notifications_enabled' => (function () {
        $v = getenv('EMAIL_NOTIFICATIONS_ENABLED');
        if ($v === false || $v === '')
            return true;
        return !in_array(strtolower(trim((string) $v)), ['0', 'false', 'no', 'off'], true);
    })(),
];
