<?php
require_once __DIR__ . '/includes/auth.php';

// Check if user is logged in
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