<?php
/**
 * Global Timezone Configuration
 * Sets timezone to IST (Indian Standard Time) for entire application
 * Include this file at the very beginning of every entry point
 */

// Force set timezone to Asia/Kolkata (IST - UTC+05:30)
date_default_timezone_set('Asia/Kolkata');

// Verify it's set correctly
if (date_default_timezone_get() !== 'Asia/Kolkata') {
    // Fallback: try setting it again
    @ini_set('date.timezone', 'Asia/Kolkata');
    date_default_timezone_set('Asia/Kolkata');
}

// Define timezone constant for reference
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Kolkata');
}

if (!defined('APP_TIMEZONE_OFFSET')) {
    define('APP_TIMEZONE_OFFSET', '+05:30');
}
