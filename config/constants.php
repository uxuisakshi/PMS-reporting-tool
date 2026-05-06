<?php
// Set timezone to IST (Indian Standard Time) for entire application
require_once __DIR__ . '/timezone.php';

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_PROJECT_LEAD', 'project_lead');
define('ROLE_QA', 'qa');
define('ROLE_AT_TESTER', 'at_tester');
define('ROLE_FT_TESTER', 'ft_tester');

// Project Statuses
define('PROJECT_NOT_STARTED', 'not_started');
define('PROJECT_IN_PROGRESS', 'in_progress');
define('PROJECT_ON_HOLD', 'on_hold');
define('PROJECT_COMPLETED', 'completed');
define('PROJECT_CANCELLED', 'cancelled');

// Page Statuses
define('PAGE_NOT_STARTED', 'not_started');
define('PAGE_IN_PROGRESS', 'in_progress');
define('PAGE_ON_HOLD', 'on_hold');
define('PAGE_QA_IN_PROGRESS', 'qa_in_progress');
define('PAGE_IN_FIXING', 'in_fixing');
define('PAGE_NEEDS_REVIEW', 'needs_review');

// Project Types
define('PROJECT_TYPE_WEB', 'web');
define('PROJECT_TYPE_APP', 'app');
define('PROJECT_TYPE_PDF', 'pdf');

// Priorities
define('PRIORITY_CRITICAL', 'critical');
define('PRIORITY_HIGH', 'high');
define('PRIORITY_MEDIUM', 'medium');
define('PRIORITY_LOW', 'low');

// Session timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Upload paths - use a function to avoid issues with $_SERVER at define time
if (!defined('UPLOAD_PATH')) {
    $uploadBase = isset($_SERVER['DOCUMENT_ROOT']) 
        ? $_SERVER['DOCUMENT_ROOT'] 
        : dirname(__DIR__);
    define('UPLOAD_PATH', rtrim($uploadBase, '/\\') . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
}
define('MAX_UPLOAD_SIZE', 5242880); // 5MB