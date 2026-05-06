<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

// CSRF protection for logout — accept only POST with valid token, or GET with valid token param
// This prevents CSRF logout attacks via <img> or link tags
$auth = new Auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST logout — verify CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!$auth->isLoggedIn() || !verifyCsrfToken($token)) {
        redirect("/modules/auth/login.php");
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET logout — verify token param (for navbar links)
    $token = $_GET['csrf_token'] ?? '';
    if (!$auth->isLoggedIn() || !verifyCsrfToken($token)) {
        redirect("/modules/auth/login.php");
        exit;
    }
}

// Perform logout — sets flash message in cookie, no sensitive state in URL
$auth->logout();
