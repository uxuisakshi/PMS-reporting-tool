<?php
ob_start();
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = Database::getInstance();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only admins can access
if (!in_array($user_role, ['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// CSRF validation for all state-changing (non-GET) requests
enforceApiCsrf();

// AES-256-GCM encryption with random IV and authentication tag
function getVaultKey() {
    $key = getenv('VAULT_ENCRYPTION_KEY');
    if (!$key) {
        // Derive from APP_KEY env var or a server-specific secret
        $appKey = getenv('APP_KEY') ?: (defined('APP_SECRET') ? APP_SECRET : '');
        if (empty($appKey)) {
            // No key configured - refuse to operate rather than use a weak predictable key
            error_log('SECURITY: VAULT_ENCRYPTION_KEY or APP_KEY environment variable not set. Vault operations disabled.');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Vault encryption key not configured. Set VAULT_ENCRYPTION_KEY environment variable.']);
            exit;
        }
        // Use PBKDF2 for proper key derivation from APP_KEY.
        // Security: salt should come from env var, not a hardcoded constant.
        // Set VAULT_KEY_SALT to a random base64 string in your .env file.
        $salt = getenv('VAULT_KEY_SALT');
        if (!$salt) {
            throw new Exception('VAULT_KEY_SALT environment variable is required for vault security');
        }
        $key = hash_pbkdf2('sha256', $appKey, $salt, 100000, 32, true);
    } else {
        $key = base64_decode($key) ?: hash('sha256', $key, true);
    }
    return $key;
}

function encryptPassword($password) {
    $key = getVaultKey();
    $iv = random_bytes(12); // 96-bit IV for GCM
    $tag = '';
    $encrypted = openssl_encrypt($password, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($encrypted === false) return '';
    // Store: base64(iv + tag + ciphertext)
    return base64_encode($iv . $tag . $encrypted);
}

function decryptPassword($stored) {
    if (empty($stored)) return '';
    $key = getVaultKey();
    $raw = base64_decode($stored);
    if ($raw === false || strlen($raw) < 28) {
        // Legacy AES-128-ECB fallback (for old records only)
        // SECURITY: Legacy AES-128-ECB fallback — ECB mode lacks diffusion and leaks plaintext patterns.
        // This path is ONLY for migrating old records. REMOVE once all records are re-encrypted.
        // To disable: unset VAULT_LEGACY_KEY from your environment after running migration.
        $legacyKey = getenv('VAULT_LEGACY_KEY') ?: '';
        if ($legacyKey) {
            error_log('SECURITY WARNING: Legacy AES-128-ECB vault fallback triggered. '
                . 'Re-encrypt this record and then unset VAULT_LEGACY_KEY to remove this risk.');
            $result = @openssl_decrypt(base64_decode($stored), 'AES-128-ECB', $legacyKey);
            return $result !== false ? $result : '';
        }
        return '';
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $decrypted = openssl_decrypt($ciphertext, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $decrypted !== false ? $decrypted : '';
}

try {
    switch ($action) {
        // ===== CREDENTIALS =====
        case 'get_credentials':
            $stmt = $pdo->prepare("
                SELECT id, title, category, username, url, notes, tags, last_used, created_at, updated_at
                FROM admin_credentials
                WHERE admin_id = ?
                ORDER BY category, title
            ");
            $stmt->execute([$user_id]);
            $credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'credentials' => $credentials]);
            break;

        case 'get_credential_password':
            $credId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $stmt = $pdo->prepare("SELECT id, title, password_encrypted FROM admin_credentials WHERE id = ? AND admin_id = ?");
            $stmt->execute([$credId, $user_id]);
            $result = $stmt->fetch();
            if ($result) {
                $password = decryptPassword($result['password_encrypted']);
                // Audit log: every password reveal is recorded
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                        VALUES (?, 'vault_password_revealed', 'admin_credential', ?, ?, ?, NOW())
                    ");
                    $logStmt->execute([
                        $user_id,
                        (int)$result['id'],
                        json_encode(['credential_title' => $result['title'], 'accessed_by_role' => $user_role]),
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    ]);
                } catch (Exception $logEx) {
                    error_log('Vault audit log failed: ' . $logEx->getMessage());
                }
                echo json_encode(['success' => true, 'password' => $password]);
            } else {
                throw new Exception('Credential not found');
            }
            break;

        case 'add_credential':
            $stmt = $pdo->prepare("
                INSERT INTO admin_credentials (admin_id, title, category, username, password_encrypted, url, notes, tags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $encrypted = encryptPassword($_POST['password']);
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['category'],
                $_POST['username'] ?? null,
                $encrypted,
                $_POST['url'] ?? null,
                $_POST['notes'] ?? null,
                $_POST['tags'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Credential added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_credential':
            $sql = "UPDATE admin_credentials SET title = ?, category = ?, username = ?, url = ?, notes = ?, tags = ?";
            $params = [
                $_POST['title'],
                $_POST['category'],
                $_POST['username'] ?? null,
                $_POST['url'] ?? null,
                $_POST['notes'] ?? null,
                $_POST['tags'] ?? null
            ];
            
            if (!empty($_POST['password'])) {
                $sql .= ", password_encrypted = ?";
                $params[] = encryptPassword($_POST['password']);
            }
            
            $sql .= " WHERE id = ? AND admin_id = ?";
            $params[] = $_POST['id'];
            $params[] = $user_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Credential updated']);
            break;

        case 'delete_credential':
            $stmt = $pdo->prepare("DELETE FROM admin_credentials WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Credential deleted']);
            break;

        case 'mark_credential_used':
            $stmt = $pdo->prepare("UPDATE admin_credentials SET last_used = NOW() WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Marked as used']);
            break;

        // ===== NOTES =====
        case 'get_notes':
            $stmt = $pdo->prepare("
                SELECT * FROM admin_notes
                WHERE admin_id = ?
                ORDER BY is_pinned DESC, updated_at DESC
            ");
            $stmt->execute([$user_id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'notes' => $notes]);
            break;

        case 'add_note':
            $stmt = $pdo->prepare("
                INSERT INTO admin_notes (admin_id, title, content, category, color, is_pinned, tags)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['content'] ?? null,
                $_POST['category'] ?? 'General',
                $_POST['color'] ?? '#ffffff',
                $_POST['is_pinned'] ?? 0,
                $_POST['tags'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Note added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_note':
            $stmt = $pdo->prepare("
                UPDATE admin_notes 
                SET title = ?, content = ?, category = ?, color = ?, is_pinned = ?, tags = ?
                WHERE id = ? AND admin_id = ?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['content'] ?? null,
                $_POST['category'] ?? 'General',
                $_POST['color'] ?? '#ffffff',
                $_POST['is_pinned'] ?? 0,
                $_POST['tags'] ?? null,
                $_POST['id'],
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Note updated']);
            break;

        case 'delete_note':
            $stmt = $pdo->prepare("DELETE FROM admin_notes WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Note deleted']);
            break;

        // ===== TODOS =====
        case 'get_todos':
            $stmt = $pdo->prepare("
                SELECT * FROM admin_todos
                WHERE admin_id = ?
                ORDER BY 
                    CASE status 
                        WHEN 'Pending' THEN 1 
                        WHEN 'In Progress' THEN 2 
                        WHEN 'Completed' THEN 3 
                        ELSE 4 
                    END,
                    CASE priority 
                        WHEN 'Critical' THEN 1 
                        WHEN 'High' THEN 2 
                        WHEN 'Medium' THEN 3 
                        ELSE 4 
                    END,
                    due_date ASC
            ");
            $stmt->execute([$user_id]);
            $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'todos' => $todos]);
            break;

        case 'add_todo':
            $stmt = $pdo->prepare("
                INSERT INTO admin_todos (admin_id, title, description, priority, status, due_date, tags)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['priority'] ?? 'Medium',
                $_POST['status'] ?? 'Pending',
                $_POST['due_date'] ?? null,
                $_POST['tags'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Todo added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_todo':
            $stmt = $pdo->prepare("
                UPDATE admin_todos 
                SET title = ?, description = ?, priority = ?, status = ?, due_date = ?, tags = ?,
                    completed_at = CASE WHEN ? = 'Completed' AND status != 'Completed' THEN NOW() ELSE completed_at END
                WHERE id = ? AND admin_id = ?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['priority'] ?? 'Medium',
                $_POST['status'] ?? 'Pending',
                $_POST['due_date'] ?? null,
                $_POST['tags'] ?? null,
                $_POST['status'] ?? 'Pending',
                $_POST['id'],
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Todo updated']);
            break;

        case 'delete_todo':
            $stmt = $pdo->prepare("DELETE FROM admin_todos WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Todo deleted']);
            break;

        // ===== MEETINGS =====
        case 'get_meetings':
            $stmt = $pdo->prepare("
                SELECT * FROM admin_meetings
                WHERE admin_id = ?
                ORDER BY meeting_date DESC, meeting_time DESC
            ");
            $stmt->execute([$user_id]);
            $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'meetings' => $meetings]);
            break;

        case 'add_meeting':
            $stmt = $pdo->prepare("
                INSERT INTO admin_meetings (admin_id, title, description, meeting_with, meeting_date, meeting_time, 
                                           duration_minutes, location, meeting_link, reminder_minutes, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['meeting_with'] ?? null,
                $_POST['meeting_date'],
                $_POST['meeting_time'],
                $_POST['duration_minutes'] ?? 30,
                $_POST['location'] ?? null,
                $_POST['meeting_link'] ?? null,
                $_POST['reminder_minutes'] ?? 15,
                $_POST['notes'] ?? null
            ]);
            echo json_encode(['success' => true, 'message' => 'Meeting added', 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_meeting':
            $stmt = $pdo->prepare("
                UPDATE admin_meetings 
                SET title = ?, description = ?, meeting_with = ?, meeting_date = ?, meeting_time = ?,
                    duration_minutes = ?, location = ?, meeting_link = ?, reminder_minutes = ?, status = ?, notes = ?
                WHERE id = ? AND admin_id = ?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'] ?? null,
                $_POST['meeting_with'] ?? null,
                $_POST['meeting_date'],
                $_POST['meeting_time'],
                $_POST['duration_minutes'] ?? 30,
                $_POST['location'] ?? null,
                $_POST['meeting_link'] ?? null,
                $_POST['reminder_minutes'] ?? 15,
                $_POST['status'] ?? 'Scheduled',
                $_POST['notes'] ?? null,
                $_POST['id'],
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Meeting updated']);
            break;

        case 'delete_meeting':
            $stmt = $pdo->prepare("DELETE FROM admin_meetings WHERE id = ? AND admin_id = ?");
            $stmt->execute([$_POST['id'], $user_id]);
            echo json_encode(['success' => true, 'message' => 'Meeting deleted']);
            break;

        // ===== DEVICE ROTATION HISTORY =====
        case 'get_device_rotation_history':
            $device_id = $_GET['device_id'] ?? null;
            if ($device_id) {
                $stmt = $pdo->prepare("
                    SELECT drh.*, 
                           d.device_name, d.device_type,
                           u1.full_name as from_user_name,
                           u2.full_name as to_user_name,
                           u3.full_name as rotated_by_name
                    FROM device_rotation_history drh
                    JOIN devices d ON drh.device_id = d.id
                    LEFT JOIN users u1 ON drh.from_user_id = u1.id
                    JOIN users u2 ON drh.to_user_id = u2.id
                    JOIN users u3 ON drh.rotated_by = u3.id
                    WHERE drh.device_id = ?
                    ORDER BY drh.rotation_date DESC
                ");
                $stmt->execute([$device_id]);
            } else {
                $stmt = $pdo->query("
                    SELECT drh.*, 
                           d.device_name, d.device_type,
                           u1.full_name as from_user_name,
                           u2.full_name as to_user_name,
                           u3.full_name as rotated_by_name
                    FROM device_rotation_history drh
                    JOIN devices d ON drh.device_id = d.id
                    LEFT JOIN users u1 ON drh.from_user_id = u1.id
                    JOIN users u2 ON drh.to_user_id = u2.id
                    JOIN users u3 ON drh.rotated_by = u3.id
                    ORDER BY drh.rotation_date DESC
                    LIMIT 100
                ");
            }
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log('admin_vault.php error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
