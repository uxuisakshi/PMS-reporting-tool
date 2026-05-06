<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection
enforceApiCsrf();

$pdo = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

function ensureHoursReminderSettingsSchema(PDO $pdo): void {
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

function getHoursReminderSettings(PDO $pdo): array {
    ensureHoursReminderSettingsSchema($pdo);
    $stmt = $pdo->query("SELECT * FROM hours_reminder_settings LIMIT 1");
    $settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];

    return [
        'id' => $settings['id'] ?? 1,
        'reminder_time' => $settings['reminder_time'] ?? '18:30:00',
        'minimum_hours' => isset($settings['minimum_hours']) ? (float) $settings['minimum_hours'] : 8.0,
        'login_cutoff_time' => $settings['login_cutoff_time'] ?? '10:30:00',
        'status_cutoff_time' => $settings['status_cutoff_time'] ?? '11:00:00',
        'exclude_weekends' => array_key_exists('exclude_weekends', $settings) ? (int) $settings['exclude_weekends'] : 1,
        'exclude_leave_days' => array_key_exists('exclude_leave_days', $settings) ? (int) $settings['exclude_leave_days'] : 1,
        'enabled' => array_key_exists('enabled', $settings) ? (int) $settings['enabled'] : 1,
        'notification_message' => $settings['notification_message'] ?? '',
    ];
}

function isExcludedComplianceDay(string $date, ?string $statusKey, array $settings): bool {
    $weekday = (int) date('N', strtotime($date));
    if (!empty($settings['exclude_weekends']) && $weekday >= 6) {
        return true;
    }

    if (!empty($settings['exclude_leave_days']) && in_array((string) $statusKey, ['on_leave', 'sick_leave'], true)) {
        return true;
    }

    return false;
}

