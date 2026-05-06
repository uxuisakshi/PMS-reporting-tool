<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
ob_end_clean();

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF protection for state-changing requests
enforceApiCsrf();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    if ($action === 'submit_feedback') {
        // Removed rate limiting as per user request to allow unlimited feedback.


        // Support multiple recipients and optional project scoping
        $recipientIds = $_POST['recipient_ids'] ?? null; // expected as comma-separated or array
        $projectId = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
        $isGeneric = isset($_POST['is_generic']) && $_POST['is_generic'] == '1' ? 1 : 0;
        $sendToAdmin = isset($_POST['send_to_admin']) && $_POST['send_to_admin'] == '1' ? 1 : 0;
        $sendToLead = isset($_POST['send_to_lead']) && $_POST['send_to_lead'] == '1' ? 1 : 0;
        $content = $_POST['content'] ?? '';

        if (!$content) {
            echo json_encode(['success' => false, 'message' => 'Missing content']);
            exit;
        }

        // normalize recipient ids
        $recipients = [];
        if ($recipientIds) {
            if (is_array($recipientIds)) $recipients = array_map('intval', $recipientIds);
            else $recipients = array_filter(array_map('intval', explode(',', $recipientIds)));
        }

        // sanitize HTML content using existing sanitizer
        $clean = sanitize_chat_html($content);

        // Parse @mentions from content and auto-include mentioned users as recipients.
        $plain = trim(strip_tags($clean));
        $mentionedUserIds = [];
        if ($plain !== '') {
            preg_match_all('/@([A-Za-z0-9._-]+)/', $plain, $matches);
            $mentions = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
            if (!empty($mentions)) {
                $ph = implode(',', array_fill(0, count($mentions), '?'));
                $mStmt = $db->prepare("SELECT id, username FROM users WHERE is_active = 1 AND LOWER(username) IN ($ph)");
                $mStmt->execute($mentions);
                while ($u = $mStmt->fetch(PDO::FETCH_ASSOC)) {
                    $uid = (int)$u['id'];
                    if ($uid > 0 && $uid !== (int)$userId) {
                        $mentionedUserIds[] = $uid;
                    }
                }
            }
        }
        $recipients = array_values(array_unique(array_filter(array_merge($recipients, $mentionedUserIds), function ($id) use ($userId) {
            return (int)$id > 0 && (int)$id !== (int)$userId;
        })));

        $stmt = $db->prepare("INSERT INTO feedbacks (sender_id, target_user_id, send_to_admin, send_to_lead, content, project_id, is_generic, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $sendToAdmin, $sendToLead, $clean, $projectId, $isGeneric]);
        $feedbackId = $db->lastInsertId();

        // Insert recipients mapping
        if (!empty($recipients)) {
            $ins = $db->prepare("INSERT INTO feedback_recipients (feedback_id, user_id) VALUES (?, ?)");
            foreach ($recipients as $rid) {
                $ins->execute([$feedbackId, $rid]);
            }
        }

        // Build additional notification recipients from flags.
        $notificationRecipients = $recipients;
        if ($sendToAdmin) {
            $adminStmt = $db->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin')");
            while ($row = $adminStmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$row['id'];
                if ($uid > 0 && $uid !== (int)$userId) $notificationRecipients[] = $uid;
            }
        }
        if ($sendToLead && $projectId) {
            $leadStmt = $db->prepare("SELECT project_lead_id FROM projects WHERE id = ? LIMIT 1");
            $leadStmt->execute([$projectId]);
            $leadId = (int)$leadStmt->fetchColumn();
            if ($leadId > 0 && $leadId !== (int)$userId) $notificationRecipients[] = $leadId;
        }
        $notificationRecipients = array_values(array_unique(array_filter($notificationRecipients)));

        // Create notifications for recipients.
        $senderName = $_SESSION['full_name'] ?? 'A user';
        $feedbackLink = getBaseDir() . '/modules/feedback.php?view=my';
        foreach ($notificationRecipients as $rid) {
            createNotification(
                $db,
                (int)$rid,
                'mention',
                $senderName . ' shared feedback with you.',
                $feedbackLink
            );
        }

        // Log activity
        logActivity($db, $userId, 'submit_feedback', 'feedback', $feedbackId, [
            'recipients' => $recipients,
            'send_to_admin' => $sendToAdmin,
            'send_to_lead' => $sendToLead,
            'project_id' => $projectId,
            'is_generic' => $isGeneric
        ]);

        if ($sendToAdmin) {
            logActivity($db, $userId, 'notify', 'user', 0, ['to' => 'admin', 'feedback_id' => $feedbackId]);
        }
        if ($sendToLead) {
            logActivity($db, $userId, 'notify', 'user', 0, ['to' => 'project_lead', 'feedback_id' => $feedbackId]);
        }

        echo json_encode(['success' => true, 'message' => 'Feedback submitted']);
    } 
    elseif ($action === 'update_status') {
        // Admin functionality to update feedback status
        if (!in_array($userRole, ['admin'])) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $feedbackId = $_POST['feedback_id'] ?? '';
        $status = $_POST['status'] ?? '';
        
        if (!$feedbackId || !$status) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE feedbacks SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $feedbackId]);
        
        // Log activity
        logActivity($db, $userId, 'update_feedback_status', 'feedback', $feedbackId, [
            'status' => $status
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    }
    elseif ($action === 'get_feedback') {
        // Admin functionality to get feedback details
        if (!in_array($userRole, ['admin'])) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $feedbackId = $_GET['feedback_id'] ?? '';
        
        if (!$feedbackId) {
            echo json_encode(['success' => false, 'message' => 'Missing feedback ID']);
            exit;
        }
        
        $stmt = $db->prepare("
            SELECT f.*, 
                   sender.full_name as sender_name,
                   sender.username as sender_username,
                   p.title as project_title,
                   p.po_number as project_code,
                   GROUP_CONCAT(DISTINCT recipient.full_name SEPARATOR ', ') as recipients
            FROM feedbacks f
            LEFT JOIN users sender ON f.sender_id = sender.id
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
            LEFT JOIN users recipient ON fr.user_id = recipient.id
            WHERE f.id = ?
            GROUP BY f.id
        ");
        $stmt->execute([$feedbackId]);
        $feedback = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$feedback) {
            echo json_encode(['success' => false, 'message' => 'Feedback not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'feedback' => $feedback]);
    }
    elseif ($action === 'list_my_feedbacks') {
        // List feedbacks sent by or received by the current user
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $countStmt = $db->prepare("
            SELECT COUNT(DISTINCT f.id) as total
            FROM feedbacks f
            LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
            WHERE f.sender_id = ?
               OR fr.user_id = ?
               OR (f.send_to_admin = 1 AND ? = 1)
        ");
        $isAdmin = in_array($userRole, ['admin']) ? 1 : 0;
        $countStmt->execute([$userId, $userId, $isAdmin]);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = ceil($totalCount / $perPage);
        
        // Get paginated results
        $stmt = $db->prepare("
            SELECT DISTINCT f.id, f.content, f.status, f.created_at,
                   p.title as project_title,
                   GROUP_CONCAT(DISTINCT ru.full_name SEPARATOR ', ') as recipients
            FROM feedbacks f
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
            LEFT JOIN users ru ON fr.user_id = ru.id
            WHERE f.sender_id = ?
               OR fr.user_id = ?
               OR (f.send_to_admin = 1 AND ? = 1)
            GROUP BY f.id
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $userId, $isAdmin, $perPage, $offset]);
        $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true, 
            'feedbacks' => $feedbacks,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_count' => $totalCount,
                'total_pages' => $totalPages
            ]
        ]);
    }
    elseif ($action === 'get_user_feedback') {
        // User functionality to get their own feedback details
        $feedbackId = $_GET['feedback_id'] ?? '';
        
        if (!$feedbackId) {
            echo json_encode(['success' => false, 'message' => 'Missing feedback ID']);
            exit;
        }
        
        $isAdmin = in_array($userRole, ['admin']) ? 1 : 0;
        // Users can view feedback they sent, received, generic feedback for their projects, admin-targeted (for admin), and lead-targeted (for project lead).
        $stmt = $db->prepare("
            SELECT f.*, 
                   p.title as project_title,
                   p.po_number as project_code,
                   GROUP_CONCAT(DISTINCT recipient.full_name SEPARATOR ', ') as recipients
            FROM feedbacks f
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
            LEFT JOIN users recipient ON fr.user_id = recipient.id
            WHERE f.id = ?
              AND (
                    ? = 1
                    OR f.sender_id = ?
                    OR fr.user_id = ?
                    OR (f.send_to_admin = 1 AND ? = 1)
                    OR (f.send_to_lead = 1 AND p.project_lead_id = ?)
                    OR (
                        f.is_generic = 1
                        AND (
                            f.project_id IS NULL
                            OR p.project_lead_id = ?
                            OR EXISTS (
                                SELECT 1
                                FROM user_assignments ua
                                WHERE ua.project_id = f.project_id
                                  AND ua.user_id = ?
                                  AND (ua.is_removed IS NULL OR ua.is_removed = 0)
                            )
                        )
                    )
              )
            GROUP BY f.id
        ");
        $stmt->execute([$feedbackId, $isAdmin, $userId, $userId, $isAdmin, $userId, $userId, $userId]);
        $feedback = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$feedback) {
            echo json_encode(['success' => false, 'message' => 'Feedback not found or access denied']);
            exit;
        }
        
        echo json_encode(['success' => true, 'feedback' => $feedback]);
    }
    elseif ($action === 'delete_feedback') {
        // Admin functionality to delete feedback
        if (!in_array($userRole, ['admin'])) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $feedbackId = $_POST['feedback_id'] ?? '';
        
        if (!$feedbackId) {
            echo json_encode(['success' => false, 'message' => 'Missing feedback ID']);
            exit;
        }
        
        // Delete feedback recipients first
        $stmt = $db->prepare("DELETE FROM feedback_recipients WHERE feedback_id = ?");
        $stmt->execute([$feedbackId]);
        
        // Delete feedback
        $stmt = $db->prepare("DELETE FROM feedbacks WHERE id = ?");
        $stmt->execute([$feedbackId]);
        
        // Log activity
        logActivity($db, $userId, 'delete_feedback', 'feedback', $feedbackId, [
            'deleted_by' => $userId
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Feedback deleted successfully']);
    }
    elseif ($action === 'export') {
        // Admin functionality to export feedbacks
        if (!in_array($userRole, ['admin'])) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $format = $_POST['format'] ?? 'csv';
        $fields = $_POST['fields'] ?? ['sender', 'project', 'content', 'recipients', 'status', 'date'];
        
        // Build query with same filters as the main page
        $whereConditions = [];
        $params = [];
        
        if (!empty($_POST['project_id'])) {
            $whereConditions[] = "f.project_id = ?";
            $params[] = $_POST['project_id'];
        }
        
        if (!empty($_POST['user_id'])) {
            $whereConditions[] = "(f.sender_id = ? OR fr.user_id = ?)";
            $params[] = $_POST['user_id'];
            $params[] = $_POST['user_id'];
        }
        
        if (!empty($_POST['search'])) {
            // Escape LIKE special chars; use addcslashes for clarity over manual str_replace
            $searchEscaped = addcslashes($_POST['search'], '%_\\');
            $whereConditions[] = "(f.content LIKE ? OR f.subject LIKE ?)";
            $params[] = "%{$searchEscaped}%";
            $params[] = "%{$searchEscaped}%";
        }
        
        if (!empty($_POST['status'])) {
            $whereConditions[] = "f.status = ?";
            $params[] = $_POST['status'];
        }
        
        if (!empty($_POST['date_from'])) {
            $whereConditions[] = "DATE(f.created_at) >= ?";
            $params[] = $_POST['date_from'];
        }
        
        if (!empty($_POST['date_to'])) {
            $whereConditions[] = "DATE(f.created_at) <= ?";
            $params[] = $_POST['date_to'];
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        $query = "
            SELECT DISTINCT f.*, 
                   sender.full_name as sender_name,
                   sender.username as sender_username,
                   p.title as project_title,
                   p.po_number as project_code,
                   GROUP_CONCAT(DISTINCT recipient.full_name SEPARATOR ', ') as recipients
            FROM feedbacks f
            LEFT JOIN users sender ON f.sender_id = sender.id
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id
            LEFT JOIN users recipient ON fr.user_id = recipient.id
            $whereClause
            GROUP BY f.id
            ORDER BY f.created_at DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filename = 'feedbacks_export_' . date('Y-m-d_H-i-s');
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Header row
            $headers = [];
            if (in_array('sender', $fields)) $headers[] = 'Sender';
            if (in_array('project', $fields)) $headers[] = 'Project';
            if (in_array('content', $fields)) $headers[] = 'Content';
            if (in_array('recipients', $fields)) $headers[] = 'Recipients';
            if (in_array('status', $fields)) $headers[] = 'Status';
            if (in_array('date', $fields)) $headers[] = 'Date';
            
            fputcsv($output, $headers);
            
            // Data rows
            foreach ($feedbacks as $feedback) {
                $row = [];
                if (in_array('sender', $fields)) $row[] = $feedback['sender_name'] ?? 'Unknown';
                if (in_array('project', $fields)) $row[] = $feedback['project_title'] ? $feedback['project_title'] . ' (' . $feedback['project_code'] . ')' : 'General';
                if (in_array('content', $fields)) $row[] = strip_tags($feedback['content']);
                if (in_array('recipients', $fields)) $row[] = $feedback['recipients'] ?? '';
                if (in_array('status', $fields)) $row[] = ucfirst($feedback['status']);
                if (in_array('date', $fields)) $row[] = date('Y-m-d H:i:s', strtotime($feedback['created_at']));
                
                fputcsv($output, $row);
            }
            
            fclose($output);
        } else {
            // Excel format would require additional library
            echo json_encode(['success' => false, 'message' => 'Excel export not implemented yet']);
        }
        
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Feedback API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

