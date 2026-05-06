<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

function isSafeSqlIdentifier($name) {
    return is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name);
}

function getUserFkReferences(PDO $db) {
    $sql = "
        SELECT
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            rc.DELETE_RULE,
            c.IS_NULLABLE
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
            ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
           AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        JOIN information_schema.COLUMNS c
            ON c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
           AND c.TABLE_NAME = kcu.TABLE_NAME
           AND c.COLUMN_NAME = kcu.COLUMN_NAME
        WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
          AND kcu.REFERENCED_TABLE_NAME = 'users'
          AND kcu.REFERENCED_COLUMN_NAME = 'id'
        ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
    ";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function countUserRefRows(PDO $db, $table, $column, $userId) {
    if (!isSafeSqlIdentifier($table) || !isSafeSqlIdentifier($column)) return 0;
    $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function generateTemporaryPassword($length = 12) {
    $length = max(10, (int)$length);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$%';
    $max = strlen($alphabet) - 1;
    $out = '';
    try {
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
    } catch (Exception $e) {
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, $max)];
        }
    }
    return $out;
}

function buildAccountMailBody($fullName, $username, $email, $tempPassword, $loginUrl, $mode) {
    $safeName = htmlspecialchars((string)$fullName, ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars((string)$username, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars((string)$email, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars((string)$tempPassword, ENT_QUOTES, 'UTF-8');
    $safeLoginUrl = htmlspecialchars((string)$loginUrl, ENT_QUOTES, 'UTF-8');
    $modeText = $mode === 'reset' ? 'password reset' : 'account setup';

    return "
        <div style='font-family:Arial,sans-serif;line-height:1.6;color:#111827'>
            <h2 style='margin:0 0 12px'>PMS " . ucfirst($modeText) . "</h2>
            <p>Hello {$safeName},</p>
            <p>Your PMS {$modeText} details are ready:</p>
            <table cellpadding='8' cellspacing='0' border='1' style='border-collapse:collapse;border-color:#d1d5db'>
                <tr><td><strong>Username</strong></td><td>{$safeUsername}</td></tr>
                <tr><td><strong>Email</strong></td><td>{$safeEmail}</td></tr>
                <tr><td><strong>Temporary Password</strong></td><td>{$safePassword}</td></tr>
            </table>
            <p style='margin-top:14px'>
                Login link: <a href='{$safeLoginUrl}'>{$safeLoginUrl}</a>
            </p>
            <p><strong>Important:</strong> You will be required to change this password after login.</p>
            <p>Regards,<br>PMS Admin</p>
        </div>
    ";
}

function ensureCredentialsMailLogTable(PDO $db) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS credentials_mail_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(80) NOT NULL,
                target_user_id INT NOT NULL,
                requested_by INT NULL,
                mail_mode ENUM('setup','reset') NOT NULL DEFAULT 'setup',
                status ENUM('success','fail') NOT NULL DEFAULT 'fail',
                detail VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_credentials_mail_log_req_user (request_id, target_user_id),
                INDEX idx_credentials_mail_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        // non-fatal; verification fallback won't work without table
    }
}

function logCredentialsMailAttempt(PDO $db, $requestId, $uid, $mailMode, $status, $detail = '') {
    $requestId = trim((string)$requestId);
    if ($requestId === '') return;
    ensureCredentialsMailLogTable($db);
    try {
        $stmt = $db->prepare("
            INSERT INTO credentials_mail_log (request_id, target_user_id, requested_by, mail_mode, status, detail)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $requestId,
            (int)$uid,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            in_array($mailMode, ['setup', 'reset'], true) ? $mailMode : 'setup',
            $status === 'success' ? 'success' : 'fail',
            substr((string)$detail, 0, 255)
        ]);
    } catch (Exception $e) {
        // non-fatal
    }
}

