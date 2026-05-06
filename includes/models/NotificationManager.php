<?php
/**
 * NotificationManager Class
 * 
 * Handles email notifications for client project assignments and revocations.
 * Integrates with the existing EmailSender infrastructure and provides
 * professional email templates with branding.
 * 
 * Requirements: 2.3, 19.1, 19.2, 19.3
 */

require_once __DIR__ . '/../email.php';

class NotificationManager {
    private $db;
    private $emailSender;
    private $settings;
    
    public function __construct($database = null) {
        global $db;
        $this->db = $database ?: $db;
        $this->emailSender = new EmailSender();
        $this->settings = include(__DIR__ . '/../../config/settings.php');
    }
    
    /**
     * Send project assignment notification to client
     * 
     * @param int $clientUserId Client user ID
     * @param array $projects Array of project data
     * @param int $adminUserId Admin who made the assignment
     * @return bool Success status
     */
    public function sendProjectAssignmentNotification($clientUserId, $projects, $adminUserId) {
        try {
            // Get client user details
            $clientData = $this->getClientUserData($clientUserId);
            if (!$clientData) {
                error_log("NotificationManager: Client user not found: $clientUserId");
                return false;
            }
            
            // Get admin user details
            $adminData = $this->getAdminUserData($adminUserId);
            if (!$adminData) {
                error_log("NotificationManager: Admin user not found: $adminUserId");
                return false;
            }
            
            // Generate email content
            $subject = $this->generateAssignmentSubject($projects);
            $body = $this->generateAssignmentEmailBody($clientData, $projects, $adminData);
            
            // Send email
            $success = $this->emailSender->send(
                $clientData['email'],
                $subject,
                $body,
                true // HTML format
            );
            
            // Log notification attempt
            $this->logNotification(
                $clientUserId,
                'project_assignment',
                $success,
                [
                    'project_count' => count($projects),
                    'project_ids' => array_column($projects, 'id'),
                    'admin_id' => $adminUserId
                ]
            );
            
            return $success;
            
        } catch (Exception $e) {
            error_log('NotificationManager sendProjectAssignmentNotification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send project revocation notification to client
     * 
     * @param int $clientUserId Client user ID
     * @param string $projectTitle Project title being revoked
     * @param int $adminUserId Admin who made the revocation
     * @param string $reason Optional reason for revocation
     * @return bool Success status
     */
    public function sendProjectRevocationNotification($clientUserId, $projectTitle, $adminUserId, $reason = null) {
        try {
            // Get client user details
            $clientData = $this->getClientUserData($clientUserId);
            if (!$clientData) {
                error_log("NotificationManager: Client user not found: $clientUserId");
                return false;
            }
            
            // Get admin user details
            $adminData = $this->getAdminUserData($adminUserId);
            if (!$adminData) {
                error_log("NotificationManager: Admin user not found: $adminUserId");
                return false;
            }
            
            // Generate email content
            $subject = "Project Access Revoked: " . $projectTitle;
            $body = $this->generateRevocationEmailBody($clientData, $projectTitle, $adminData, $reason);
            
            // Send email
            $success = $this->emailSender->send(
                $clientData['email'],
                $subject,
                $body,
                true // HTML format
            );
            
            // Log notification attempt
            $this->logNotification(
                $clientUserId,
                'project_revocation',
                $success,
                [
                    'project_title' => $projectTitle,
                    'admin_id' => $adminUserId,
                    'reason' => $reason
                ]
            );
            
            return $success;
            
        } catch (Exception $e) {
            error_log('NotificationManager sendProjectRevocationNotification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send periodic summary email with key accessibility metrics
     * 
     * @param int $clientUserId Client user ID
     * @param array $summaryData Analytics summary data
     * @return bool Success status
     */
    public function sendPeriodicSummaryEmail($clientUserId, $summaryData) {
        try {
            // Get client user details
            $clientData = $this->getClientUserData($clientUserId);
            if (!$clientData) {
                error_log("NotificationManager: Client user not found: $clientUserId");
                return false;
            }
            
            // Check if client has opted out of summary emails
            if (!$this->isClientOptedInForSummaries($clientUserId)) {
                return true; // Not an error, just skipped
            }
            
            // Generate email content
            $subject = "Weekly Accessibility Report Summary";
            $body = $this->generateSummaryEmailBody($clientData, $summaryData);
            
            // Send email
            $success = $this->emailSender->send(
                $clientData['email'],
                $subject,
                $body,
                true // HTML format
            );
            
            // Log notification attempt
            $this->logNotification(
                $clientUserId,
                'periodic_summary',
                $success,
                [
                    'summary_period' => $summaryData['period'] ?? 'weekly',
                    'project_count' => count($summaryData['projects'] ?? [])
                ]
            );
            
            return $success;
            
        } catch (Exception $e) {
            error_log('NotificationManager sendPeriodicSummaryEmail error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client user data for notifications
     * 
     * @param int $clientUserId Client user ID
     * @return array|null Client data or null if not found
     */
    private function getClientUserData($clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, full_name, is_active
                FROM users 
                WHERE id = ? AND role = 'client' AND is_active = 1
            ");
            $stmt->execute([$clientUserId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('NotificationManager getClientUserData error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get admin user data for notifications
     * 
     * @param int $adminUserId Admin user ID
     * @return array|null Admin data or null if not found
     */
    private function getAdminUserData($adminUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, full_name
                FROM users 
                WHERE id = ? AND role IN ('admin') AND is_active = 1
            ");
            $stmt->execute([$adminUserId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('NotificationManager getAdminUserData error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate subject line for assignment notification
     * 
     * @param array $projects Array of project data
     * @return string Email subject
     */
    private function generateAssignmentSubject($projects) {
        $count = count($projects);
        if ($count === 1) {
            return "New Project Access Granted: " . $projects[0]['title'];
        } else {
            return "New Project Access Granted: $count Projects";
        }
    }
    
    /**
     * Generate HTML email body for project assignment notification
     * 
     * @param array $clientData Client user data
     * @param array $projects Array of project data
     * @param array $adminData Admin user data
     * @return string HTML email body
     */
    private function generateAssignmentEmailBody($clientData, $projects, $adminData) {
        return $this->emailSender->renderTemplate('assignment_notification', [
            'userName' => ($clientData['full_name'] ?: $clientData['username']),
            'projects' => $projects,
            'adminName' => ($adminData['full_name'] ?: $adminData['username']),
            'header_subtitle' => 'New Project Access Granted'
        ]);
    }
    
    /**
     * Generate HTML email body for project revocation notification
     * 
     * @param array $clientData Client user data
     * @param string $projectTitle Project title being revoked
     * @param array $adminData Admin user data
     * @param string|null $reason Optional reason for revocation
     * @return string HTML email body
     */
    private function generateRevocationEmailBody($clientData, $projectTitle, $adminData, $reason = null) {
        return $this->emailSender->renderTemplate('revocation_notification', [
            'userName' => ($clientData['full_name'] ?: $clientData['username']),
            'projectTitle' => $projectTitle,
            'adminName' => ($adminData['full_name'] ?: $adminData['username']),
            'reason' => $reason,
            'header_subtitle' => 'Project Access Updated'
        ]);
    }
    
    /**
     * Generate HTML email body for periodic summary notification
     * 
     * @param array $clientData Client user data
     * @param array $summaryData Analytics summary data
     * @return string HTML email body
     */
    private function generateSummaryEmailBody($clientData, $summaryData) {
        return $this->emailSender->renderTemplate('periodic_summary', [
            'userName' => ($clientData['full_name'] ?: $clientData['username']),
            'summaryData' => $summaryData,
            'header_subtitle' => 'Period Analytics Insight'
        ]);
    }
    
    /**
     * Check if client is opted in for summary emails
     * 
     * @param int $clientUserId Client user ID
     * @return bool True if opted in, false otherwise
     */
    private function isClientOptedInForSummaries($clientUserId) {
        try {
            // Check for user preference in database
            // For now, default to true (opted in) unless explicitly opted out
            $stmt = $this->db->prepare("
                SELECT meta_value 
                FROM user_meta 
                WHERE user_id = ? AND meta_key = 'email_summary_opt_out'
            ");
            $stmt->execute([$clientUserId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no preference set or not opted out, default to opted in
            return !($result && $result['meta_value'] === '1');
            
        } catch (Exception $e) {
            // If table doesn't exist or error occurs, default to opted in
            return true;
        }
    }
    
    /**
     * Log notification attempt for audit trail
     * 
     * @param int $clientUserId Client user ID
     * @param string $notificationType Type of notification
     * @param bool $success Whether notification was successful
     * @param array $details Additional details
     */
    private function logNotification($clientUserId, $notificationType, $success, $details = []) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO client_audit_log (
                    client_user_id, 
                    action_type, 
                    resource_type, 
                    action_details, 
                    success, 
                    error_message,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $actionDetails = json_encode(array_merge($details, [
                'notification_type' => $notificationType,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            $errorMessage = $success ? null : 'Email delivery failed';
            
            $stmt->execute([
                $clientUserId,
                'email_notification',
                'notification',
                $actionDetails,
                $success ? 1 : 0,
                $errorMessage
            ]);
            
        } catch (Exception $e) {
            // Don't fail the main operation if logging fails
            error_log('NotificationManager logNotification error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update client communication preferences
     * 
     * @param int $clientUserId Client user ID
     * @param array $preferences Preference settings
     * @return bool Success status
     */
    public function updateCommunicationPreferences($clientUserId, $preferences) {
        try {
            $this->db->beginTransaction();
            
            foreach ($preferences as $key => $value) {
                $metaKey = 'email_' . $key;
                
                // Check if preference already exists
                $stmt = $this->db->prepare("
                    SELECT id FROM user_meta 
                    WHERE user_id = ? AND meta_key = ?
                ");
                $stmt->execute([$clientUserId, $metaKey]);
                
                if ($stmt->fetch()) {
                    // Update existing preference
                    $updateStmt = $this->db->prepare("
                        UPDATE user_meta 
                        SET meta_value = ?, updated_at = NOW() 
                        WHERE user_id = ? AND meta_key = ?
                    ");
                    $updateStmt->execute([$value, $clientUserId, $metaKey]);
                } else {
                    // Insert new preference
                    $insertStmt = $this->db->prepare("
                        INSERT INTO user_meta (user_id, meta_key, meta_value, created_at, updated_at) 
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $insertStmt->execute([$clientUserId, $metaKey, $value]);
                }
            }
            
            $this->db->commit();
            
            // Log preference update
            $this->logNotification(
                $clientUserId,
                'preference_update',
                true,
                ['preferences' => $preferences]
            );
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('NotificationManager updateCommunicationPreferences error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client communication preferences
     * 
     * @param int $clientUserId Client user ID
     * @return array Preference settings
     */
    public function getCommunicationPreferences($clientUserId) {
        try {
            $stmt = $this->db->prepare("
                SELECT meta_key, meta_value 
                FROM user_meta 
                WHERE user_id = ? AND meta_key LIKE 'email_%'
            ");
            $stmt->execute([$clientUserId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $preferences = [
                'summary_opt_out' => false,
                'assignment_notifications' => true,
                'revocation_notifications' => true
            ];
            
            foreach ($results as $row) {
                $key = str_replace('email_', '', $row['meta_key']);
                $preferences[$key] = $row['meta_value'] === '1';
            }
            
            return $preferences;
            
        } catch (Exception $e) {
            error_log('NotificationManager getCommunicationPreferences error: ' . $e->getMessage());
            // Return default preferences on error
            return [
                'summary_opt_out' => false,
                'assignment_notifications' => true,
                'revocation_notifications' => true
            ];
        }
    }
    
    /**
     * Test email configuration and connectivity
     * 
     * @return array Test results
     */
    public function testEmailConfiguration() {
        try {
            $testEmail = $this->settings['mail_from'] ?? 'test@example.com';
            $testSubject = 'Email Configuration Test - ' . date('Y-m-d H:i:s');
            $testBody = 'This is a test email to verify the notification system configuration.';
            
            $success = $this->emailSender->send($testEmail, $testSubject, $testBody, false);
            
            return [
                'success' => $success,
                'message' => $success ? 'Email configuration test successful' : 'Email configuration test failed',
                'smtp_configured' => $this->emailSender->isSmtpConfigured ?? false,
                'test_timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Email configuration test failed: ' . $e->getMessage(),
                'smtp_configured' => false,
                'test_timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}