try {
    ensureHoursReminderSettingsSchema($pdo);
    switch ($action) {
        case 'check_reminder_time':
            if (in_array($user_role, ['admin', 'client'], true)) {
                echo json_encode(['success' => true, 'show_reminder' => false, 'message' => '', 'current_hours' => 0, 'minimum_hours' => 0]);
                break;
            }

            // Check if it's time to show reminder
            $settings = getHoursReminderSettings($pdo);
            
            if (empty($settings['enabled'])) {
                echo json_encode(['success' => true, 'show_reminder' => false]);
                break;
            }
            
            $currentTime = date('H:i:s');
            $reminderTime = $settings['reminder_time'];
            $minimumHours = $settings['minimum_hours'];
            
            // Check if current time is within 5 minutes of reminder time
            $current = strtotime($currentTime);
            $reminder = strtotime($reminderTime);
            $diff = abs($current - $reminder);
            
            $showReminder = false;
            $message = '';
            
            if ($diff <= 300) { // Within 5 minutes
                // Check today's hours for current user
                $today = date('Y-m-d');
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(hours_spent), 0) as total_hours
                    FROM project_time_logs
                    WHERE user_id = ? AND DATE(log_date) = ?
                ");
                $stmt->execute([$user_id, $today]);
                $result = $stmt->fetch();
                $totalHours = $result['total_hours'];

                $statusStmt = $pdo->prepare("SELECT status FROM user_daily_status WHERE user_id = ? AND status_date = ? LIMIT 1");
                $statusStmt->execute([$user_id, $today]);
                $statusKey = $statusStmt->fetchColumn() ?: null;

                if (isExcludedComplianceDay($today, $statusKey, $settings)) {
                    echo json_encode([
                        'success' => true,
                        'show_reminder' => false,
                        'message' => '',
                        'current_hours' => $totalHours ?? 0,
                        'minimum_hours' => $minimumHours,
                        'excluded_day' => true
                    ]);
                    break;
                }
                
                if ($totalHours < $minimumHours) {
                    // Check if reminder already sent today
                    $stmt = $pdo->prepare("
                        SELECT id FROM daily_hours_compliance
                        WHERE user_id = ? AND date = ? AND reminder_sent = TRUE
                    ");
                    $stmt->execute([$user_id, $today]);
                    
                    if (!$stmt->fetch()) {
                        $showReminder = true;
                        $message = $settings['notification_message'];
                        $hoursNeeded = $minimumHours - $totalHours;
                        $message .= " You have logged {$totalHours} hours. {$hoursNeeded} more hours needed.";
                        
                        // Mark reminder as sent
                        $stmt = $pdo->prepare("
                            INSERT INTO daily_hours_compliance 
                            (user_id, date, total_hours, is_compliant, reminder_sent, reminder_sent_at)
                            VALUES (?, ?, ?, FALSE, TRUE, NOW())
                            ON DUPLICATE KEY UPDATE 
                            reminder_sent = TRUE, 
                            reminder_sent_at = NOW(),
                            total_hours = ?
                        ");
                        $stmt->execute([$user_id, $today, $totalHours, $totalHours]);

                        // Mirror reminder as in-app notification (email mirror handled in helper).
                        $reminderLink = '/modules/my_daily_status.php?date=' . $today;
                        createNotification($pdo, (int)$user_id, 'system', $message, $reminderLink);
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'show_reminder' => $showReminder,
                'message' => $message,
                'current_hours' => $totalHours ?? 0,
                'minimum_hours' => $minimumHours
            ]);
            break;

        case 'get_compliance_report':
            if (!in_array($user_role, ['admin'])) {
                throw new Exception('Only admins can view compliance reports');
            }
            
            $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
            
            // Get all active users who are expected to log daily production hours.
            $stmt = $pdo->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.full_name,
                    u.email,
                    u.role,
                    COALESCE(SUM(ptl.hours_spent), 0) as total_hours,
                    dhc.is_compliant,
                    dhc.reminder_sent,
                    dhc.reminder_sent_at
                FROM users u
                LEFT JOIN project_time_logs ptl ON u.id = ptl.user_id AND DATE(ptl.log_date) = ?
                LEFT JOIN daily_hours_compliance dhc ON u.id = dhc.user_id AND dhc.date = ?
                WHERE u.is_active = TRUE AND u.role NOT IN ('admin', 'client')
                GROUP BY u.id, u.username, u.full_name, u.email, u.role, dhc.is_compliant, dhc.reminder_sent, dhc.reminder_sent_at
                ORDER BY total_hours ASC, u.full_name
            ");
            $stmt->execute([$date, $date]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get settings
            $settings = getHoursReminderSettings($pdo);
            $minimumHours = $settings['minimum_hours'] ?? 8;
            
            // Categorize users
            $nonCompliant = [];
            $compliant = [];
            $excluded = [];
            
            foreach ($users as $user) {
                $statusStmt = $pdo->prepare("SELECT status FROM user_daily_status WHERE user_id = ? AND status_date = ? LIMIT 1");
                $statusStmt->execute([$user['id'], $date]);
                $statusKey = $statusStmt->fetchColumn() ?: null;
                if (isExcludedComplianceDay($date, $statusKey, $settings)) {
                    $user['status_key'] = $statusKey;
                    $excluded[] = $user;
                    continue;
                }

                $user['is_compliant'] = $user['total_hours'] >= $minimumHours;
                if ($user['is_compliant']) {
                    $compliant[] = $user;
                } else {
                    $nonCompliant[] = $user;
                }
            }
            
            echo json_encode([
                'success' => true,
                'date' => $date,
                'minimum_hours' => $minimumHours,
                'excluded' => $excluded,
                'non_compliant' => $nonCompliant,
                'compliant' => $compliant,
                'summary' => [
                    'total_users' => count($compliant) + count($nonCompliant),
                    'excluded_count' => count($excluded),
                    'compliant_count' => count($compliant),
                    'non_compliant_count' => count($nonCompliant),
                    'compliance_rate' => (count($compliant) + count($nonCompliant)) > 0 ? round((count($compliant) / (count($compliant) + count($nonCompliant))) * 100, 2) : 0
                ]
            ]);
            break;

        case 'update_settings':
            if (!in_array($user_role, ['admin'])) {
                throw new Exception('Only admins can update settings');
            }
            
            $stmt = $pdo->prepare("
                UPDATE hours_reminder_settings 
                SET reminder_time = ?, 
                    minimum_hours = ?, 
                    login_cutoff_time = ?,
                    status_cutoff_time = ?,
                    exclude_weekends = ?,
                    exclude_leave_days = ?,
                    enabled = ?,
                    notification_message = ?
                WHERE id = 1
            ");
            $stmt->execute([
                $_POST['reminder_time'],
                $_POST['minimum_hours'],
                $_POST['login_cutoff_time'],
                $_POST['status_cutoff_time'],
                !empty($_POST['exclude_weekends']) ? 1 : 0,
                !empty($_POST['exclude_leave_days']) ? 1 : 0,
                $_POST['enabled'] ? 1 : 0,
                $_POST['notification_message']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
            break;

        case 'get_settings':
            if (!in_array($user_role, ['admin'])) {
                throw new Exception('Only admins can view settings');
            }
            
            $settings = getHoursReminderSettings($pdo);
            echo json_encode(['success' => true, 'settings' => $settings]);
            break;

        case 'dismiss_reminder':
            // User dismissed the reminder
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                UPDATE daily_hours_compliance 
                SET reminder_sent = TRUE, reminder_sent_at = NOW()
                WHERE user_id = ? AND date = ?
            ");
            $stmt->execute([$user_id, $today]);
            
            echo json_encode(['success' => true, 'message' => 'Reminder dismissed']);
            break;

        case 'get_my_hours_today':
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(hours_spent), 0) as total_hours
                FROM project_time_logs
                WHERE user_id = ? AND DATE(log_date) = ?
            ");
            $stmt->execute([$user_id, $today]);
            $result = $stmt->fetch();
            
            $settings = getHoursReminderSettings($pdo);
            
            echo json_encode([
                'success' => true,
                'total_hours' => $result['total_hours'],
                'minimum_hours' => $settings['minimum_hours'] ?? 8,
                'is_compliant' => $result['total_hours'] >= ($settings['minimum_hours'] ?? 8)
            ]);
            break;

        case 'get_user_time_logs':
            if (!in_array($user_role, ['admin'])) {
                throw new Exception('Only admins can view user time logs');
            }
            
            $target_user_id = $_GET['user_id'] ?? null;
            $date = $_GET['date'] ?? date('Y-m-d');
            
            if (!$target_user_id) {
                throw new Exception('User ID is required');
            }
            
            try {
                // Get detailed time logs for the user on the specified date
                $stmt = $pdo->prepare("
                    SELECT 
                        ptl.id,
                        ptl.hours_spent,
                        ptl.task_type,
                        ptl.description,
                        ptl.log_date,
                        p.title as project_name,
                        p.id as project_id,
                        pp.page_name,
                        pp.page_number,
                        te.name as env_name,
                        COALESCE(pph.phase_name, pm.phase_name) as phase_name,
                        gtc.name as task_category,
                        ptl.created_at
                    FROM project_time_logs ptl
                    LEFT JOIN projects p ON ptl.project_id = p.id
                    LEFT JOIN project_pages pp ON ptl.page_id = pp.id
                    LEFT JOIN testing_environments te ON ptl.environment_id = te.id
                    LEFT JOIN project_phases pph ON ptl.phase_id = pph.id
                    LEFT JOIN phase_master pm ON pph.phase_master_id = pm.id
                    LEFT JOIN generic_task_categories gtc ON ptl.generic_category_id = gtc.id
                    WHERE ptl.user_id = ? AND DATE(ptl.log_date) = ?
                    ORDER BY ptl.created_at DESC
                ");
                $stmt->execute([$target_user_id, $date]);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'logs' => $logs,
                    'total_hours' => array_sum(array_column($logs, 'hours_spent'))
                ]);
            } catch (PDOException $e) {
                error_log('hours_reminder get_logs DB error: ' . $e->getMessage());
                throw new Exception('Database error occurred');
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log('hours_reminder error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
