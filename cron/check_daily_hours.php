<?php
/**
 * Daily Hours Compliance Checker
 * 
 * This script should be run via cron job every 5 minutes or at specific times
 * Example cron: * / 5 * * * * php /path/to/cron/check_daily_hours.php
 * 
 * Or run at specific time: 30 18 * * * php /path/to/cron/check_daily_hours.php
 */

require_once __DIR__ . '/../config/database.php';

$logFile = __DIR__ . '/../logs/hours_compliance.log';

function ensureHoursReminderSettingsSchema(PDO $pdo) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $columns = [
        "ADD COLUMN login_cutoff_time TIME DEFAULT '10:30:00' AFTER minimum_hours",
        "ADD COLUMN status_cutoff_time TIME DEFAULT '11:00:00' AFTER login_cutoff_time",
        "ADD COLUMN exclude_weekends TINYINT(1) DEFAULT 1 AFTER status_cutoff_time",
        "ADD COLUMN exclude_leave_days TINYINT(1) DEFAULT 1 AFTER exclude_weekends",
    ];

    foreach ($columns as $definition) {
        try {
            $pdo->exec("ALTER TABLE hours_reminder_settings {$definition}");
        } catch (Exception $e) {
        }
    }

    $ensured = true;
}

function isExcludedComplianceDay(PDO $pdo, $userId, $date, array $settings) {
    $weekday = (int) date('N', strtotime($date));
    if (!empty($settings['exclude_weekends']) && $weekday >= 6) {
        return true;
    }

    if (!empty($settings['exclude_leave_days'])) {
        $stmt = $pdo->prepare("SELECT status FROM user_daily_status WHERE user_id = ? AND status_date = ? LIMIT 1");
        $stmt->execute([$userId, $date]);
        $statusKey = $stmt->fetchColumn() ?: null;
        if (in_array((string) $statusKey, ['on_leave', 'sick_leave'], true)) {
            return true;
        }
    }

    return false;
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

try {
    $pdo = Database::getInstance();
    ensureHoursReminderSettingsSchema($pdo);
    
    logMessage("Starting daily hours compliance check...");
    
    // Get settings
    $stmt = $pdo->query("SELECT * FROM hours_reminder_settings WHERE enabled = TRUE LIMIT 1");
    $settings = $stmt->fetch();
    
    if (!$settings) {
        logMessage("Hours reminder system is disabled. Exiting.");
        exit(0);
    }
    
    $currentTime = date('H:i:s');
    $reminderTime = $settings['reminder_time'];
    $minimumHours = $settings['minimum_hours'];
    
    logMessage("Settings: Reminder Time = $reminderTime, Minimum Hours = $minimumHours");
    
    // Check if current time matches reminder time (within 5 minutes)
    $current = strtotime($currentTime);
    $reminder = strtotime($reminderTime);
    $diff = abs($current - $reminder);
    
    if ($diff > 300) { // More than 5 minutes difference
        logMessage("Not reminder time yet. Current: $currentTime, Reminder: $reminderTime");
        exit(0);
    }
    
    logMessage("Reminder time reached! Checking user compliance...");
    
    $today = date('Y-m-d');
    
    // Get all active users who are expected to log daily production hours.
    $stmt = $pdo->query("
        SELECT id, username, full_name, email, role
        FROM users
        WHERE is_active = TRUE AND role NOT IN ('admin', 'client')
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Found " . count($users) . " active users to check");
    
    $compliantCount = 0;
    $nonCompliantCount = 0;
    $remindersCreated = 0;
    
    foreach ($users as $user) {
        if (isExcludedComplianceDay($pdo, $user['id'], $today, $settings)) {
            logMessage("↷ {$user['username']}: Excluded from compliance check (weekend/leave)");
            continue;
        }

        // Get today's hours for this user
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(hours_spent), 0) as total_hours
            FROM project_time_logs
            WHERE user_id = ? AND DATE(log_date) = ?
        ");
        $stmt->execute([$user['id'], $today]);
        $result = $stmt->fetch();
        $totalHours = $result['total_hours'];
        
        $isCompliant = $totalHours >= $minimumHours;
        
        // Check if reminder already sent today
        $stmt = $pdo->prepare("
            SELECT id, reminder_sent FROM daily_hours_compliance
            WHERE user_id = ? AND date = ?
        ");
        $stmt->execute([$user['id'], $today]);
        $existing = $stmt->fetch();
        
        if ($isCompliant) {
            $compliantCount++;
            
            // Update compliance record
            if ($existing) {
                $stmt = $pdo->prepare("
                    UPDATE daily_hours_compliance
                    SET total_hours = ?, is_compliant = TRUE, checked_at = NOW()
                    WHERE user_id = ? AND date = ?
                ");
                $stmt->execute([$totalHours, $user['id'], $today]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO daily_hours_compliance 
                    (user_id, date, total_hours, is_compliant, reminder_sent)
                    VALUES (?, ?, ?, TRUE, FALSE)
                ");
                $stmt->execute([$user['id'], $today, $totalHours]);
            }
            
            logMessage("✓ {$user['username']}: Compliant ({$totalHours} hrs)");
        } else {
            $nonCompliantCount++;
            
            // Create/update compliance record for non-compliant user
            if ($existing && $existing['reminder_sent']) {
                logMessage("⚠ {$user['username']}: Non-compliant ({$totalHours} hrs) - Reminder already sent");
            } else {
                // Mark that reminder should be shown
                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE daily_hours_compliance
                        SET total_hours = ?, is_compliant = FALSE, checked_at = NOW()
                        WHERE user_id = ? AND date = ?
                    ");
                    $stmt->execute([$totalHours, $user['id'], $today]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO daily_hours_compliance 
                        (user_id, date, total_hours, is_compliant, reminder_sent)
                        VALUES (?, ?, ?, FALSE, FALSE)
                    ");
                    $stmt->execute([$user['id'], $today, $totalHours]);
                }
                
                $remindersCreated++;
                logMessage("✗ {$user['username']}: Non-compliant ({$totalHours} hrs) - Reminder queued");
            }
        }
    }
    
    logMessage("=== Summary ===");
    logMessage("Total Users: " . count($users));
    logMessage("Compliant: $compliantCount");
    logMessage("Non-Compliant: $nonCompliantCount");
    logMessage("New Reminders Queued: $remindersCreated");
    logMessage("Compliance Rate: " . (count($users) > 0 ? round(($compliantCount / count($users)) * 100, 2) : 0) . "%");
    logMessage("Check completed successfully!");
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    exit(1);
}
