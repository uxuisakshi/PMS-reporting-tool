<?php
// Set timezone to IST (Indian Standard Time)
require_once __DIR__ . '/config/timezone.php';

session_start();

// Check if user is logged in
// Check if user is logged in
require_once __DIR__ . '/includes/helpers.php';
$baseDir = getBaseDir();

if (isset($_SESSION['user_id'])) {
    // Redirect to appropriate dashboard based on role
    $role = $_SESSION['role'];
    $moduleDir = getModuleDirectory($role);
    
    redirect("/modules/{$moduleDir}/dashboard.php");
} else {
    // Redirect to login page
    redirect("/modules/auth/login.php");   
} 
?>