function sendCredentialsMailForUser(PDO $db, $uid, $mailMode, $requestId = '') {
    require_once __DIR__ . '/../../includes/email.php';
    $mailer = class_exists('EmailSender') ? new EmailSender() : null;
    if (!$mailer) {
        logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'fail', 'Email service is not available.');
        return ['success' => false, 'error' => 'Email service is not available.'];
    }

    $uid = (int)$uid;
    if ($uid <= 0) {
        logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'fail', 'Invalid user id.');
        return ['success' => false, 'error' => 'Invalid user id.'];
    }

    $mailMode = in_array($mailMode, ['setup', 'reset'], true) ? $mailMode : 'setup';
    $loginUrl = getConfiguredAppUrl() . '/modules/auth/login.php';

    try {
        $db->beginTransaction();

        $userStmt = $db->prepare("SELECT id, full_name, username, email, is_active FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$uid]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $db->rollBack();
            logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'fail', "User#{$uid} not found.");
            return ['success' => false, 'error' => "User#{$uid} not found."];
        }

        $recipientEmail = trim((string)($user['email'] ?? ''));
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $db->rollBack();
            logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'fail', (string)($user['username'] ?? "User#{$uid}") . ' invalid email.');
            return ['success' => false, 'error' => (string)($user['username'] ?? "User#{$uid}") . ' has invalid email.'];
        }

        $tempPassword = generateTemporaryPassword(12);
        $newHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $upd = $db->prepare("UPDATE users SET password = ?, force_password_reset = 1, temp_password = NULL, account_setup_completed = 0 WHERE id = ?");
        $upd->execute([$newHash, $uid]);

        $subject = ($mailMode === 'reset')
            ? "PMS Password Reset - Login Details"
            : "PMS Account Setup - Login Details";
        $body = buildAccountMailBody(
            $user['full_name'] ?? 'User',
            $user['username'] ?? '',
            $recipientEmail,
            $tempPassword,
            $loginUrl,
            $mailMode
        );

        // Commit first so the new password is always saved before attempting email send.
        $db->commit();

        $sent = $mailer->send($recipientEmail, $subject, $body, true);
        
        if ($sent) {
            logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'success', (string)($user['username'] ?? "User#{$uid}") . ' sent.');
            return ['success' => true, 'username' => (string)($user['username'] ?? "User#{$uid}")];
        }

        logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'fail', (string)($user['username'] ?? "User#{$uid}") . ' mail send failed.');
        return ['success' => false, 'error' => (string)($user['username'] ?? "User#{$uid}") . ' mail send failed. Generate a new reset email if needed.'];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Send setup/reset mail failed for user_id=' . $uid . ': ' . $e->getMessage());
        logCredentialsMailAttempt($db, $requestId, $uid, $mailMode, 'fail', "User#{$uid} exception: " . $e->getMessage());
        return ['success' => false, 'error' => "User#{$uid} failed: " . $e->getMessage()];
    }
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjaxAction = isset($_POST['send_credentials_email_single']);
    $csrfFromRequest = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfFromRequest)) {
        if ($isAjaxAction) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid request token.']);
        } else {
            $_SESSION['error'] = 'Invalid request. Please try again.';
            header('Location: users.php');
        }
        exit;
    }
    if (isset($_POST['send_credentials_email_single'])) {
        @set_time_limit(180);
        @ignore_user_abort(true);
        header('Content-Type: application/json');
        $uid = (int)($_POST['user_id'] ?? 0);
        $mailMode = trim((string)($_POST['mail_mode'] ?? 'setup'));
        $requestId = trim((string)($_POST['request_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\-:.]{6,80}$/', $requestId)) {
            $requestId = '';
        }
        $result = sendCredentialsMailForUser($db, $uid, $mailMode, $requestId);
        echo json_encode($result);
        exit;
    }

    if (isset($_POST['add_user'])) {
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $fullName = sanitizeInput($_POST['full_name']);
        $role = sanitizeInput($_POST['role']);
        $rawPassword = isset($_POST['password']) ? $_POST['password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Server-side password confirmation check
        if ($rawPassword === '' || $rawPassword !== $confirmPassword) {
            $_SESSION['error'] = "Passwords are empty or do not match.";
        } else {
            $password = password_hash($rawPassword, PASSWORD_DEFAULT);

            // Check for existing username/email to provide friendly message
            $chk = $db->prepare("SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
            $chk->execute([$username, $email]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (strtolower($existing['username']) === strtolower($username)) {
                    $_SESSION['error'] = "Username already exists. Please choose a different username.";
                } elseif (strtolower($existing['email']) === strtolower($email)) {
                    $_SESSION['error'] = "Email already in use. Please use a different email.";
                } else {
                    $_SESSION['error'] = "A user with the same username or email already exists.";
                }
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO users (username, email, password, full_name, role, force_password_reset, can_manage_issue_config, can_manage_devices, temp_password, account_setup_completed) VALUES (?, ?, ?, ?, ?, 1, ?, ?, NULL, 0)"
                );
                $canManageConfig = isset($_POST['can_manage_issue_config']) ? 1 : 0;
                $canManageDevices = isset($_POST['can_manage_devices']) ? 1 : 0;

                try {
                    if ($stmt->execute([$username, $email, $password, $fullName, $role, $canManageConfig, $canManageDevices])) {
                        $mailNote = '';
                        if (!empty($_POST['send_setup_email'])) {
                            try {
                                require_once __DIR__ . '/../../includes/email.php';
                                $mailer = class_exists('EmailSender') ? new EmailSender() : null;
                                if ($mailer && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    $loginUrl = getConfiguredAppUrl() . '/modules/auth/login.php';
                                    $subject = "PMS Account Setup - Login Details";
                                    $body = buildAccountMailBody($fullName, $username, $email, $rawPassword, $loginUrl, 'setup');
                                    $sent = $mailer->send($email, $subject, $body, true);
                                    $mailNote = $sent ? " Setup email sent." : " User created, but setup email failed.";
                                } else {
                                    $mailNote = " User created, but setup email could not be sent (invalid mail settings/email).";
                                }
                            } catch (Exception $mailEx) {
                                error_log('Add user setup mail error: ' . $mailEx->getMessage());
                                $mailNote = " User created, but setup email failed.";
                            }
                        }
                        $_SESSION['success'] = "User added successfully! They will be asked to reset their password on first login." . $mailNote;
                    } else {
                        $_SESSION['error'] = "Failed to add user. Please try again.";
                    }
                } catch (PDOException $e) {
                    // Duplicate key race or other DB issue
                    if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                        $_SESSION['error'] = "Failed to add user: username or email already exists.";
                    } else {
                        // For other errors, log and show generic message
                        error_log('Add user error: ' . $e->getMessage());
                        $_SESSION['error'] = "An unexpected database error occurred while adding the user.";
                    }
                }
            }
        }
    } elseif (isset($_POST['update_user'])) {
        $userId = $_POST['user_id'];
        $fullName = sanitizeInput($_POST['full_name']);
        $role = sanitizeInput($_POST['role']);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $canManageConfig = isset($_POST['can_manage_issue_config']) ? 1 : 0;
        $canManageDevices = isset($_POST['can_manage_devices']) ? 1 : 0;

        // Fetch previous permissions for notification diff
        $prevStmt = $db->prepare("SELECT can_manage_issue_config, can_manage_devices FROM users WHERE id = ? LIMIT 1");
        $prevStmt->execute([$userId]);
        $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: ['can_manage_issue_config' => 0, 'can_manage_devices' => 0];
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                UPDATE users 
                SET full_name = ?, role = ?, is_active = ?, can_manage_issue_config = ?, can_manage_devices = ?
                WHERE id = ?
            ");
            $stmt->execute([$fullName, $role, $isActive, $canManageConfig, $canManageDevices, $userId]);

            // Sync role in user_assignments for active assignments
            $syncStmt = $db->prepare("
                UPDATE user_assignments 
                SET role = ? 
                WHERE user_id = ? AND (is_removed IS NULL OR is_removed = 0)
            ");
            $syncStmt->execute([$role, $userId]);

            $db->commit();
            
            // Notify user if permission changed
            $baseDir = getBaseDir();
            if ((int)$prev['can_manage_issue_config'] !== (int)$canManageConfig) {
                $msg = $canManageConfig ? 'You have been granted Issue Config access.' : 'Your Issue Config access has been removed.';
                createNotification($db, (int)$userId, 'system', $msg, $baseDir . "/modules/admin/issue_config.php");
            }
            if ((int)$prev['can_manage_devices'] !== (int)$canManageDevices) {
                $msg = $canManageDevices ? 'You have been granted Device Management access.' : 'Your Device Management access has been removed.';
                createNotification($db, (int)$userId, 'system', $msg, $baseDir . "/modules/admin/devices.php");
            }
            
            $_SESSION['success'] = "User updated successfully and project roles synchronized!";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("User update/sync failed: " . $e->getMessage());
            $_SESSION['error'] = "Failed to update user. Please try again.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $isAjax = (
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        );

        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($userId <= 0) {
            $msg = "Invalid user selected.";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
            $_SESSION['error'] = $msg;
        } elseif (strlen($newPassword) < 6) {
            $msg = "Password must be at least 6 characters.";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
            $_SESSION['error'] = $msg;
        } elseif ($newPassword !== $confirmPassword) {
            $msg = "Passwords do not match.";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
            $_SESSION['error'] = $msg;
        } else {
            $password = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update password and force the user to reset their password on next login
            $db->prepare("UPDATE users SET password = ?, force_password_reset = 1 WHERE id = ?")
               ->execute([$password, $userId]);
            $msg = "Password reset successfully! The user will be required to change their password on next login.";

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $msg]);
                exit;
            }
            $_SESSION['success'] = $msg;
        }
    } elseif (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'];
        $userId = intval($userId);

        // Check if user is the current user
        if ($userId == intval($_SESSION['user_id'] ?? 0)) {
            $_SESSION['error'] = "You cannot delete your own account!";
        } else {
            try {
                // Check for dependencies (Projects, Assignments, etc.)
                $hasData = false;

                // Check Projects
                $stmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE created_by = ? OR project_lead_id = ?");
                $stmt->execute([$userId, $userId]);
                if ((int)$stmt->fetchColumn() > 0) $hasData = true;

                if (!$hasData) {
                    // Check only active assignments for this user.
                    // Soft-removed entries (is_removed=1) are shown under "Removed resources"
                    // and should not block admin delete.
                    $stmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM user_assignments
                        WHERE user_id = ?
                          AND (is_removed IS NULL OR is_removed = 0)
                    ");
                    $stmt->execute([$userId]);
                    if ((int)$stmt->fetchColumn() > 0) $hasData = true;
                }

                if (!$hasData) {
                    // Check Testing/QA Results
                    $stmt = $db->prepare("SELECT COUNT(*) FROM testing_results WHERE tester_id = ?");
                    $stmt->execute([$userId]);
                    if ((int)$stmt->fetchColumn() > 0) $hasData = true;

                    $stmt = $db->prepare("SELECT COUNT(*) FROM qa_results WHERE qa_id = ?");
                    $stmt->execute([$userId]);
                    if ((int)$stmt->fetchColumn() > 0) $hasData = true;
                }

                if ($hasData) {
                    $_SESSION['error'] = "Cannot delete user with existing data (projects/tasks). Please deactivate them instead.";
                } else {
                    $fkRefs = getUserFkReferences($db);
                    $blockingRefs = [];
                    $nullableRestrictRefs = [];

                    foreach ($fkRefs as $ref) {
                        $table = $ref['TABLE_NAME'] ?? '';
                        $column = $ref['COLUMN_NAME'] ?? '';
                        $rule = strtoupper((string)($ref['DELETE_RULE'] ?? ''));
                        $isNullable = strtoupper((string)($ref['IS_NULLABLE'] ?? '')) === 'YES';

                        if (!isSafeSqlIdentifier($table) || !isSafeSqlIdentifier($column)) {
                            continue;
                        }

                        $rowCount = countUserRefRows($db, $table, $column, $userId);
                        if ($rowCount <= 0) continue;

                        // CASCADE / SET NULL are handled by DB automatically on delete.
                        if ($rule === 'CASCADE' || $rule === 'SET NULL') {
                            continue;
                        }

                        // RESTRICT/NO ACTION on nullable columns can be cleaned manually.
                        if ($isNullable) {
                            $nullableRestrictRefs[] = ['table' => $table, 'column' => $column, 'count' => $rowCount];
                        } else {
                            $blockingRefs[] = ['table' => $table, 'column' => $column, 'count' => $rowCount];
                        }
                    }

                    if (!empty($blockingRefs)) {
                        $preview = array_slice($blockingRefs, 0, 3);
                        $detailParts = [];
                        foreach ($preview as $b) {
                            $detailParts[] = $b['table'] . '.' . $b['column'] . ' (' . $b['count'] . ')';
                        }
                        $_SESSION['error'] = "Cannot delete user with existing data references. Please deactivate user instead. Blocking: " . implode(', ', $detailParts);
                    } else {
                        $db->beginTransaction();
                        try {
                            foreach ($nullableRestrictRefs as $n) {
                                $sql = "UPDATE `{$n['table']}` SET `{$n['column']}` = NULL WHERE `{$n['column']}` = ?";
                                $db->prepare($sql)->execute([$userId]);
                            }

                            // Extra cleanup for optional links not always declared as FKs across installations.
                            $cleanupQueries = [
                                "UPDATE project_pages SET created_by = NULL WHERE created_by = ?",
                                "UPDATE project_pages SET at_tester_id = NULL WHERE at_tester_id = ?",
                                "UPDATE project_pages SET ft_tester_id = NULL WHERE ft_tester_id = ?",
                                "UPDATE project_pages SET qa_id = NULL WHERE qa_id = ?",
                                "UPDATE projects SET created_by = NULL WHERE created_by = ?",
                                "UPDATE projects SET project_lead_id = NULL WHERE project_lead_id = ?",
                                "UPDATE user_assignments SET user_id = NULL WHERE user_id = ?",
                                "UPDATE user_assignments SET assigned_by = NULL WHERE assigned_by = ?"
                            ];
                            foreach ($cleanupQueries as $q) {
                                try {
                                    $db->prepare($q)->execute([$userId]);
                                } catch (PDOException $inner) {
                                    // ignore optional schema differences
                                }
                            }

                            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                            if ($stmt->execute([$userId])) {
                                $db->commit();
                                $_SESSION['success'] = "User deleted successfully!";
                            } else {
                                $db->rollBack();
                                $_SESSION['error'] = "Failed to delete user due to database constraints.";
                            }
                        } catch (Exception $innerDelete) {
                            if ($db->inTransaction()) $db->rollBack();
                            throw $innerDelete;
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log('Delete user error: ' . $e->getMessage());
                $_SESSION['error'] = "Unable to delete user right now due to database constraints. Please deactivate the user instead.";
            } catch (Exception $e) {
                error_log('Delete user unexpected error: ' . $e->getMessage());
                $_SESSION['error'] = "Unexpected error while deleting user. Please try again.";
            }
        }
    } elseif (isset($_POST['send_credentials_email'])) {
        @set_time_limit(300);
        @ignore_user_abort(true);
        $selectedCsv = trim((string)($_POST['selected_user_ids'] ?? ''));
        $mailMode = trim((string)($_POST['mail_mode'] ?? 'setup'));
        if (!in_array($mailMode, ['setup', 'reset'], true)) {
            $mailMode = 'setup';
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $selectedCsv)), function($v) {
            return $v > 0;
        })));

        if (empty($ids)) {
            $_SESSION['error'] = "Please select at least one user.";
        } else {
            require_once __DIR__ . '/../../includes/email.php';
            $mailer = class_exists('EmailSender') ? new EmailSender() : null;

            if (!$mailer) {
                $_SESSION['error'] = "Email service is not available.";
            } else {
                $loginUrl = getConfiguredAppUrl() . '/modules/auth/login.php';

                $ok = 0;
                $failed = 0;
                $failedUsers = [];
                $attempted = 0;
                $consecutiveFail = 0;
                $stoppedEarly = false;

                foreach ($ids as $uid) {
                    $attempted++;
                    $result = sendCredentialsMailForUser($db, $uid, $mailMode);
                    if (!empty($result['success'])) {
                        $ok++;
                        $consecutiveFail = 0;
                    } else {
                        $failed++;
                        $consecutiveFail++;
                        $failedUsers[] = (string)($result['error'] ?? "User#{$uid}");
                        if ($ok === 0 && $consecutiveFail >= 3) {
                            $stoppedEarly = true;
                            break;
                        }
                    }
                }

                if ($ok > 0 && $failed === 0) {
                    $_SESSION['success'] = "Credentials email sent successfully to {$ok} user(s).";
                } elseif ($ok > 0 && $failed > 0) {
                    $preview = implode(', ', array_slice($failedUsers, 0, 5));
                    $extra = $stoppedEarly ? " Sending stopped early due to repeated mail transport failures." : "";
                    $_SESSION['error'] = "Email sent to {$ok} user(s), failed for {$failed} user(s)." . $extra . " Failed: {$preview}";
                } else {
                    $extra = $stoppedEarly ? " Sending stopped early due to repeated mail transport failures." : "";
                    $_SESSION['error'] = "Could not send credentials email to selected users. Please verify SMTP settings/network." . $extra;
                }
            }
        }
    }
    
    redirect($_SERVER['PHP_SELF']);
}

