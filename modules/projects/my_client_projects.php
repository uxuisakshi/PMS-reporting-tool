<?php
/**
 * Legacy Client Projects Redirect
 * 
 * This page is redundant. The fully featured modern interface is located at
 * /modules/client/projects.php.
 * Redirecting clients automatically to the proper dashboard.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
$baseDir = getBaseDir();

header('Location: ' . $baseDir . '/modules/client/projects.php');
exit;
?>