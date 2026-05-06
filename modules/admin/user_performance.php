<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$baseDir = getBaseDir();

// Legacy page kept for backward compatibility.
// Redirect to the current performance report module.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = $baseDir . '/modules/admin/performance.php' . ($query ? ('?' . $query) : '');
header('Location: ' . $target);
exit;