// AJAX: return user details (projects, pages, assignments, activity)
if (isset($_GET['action']) && $_GET['action'] === 'get_user_details' && isset($_GET['user_id'])) {
    try {
        $uid = intval($_GET['user_id']);
        $out = ['user' => null, 'projects' => [], 'pages' => [], 'assignments' => [], 'activity' => []];

        // Basic user info
        $stmt = $db->prepare("SELECT id, username, full_name, email, role, is_active, can_manage_issue_config, can_manage_devices FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $out['user'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // Projects where user is created_by or project_lead
        $stmt = $db->prepare("SELECT id, title, po_number, project_lead_id, created_by FROM projects WHERE created_by = ? OR project_lead_id = ? ORDER BY title");
        $stmt->execute([$uid, $uid]);
        $out['projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pages where user is involved (use correct column names: page_name)
        $stmt = $db->prepare(
            "SELECT pp.id, pp.page_name AS title, pp.page_number, pp.at_tester_id, pp.ft_tester_id, pp.qa_id, pp.created_by
             FROM project_pages pp
             WHERE pp.created_by = ? OR pp.at_tester_id = ? OR pp.ft_tester_id = ? OR pp.qa_id = ?
             OR (pp.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(?)))
             OR (pp.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(?)))
             ORDER BY pp.page_name"
        );
        $stmt->execute([$uid, $uid, $uid, $uid, $uid, $uid]);
        $out['pages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assignments
        $stmt = $db->prepare("
            SELECT ua.*, p.title as project_title, u.full_name as assigned_by_name 
            FROM user_assignments ua 
            LEFT JOIN projects p ON ua.project_id = p.id
            LEFT JOIN users u ON ua.assigned_by = u.id
            WHERE ua.user_id = ? OR ua.assigned_by = ? 
            ORDER BY ua.assigned_at DESC
        ");
        $stmt->execute([$uid, $uid]);
        $out['assignments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Activity (limit 100)
        $stmt = $db->prepare("
            SELECT al.*, p.title as project_title
            FROM activity_log al 
            LEFT JOIN projects p ON al.entity_type = 'project' AND al.entity_id = p.id
            WHERE al.user_id = ? 
            ORDER BY al.created_at DESC LIMIT 100
        ");
        $stmt->execute([$uid]);
        $out['activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'verify_credentials_mail') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);
    $requestId = trim((string)($_GET['request_id'] ?? ''));
    if ($uid <= 0 || !preg_match('/^[A-Za-z0-9_\-:.]{6,80}$/', $requestId)) {
        echo json_encode(['success' => false, 'error' => 'Invalid verification request']);
        exit;
    }
    try {
        ensureCredentialsMailLogTable($db);
        $stmt = $db->prepare("
            SELECT l.status, l.detail, u.username
            FROM credentials_mail_log l
            LEFT JOIN users u ON u.id = l.target_user_id
            WHERE l.request_id = ? AND l.target_user_id = ?
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $stmt->execute([$requestId, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'No delivery log found yet']);
            exit;
        }
        if (($row['status'] ?? '') === 'success') {
            echo json_encode(['success' => true, 'username' => (string)($row['username'] ?? "User#{$uid}")]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => (string)($row['detail'] ?? 'Delivery failed')]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Verification error: ' . $e->getMessage()]);
    }
    exit;
}

// Get all users with locked status
$users = $db->query("
    SELECT u.*, 
           u.two_factor_enabled,
           COUNT(DISTINCT p.id) as project_count,
           COUNT(DISTINCT pp.id) as page_count,
           (SELECT COUNT(*) FROM login_attempts la 
            WHERE la.username_hash = MD5(LOWER(u.username)) 
            AND la.attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) as login_attempt_count
    FROM users u
    LEFT JOIN projects p ON u.id = p.project_lead_id
    LEFT JOIN project_pages pp ON (
        u.id = pp.at_tester_id OR u.id = pp.ft_tester_id OR u.id = pp.qa_id
        OR (pp.at_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(u.id)))
        OR (pp.ft_tester_ids IS NOT NULL AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(u.id)))
    )
    WHERE u.role != 'client'
    GROUP BY u.id
    ORDER BY u.role, u.full_name
") -> fetchAll();

// Recent credentials mail logs for admin visibility
$credentialsMailLogs = [];
try {
    ensureCredentialsMailLogTable($db);
    $logStmt = $db->query("
        SELECT
            l.id,
            l.request_id,
            l.target_user_id,
            l.requested_by,
            l.mail_mode,
            l.status,
            l.detail,
            l.created_at,
            tu.username AS target_username,
            tu.full_name AS target_full_name,
            ru.username AS requested_by_username,
            ru.full_name AS requested_by_full_name
        FROM credentials_mail_log l
        LEFT JOIN users tu ON tu.id = l.target_user_id
        LEFT JOIN users ru ON ru.id = l.requested_by
        ORDER BY l.id DESC
        LIMIT 150
    ");
    $credentialsMailLogs = $logStmt ? ($logStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    // non-fatal; page still works even if log table is unavailable
    $credentialsMailLogs = [];
}

include __DIR__ . '/../../includes/header.php';
?>
<style>
#usersTable_wrapper .dataTables_length select {
    min-width: 86px;
    padding-right: 2rem !important;
    background-position: right 0.6rem center;
    text-overflow: clip;
}
#usersTable td, #usersTable th {
    white-space: nowrap;
}
#usersTable {
    width: 100% !important;
}
</style>
<?php
// Render compact fixed-position flash messages (top-right) so they don't push content
if (!empty($_SESSION['error'])) {
    $err = htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8');
    echo '<div class="position-fixed top-0 end-0 p-3" style="z-index:10800; max-width:420px;">
            <div class="alert alert-danger alert-dismissible" role="alert" style="margin:0;padding:.5rem .75rem;font-size:.95rem;box-shadow:0 6px 18px rgba(0,0,0,.12);">
                ' . $err . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="margin-left:.5rem"></button>
            </div>
          </div>';
    unset($_SESSION['error']);
}
if (!empty($_SESSION['success'])) {
    $msg = htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8');
    echo '<div class="position-fixed top-0 end-0 p-3" style="z-index:10800; max-width:420px;">
            <div class="alert alert-info alert-dismissible" role="alert" style="margin:0;padding:.45rem .7rem;font-size:.9rem;box-shadow:0 6px 18px rgba(0,0,0,.08);">
                ' . $msg . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="margin-left:.5rem"></button>
            </div>
          </div>';
    unset($_SESSION['success']);
}
?>
<div class="container-fluid">
    <h2><i class="fas fa-user-tie"></i> Internal Users Management</h2>
    <p class="text-muted">Manage internal team members (Admin, QA, Developers, Project Leads, Testers)</p>
    
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-user-plus"></i> Add New User
        </button>
        <button type="button" class="btn btn-outline-success" id="bulkMailBtn" data-bs-toggle="modal" data-bs-target="#bulkMailModal" disabled>
            <i class="fas fa-paper-plane"></i> Send Setup/Reset Mail
        </button>
        <button type="button" class="btn btn-outline-info" id="bulk2FAReminderBtn" disabled>
            <i class="fas fa-shield-alt"></i> Send 2FA Reminders
        </button>
        <span class="align-self-center text-muted small" id="selectedUsersHint">0 users selected</span>
    </div>

    <!-- Users Table -->

<!-- Autofocus the close button of the top-most toast/alert for keyboard users -->
<?php
echo '<script>(function(){try{function focusClose(){var container=document.querySelector(".position-fixed.top-0.end-0");if(!container) return;var alertEl=container.querySelector(".alert");if(!alertEl) return;var btn=alertEl.querySelector(".btn-close");if(btn){btn.tabIndex=-1;btn.focus();}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",focusClose);}else{focusClose();}}catch(e){}})();</script>';
?>
<div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="usersTable" class="table table-striped dataTable">
                    <thead>
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" id="selectAllUsers" aria-label="Select all users">
                            </th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Projects</th>
                            <th>Pages</th>
                            <th>Setup Status</th>
                            <th>Temp Password</th>
                            <th>Status</th>
                            <th>2FA</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="user-select" value="<?php echo (int)$user['id']; ?>" aria-label="Select <?php echo htmlspecialchars($user['username']); ?>">
                            </td>
                            <td><?php echo $user['username']; ?></td>
                            <td><?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></td>
                            <td><?php echo $user['email']; ?></td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $user['role'] === 'admin' ? 'danger' : 
                                         ($user['role'] === 'project_lead' ? 'warning' : 'info');
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                </span>
                            </td>
                            <td><?php echo $user['project_count']; ?></td>
                            <td><?php echo $user['page_count']; ?></td>
                            <td>
                                <?php if (!empty($user['account_setup_completed'])): ?>
                                    <span class="badge bg-success">Completed</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted">Not stored</span>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($user['two_factor_enabled'])): ?>
                                    <span class="badge bg-success" title="2FA Enabled"><i class="fas fa-shield-alt"></i> On</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary" title="2FA Disabled"><i class="fas fa-shield-alt"></i> Off</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm <?php echo ((int)($user['login_attempt_count'] ?? 0) >= 5) ? 'btn-danger' : 'btn-outline-secondary'; ?> unlock-user-btn"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                        data-username-hash="<?php echo md5(strtolower($user['username'])); ?>"
                                        title="<?php echo ((int)($user['login_attempt_count'] ?? 0) >= 5) ? 'Account Locked - Click to Unlock' : 'Unlock / Clear Login Attempts'; ?>">
                                    <i class="fas fa-<?php echo ((int)($user['login_attempt_count'] ?? 0) >= 5) ? 'lock' : 'unlock'; ?>"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-info send-reset-email-btn" 
                                        data-user-id="<?php echo $user['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                        title="Send Reset Password Email">
                                    <i class="fas fa-envelope"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary manual-reset-password-btn" 
                                        data-user-id="<?php echo $user['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                        title="Set Manual Password (Copy/Paste)">
                                    <i class="fas fa-key"></i>
                                </button>
                                    <button type="button" class="btn btn-sm btn-secondary view-user-btn" data-user-id="<?php echo $user['id']; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                <?php if (empty($user['two_factor_enabled']) && $user['is_active']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary send-2fa-reminder-btn" 
                                             data-user-id="<?php echo $user['id']; ?>"
                                             data-fullname="<?php echo htmlspecialchars($user['full_name']); ?>"
                                             title="Send 2FA Reminder Email">
                                         <i class="fas fa-shield-alt"></i>
                                     </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#deleteUserModal<?php echo $user['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Edit User Modal -->
                        <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit User: <?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label>Full Name *</label>
                                                <input type="text" name="full_name" class="form-control" 
                                                       value="<?php echo $user['full_name']; ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Role *</label>
                                                <select name="role" class="form-select" required>
                                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                    <option value="project_lead" <?php echo $user['role'] === 'project_lead' ? 'selected' : ''; ?>>Project Lead</option>
                                                    <option value="qa" <?php echo $user['role'] === 'qa' ? 'selected' : ''; ?>>QA</option>
                                                    <option value="at_tester" <?php echo $user['role'] === 'at_tester' ? 'selected' : ''; ?>>AT Tester</option>
                                                    <option value="ft_tester" <?php echo $user['role'] === 'ft_tester' ? 'selected' : ''; ?>>FT Tester</option>
                                                    <option value="client" <?php echo $user['role'] === 'client' ? 'selected' : ''; ?>>Client</option>
                                                </select>
                                            </div>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="is_active" class="form-check-input" 
                                                       id="active<?php echo $user['id']; ?>" 
                                                       <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="active<?php echo $user['id']; ?>">
                                                    Active User
                                                </label>
                                            </div>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="can_manage_issue_config" class="form-check-input" 
                                                       id="config<?php echo $user['id']; ?>" 
                                                       <?php echo !empty($user['can_manage_issue_config']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="config<?php echo $user['id']; ?>">
                                                    Can Manage Issue Config
                                                </label>
                                            </div>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" name="can_manage_devices" class="form-check-input" 
                                                       id="devicesPerm<?php echo $user['id']; ?>" 
                                                       <?php echo !empty($user['can_manage_devices']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="devicesPerm<?php echo $user['id']; ?>">
                                                    Can Manage Devices
                                                </label>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" name="update_user" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Delete User Modal -->
                        <div class="modal fade" id="deleteUserModal<?php echo $user['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title text-danger">Delete User</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Are you sure you want to delete user <strong><?php echo renderUserNameLink(['id'=>$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']]); ?></strong>?</p>
                                            <p class="text-danger"><small>Note: This action cannot be undone. You can only delete users who have no associated projects or tasks.</small></p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <button
            class="btn btn-sm btn-outline-secondary"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mailLogsCollapse"
            aria-expanded="false"
            aria-controls="mailLogsCollapse"
        >
            <i class="fas fa-envelope-open-text me-1"></i> Setup/Reset Mail Logs (Expand/Collapse)
        </button>
        <small class="text-muted">Latest <?php echo (int)count($credentialsMailLogs); ?> entries</small>
    </div>
    <div id="mailLogsCollapse" class="collapse">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="credentialsMailLogsTable" class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:120px;">When</th>
                            <th style="min-width:150px;">User</th>
                            <th style="min-width:90px;">Mode</th>
                            <th style="min-width:90px;">Status</th>
                            <th style="min-width:150px;">Sent By</th>
                            <th style="min-width:230px;">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($credentialsMailLogs)): ?>
                            <?php foreach ($credentialsMailLogs as $log): ?>
                                <tr>
                                    <td>
                                        <small><?php echo htmlspecialchars((string)($log['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                            $targetLabel = trim((string)($log['target_full_name'] ?? ''));
                                            if ($targetLabel === '') $targetLabel = trim((string)($log['target_username'] ?? ''));
                                            if ($targetLabel === '') $targetLabel = 'User#' . (int)($log['target_user_id'] ?? 0);
                                        ?>
                                        <span><?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td>
                                        <?php $mode = strtolower((string)($log['mail_mode'] ?? 'setup')); ?>
                                        <span class="badge bg-<?php echo $mode === 'reset' ? 'warning text-dark' : 'info'; ?>">
                                            <?php echo htmlspecialchars(strtoupper($mode), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php $ok = strtolower((string)($log['status'] ?? 'fail')) === 'success'; ?>
                                        <span class="badge bg-<?php echo $ok ? 'success' : 'danger'; ?>">
                                            <?php echo $ok ? 'SUCCESS' : 'FAIL'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $byLabel = trim((string)($log['requested_by_full_name'] ?? ''));
                                            if ($byLabel === '') $byLabel = trim((string)($log['requested_by_username'] ?? ''));
                                            if ($byLabel === '') $byLabel = 'System';
                                        ?>
                                        <span><?php echo htmlspecialchars($byLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars((string)($log['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">No setup/reset mail logs found yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Bulk setup/reset mail modal -->
<div class="modal fade" id="bulkMailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="bulkMailForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="send_credentials_email" value="1">
                <input type="hidden" name="selected_user_ids" id="selectedUserIdsInput" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Send Setup/Reset Mail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Selected users: <strong id="selectedUsersCount">0</strong></p>
                    <div class="mb-3">
                        <label class="form-label d-block mb-2">Mail Type</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mail_mode" id="mailModeSetup" value="setup" checked>
                            <label class="form-check-label" for="mailModeSetup">Account setup email</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mail_mode" id="mailModeReset" value="reset">
                            <label class="form-check-label" for="mailModeReset">Password reset email</label>
                        </div>
                    </div>
                    <div class="alert alert-warning mb-0 py-2">
                        Each selected user will receive an individual email with:
                        username, email, temporary password, and login link.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Send Individual Emails</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Username *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Email *</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Role *</label>
                        <select name="role" class="form-select" required>
                            <option value="admin">Admin</option>
                            <option value="project_lead">Project Lead</option>
                            <option value="qa">QA</option>
                            <option value="at_tester">AT Tester</option>
                            <option value="ft_tester">FT Tester</option>
                            <option value="client">Client</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="can_manage_issue_config" class="form-check-input" id="addConfigPerm">
                        <label class="form-check-label" for="addConfigPerm">
                            Can Manage Issue Config
                        </label>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="can_manage_devices" class="form-check-input" id="addDevicesPerm">
                        <label class="form-check-label" for="addDevicesPerm">
                            Can Manage Devices
                        </label>
                    </div>
                    <div class="mb-3">
                        <label>Password *</label>
                        <input type="password" name="password" autocomplete="off" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" autocomplete="off" class="form-control" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="send_setup_email" class="form-check-input" id="sendSetupEmailNow" value="1">
                        <label class="form-check-label" for="sendSetupEmailNow">
                            Send setup email now (username, email, password, login link)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
$(document).ready(function() {
    // Ensure users table is initialized with checkbox column non-sortable.
    // This prevents header-sort click conflicts with the select-all checkbox.
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#usersTable')) {
        $('#usersTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false }
            ],
            language: {
                search: 'Filter:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries'
            }
        });
    }

    function initCredentialsLogsTable() {
        if (!$.fn.DataTable || $.fn.DataTable.isDataTable('#credentialsMailLogsTable')) return;
        $('#credentialsMailLogsTable').DataTable({
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            paging: true,
            searching: true,
            info: true,
            autoWidth: false,
            order: [[0, 'desc']],
            columnDefs: [
                { targets: [2, 3], orderable: false }
            ],
            language: {
                search: 'Filter logs:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries'
            }
        });
    }

    $('#mailLogsCollapse').on('shown.bs.collapse', function() {
        initCredentialsLogsTable();
        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#credentialsMailLogsTable')) {
            $('#credentialsMailLogsTable').DataTable().columns.adjust().draw(false);
        }
    });

    if ($('#mailLogsCollapse').hasClass('show')) {
        initCredentialsLogsTable();
    }

    // Password confirmation validation
    $('form').on('submit', function() {
        var password = $('input[name="password"]').val();
        var confirmPassword = $('input[name="confirm_password"]').val();
        
        if (password && confirmPassword && password !== confirmPassword) {
            showToast('Passwords do not match!', 'warning');
            return false;
        }
        return true;
    });

    // Reset password form (force reset) via AJAX to avoid full page reload.
    $(document).on('submit', 'form[data-reset-password-form="1"]', function(e) {
        e.preventDefault();
        const form = this;
        const $form = $(form);
        const $submitBtn = $form.find('button[type="submit"][name="reset_password"]');
        const newPassword = String($form.find('input[name="new_password"]').val() || '');
        const confirmPassword = String($form.find('input[name="confirm_password"]').val() || '');

        if (newPassword.length < 6) {
            showToast('Password must be at least 6 characters.', 'warning');
            return false;
        }
        if (newPassword !== confirmPassword) {
            showToast('Passwords do not match!', 'warning');
            return false;
        }

        const oldBtnHtml = $submitBtn.html();
        $submitBtn.prop('disabled', true).html('Resetting...');

        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            dataType: 'json',
            timeout: 60000,
            data: $form.serialize() + '&reset_password=1',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function(resp) {
            if (resp && resp.success) {
                showToast(resp.message || 'Password reset successfully.', 'success');
                try {
                    const modalEl = form.closest('.modal');
                    if (modalEl) {
                        const instance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                        instance.hide();
                    }
                } catch (err) {}
                form.reset();
            } else {
                showToast((resp && resp.error) ? resp.error : 'Failed to reset password.', 'danger');
            }
        }).fail(function(xhr) {
            let msg = 'Failed to reset password.';
            try {
                const j = xhr.responseJSON;
                if (j && (j.error || j.message)) msg = j.error || j.message;
            } catch (err) {}
            showToast(msg, 'danger');
        }).always(function() {
            $submitBtn.prop('disabled', false).html(oldBtnHtml);
        });

        return false;
    });

    const selectedUserIds = new Set();

    function getVisibleUserIds() {
        return $('.user-select').map(function() {
            return String($(this).val());
        }).get();
    }

    function getSelectedUserIds() {
        return Array.from(selectedUserIds);
    }

    function syncVisibleSelectionState() {
        $('.user-select').each(function() {
            const uid = String($(this).val());
            $(this).prop('checked', selectedUserIds.has(uid));
        });

        const visibleIds = getVisibleUserIds();
        const total = visibleIds.length;
        let checked = 0;
        visibleIds.forEach(function(uid) {
            if (selectedUserIds.has(uid)) checked++;
        });
        const allChecked = total > 0 && checked === total;
        const partial = checked > 0 && checked < total;
        $('#selectAllUsers').prop('checked', allChecked).prop('indeterminate', partial);
    }

    function refreshBulkMailUi() {
        const selected = getSelectedUserIds();
        const count = selected.length;
        $('#bulkMailBtn').prop('disabled', count === 0);
        $('#selectedUsersHint').text(count + ' users selected');
        $('#selectedUsersCount').text(count);
        $('#selectedUserIdsInput').val(selected.join(','));
        syncVisibleSelectionState();
    }

    $(document).on('click', '#selectAllUsers', function(e) {
        e.stopPropagation(); // prevent header sort click side-effects
        e.preventDefault();
        const visibleIds = getVisibleUserIds();
        if (!visibleIds.length) {
            refreshBulkMailUi();
            return;
        }

        const allVisibleSelected = visibleIds.every(function(uid) {
            return selectedUserIds.has(uid);
        });

        if (allVisibleSelected) {
            visibleIds.forEach(function(uid) { selectedUserIds.delete(uid); });
        } else {
            visibleIds.forEach(function(uid) { selectedUserIds.add(uid); });
        }
        refreshBulkMailUi();
    });

    $(document).on('change', '.user-select', function() {
        const uid = String($(this).val());
        if ($(this).is(':checked')) {
            selectedUserIds.add(uid);
        } else {
            selectedUserIds.delete(uid);
        }
        refreshBulkMailUi();
    });

    // DataTables redraw (pagination/search/sort) can re-render rows;
    // re-apply selection so "Select All" works across all pages.
    $('#usersTable').on('draw.dt', function() {
        syncVisibleSelectionState();
    });

    $('#bulkMailForm').on('submit', async function(e) {
        const selected = getSelectedUserIds();
        if (!selected.length) {
            e.preventDefault();
            showToast('Please select at least one user.', 'warning');
            return false;
        }
        e.preventDefault();
        $('#selectedUserIdsInput').val(selected.join(','));

        const mode = $('input[name="mail_mode"]:checked').val() || 'setup';
        const $form = $(this);
        const endpointUrl = $form.attr('action') || window.location.pathname;
        const $submitBtn = $form.find('button[type="submit"]');
        const oldBtnHtml = $submitBtn.html();
        $submitBtn.prop('disabled', true).html('Sending...');

        let ok = 0;
        let failed = 0;
        const sentUsers = [];
        const failedUsers = [];

        for (let i = 0; i < selected.length; i++) {
            const uid = selected[i];
            const requestId = 'mail_' + uid + '_' + Date.now() + '_' + Math.floor(Math.random() * 1000000);
            try {
                const resp = await $.ajax({
                    url: endpointUrl,
                    method: 'POST',
                    dataType: 'json',
                    timeout: 180000,
                    data: {
                        send_credentials_email_single: 1,
                        user_id: uid,
                        mail_mode: mode,
                        request_id: requestId,
                        csrf_token: window._csrfToken || (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || ''
                    }
                });
                if (resp && resp.success) {
                    ok++;
                    sentUsers.push((resp && resp.username) ? resp.username : ('User#' + uid));
                } else {
                    failed++;
                    failedUsers.push((resp && resp.error) ? resp.error : ('User#' + uid));
                }
            } catch (err) {
                try {
                    const verify = await $.ajax({
                        url: endpointUrl,
                        method: 'GET',
                        dataType: 'json',
                        timeout: 25000,
                        data: {
                            action: 'verify_credentials_mail',
                            user_id: uid,
                            request_id: requestId
                        }
                    });
                    if (verify && verify.success) {
                        ok++;
                        sentUsers.push((verify && verify.username) ? (verify.username + ' (verified)') : ('User#' + uid + ' (verified)'));
                    } else {
                        failed++;
                        failedUsers.push((verify && verify.error) ? verify.error : ('User#' + uid + ' request timeout/connection closed'));
                    }
                } catch (verifyErr) {
                    failed++;
                    failedUsers.push('User#' + uid + ' request timeout/connection closed');
                }
            }
            $('#selectedUsersCount').text((selected.length - (i + 1)) + ' pending');
        }

        $submitBtn.prop('disabled', false).html(oldBtnHtml);
        const modalEl = document.getElementById('bulkMailModal');
        if (modalEl) {
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        }

        function listText(arr, maxItems) {
            const lim = Math.max(1, maxItems || 10);
            if (!arr || !arr.length) return '';
            const shown = arr.slice(0, lim).join(', ');
            const more = arr.length > lim ? (' +' + (arr.length - lim) + ' more') : '';
            return shown + more;
        }

        if (ok > 0 && failed === 0) {
            showToast(
                'Sent to ' + ok + ' user(s): ' + listText(sentUsers, 12),
                'success'
            );
        } else if (ok > 0 && failed > 0) {
            showToast(
                'Sent: ' + ok + ' [' + listText(sentUsers, 8) + '] | Failed: ' + failed + ' [' + listText(failedUsers, 6) + ']',
                'warning'
            );
            console.warn('Failed users:', failedUsers);
        } else {
            showToast(
                'Could not send credentials email. Failed users: ' + listText(failedUsers, 10),
                'danger'
            );
            console.warn('Failed users:', failedUsers);
        }
        $('#selectedUsersCount').text(selected.length);
        return false;
    });

    refreshBulkMailUi();
});
</script>
<script nonce="<?php echo $cspNonce ?? ''; ?>">
window._adminUsersConfig = {
    baseDir: '<?php echo htmlspecialchars(rtrim(getBaseDir(), '/'), ENT_QUOTES, 'UTF-8'); ?>'
};
</script>
<!-- Manual Reset Password Modal -->
<div class="modal fade" id="manualResetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="manualResetPasswordForm" method="POST" data-reset-password-form="1">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="user_id" id="manualResetUserId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Set Manual Temporary Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Setting a temporary password for <strong id="manualResetUsername"></strong>.</p>
                    <div class="alert alert-warning py-2 small">
                        <i class="fas fa-exclamation-triangle"></i> Use this if the automated email fails. You must copy and send this password to the user manually.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Temporary Password</label>
                        <div class="input-group">
                            <input type="text" name="new_password" id="manualResetPasswordInput" class="form-control" required>
                            <button class="btn btn-outline-secondary" type="button" id="generateRandomPass">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="text" name="confirm_password" id="manualResetConfirmInput" class="form-control" required>
                    </div>
                    <p class="text-info small"><i class="fas fa-info-circle"></i> The user will be forced to change this password on their next login.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-primary">Set Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewUserModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="viewUserContent">
          <p><strong>Loading...</strong></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="<?php echo htmlspecialchars(getBaseDir(), ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-users.js"></script>

<script>
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.unlock-user-btn');
    if (!btn) return;
    var username = btn.getAttribute('data-username');
    var hash = btn.getAttribute('data-username-hash');
    if (!confirm('Unlock account for "' + username + '"?')) return;
    fetch('<?php echo getBaseDir(); ?>/api/unlock_account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window._csrfToken || '' },
        body: 'action=unlock&username_hash=' + encodeURIComponent(hash) + '&csrf_token=' + encodeURIComponent(window._csrfToken || '')
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (typeof showToast === 'function') showToast('Account unlocked for ' + username, 'success');
            btn.remove();
        } else {
            if (typeof showToast === 'function') showToast('Failed to unlock: ' + (data.error || ''), 'danger');
        }
    })
    .catch(() => { if (typeof showToast === 'function') showToast('Request failed', 'danger'); });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; 