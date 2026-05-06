<?php
require_once __DIR__ . '/../config/settings.php';

class EmailSender {
    private $settings;
    private $smtpTimeout = 8;
    private $lastSmtpResponse = '';
    
    public function __construct() {
        $this->settings = include(__DIR__ . '/../config/settings.php');
    }
    
    public function send($to, $subject, $body, $isHtml = true) {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address: $to");
            return false;
        }

        // Normalize and sanitize fields
        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string)$subject));
        $fromEmail = filter_var($this->settings['mail_from'] ?? '', FILTER_VALIDATE_EMAIL);
        $fromName = trim(preg_replace('/[\r\n]+/', ' ', (string)($this->settings['mail_from_name'] ?? '')));
        if ($fromName === '') {
            $fromName = 'Project Management System';
        }

        if (!$fromEmail) {
            error_log("Invalid from email address in settings");
            return false;
        }

        // Prefer SMTP for shared hosting reliability.
        if ($this->isSmtpConfigured()) {
            try {
                return $this->sendViaSmtp($to, $subject, $body, $isHtml, $fromEmail, $fromName);
            } catch (Throwable $e) {
                error_log('SMTP send failed: ' . $e->getMessage());
                // When SMTP is explicitly configured, do not fall back to localhost mail().
                return false;
            }
        } else {
            error_log('EmailSender: SMTP not configured; falling back to mail()');
        }

        // Fallback to mail() if SMTP is unavailable.
        try {
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = $isHtml ? 'Content-type: text/html; charset=utf-8' : 'Content-type: text/plain; charset=utf-8';
            $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
            $headers[] = 'Reply-To: ' . $fromEmail;
            $headers[] = 'X-Mailer: PHP/' . phpversion();
            $headers[] = 'X-Priority: 3';
            $fullHeaders = implode("\r\n", $headers);
            $ok = mail($to, $subject, $body, $fullHeaders);
            if (!$ok) {
                $lastError = error_get_last();
                $lastMessage = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
                error_log('mail() send failed for ' . $to . ($lastMessage !== '' ? (': ' . $lastMessage) : ''));
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('Mail send failed: ' . $e->getMessage());
            return false;
        }
    }

    private function isSmtpConfigured() {
        $host = trim((string)($this->settings['smtp_host'] ?? ''));
        $username = trim((string)($this->settings['smtp_username'] ?? ''));
        $password = trim((string)($this->settings['smtp_password'] ?? ''));
        return $host !== '' && $username !== '' && $password !== '';
    }

    private function sendViaSmtp($to, $subject, $body, $isHtml, $fromEmail, $fromName) {
        $host = trim((string)$this->settings['smtp_host']);
        $port = (int)($this->settings['smtp_port'] ?? 587);
        $secure = strtolower(trim((string)($this->settings['smtp_secure'] ?? 'tls')));
        $authValue = $this->settings['smtp_auth'] ?? true;
        if (is_string($authValue)) {
            $auth = !in_array(strtolower(trim($authValue)), ['0', 'false', 'no', 'off'], true);
        } else {
            $auth = (bool)$authValue;
        }
        $username = trim((string)($this->settings['smtp_username'] ?? ''));
        $password = trim((string)($this->settings['smtp_password'] ?? ''));

        $remoteHost = ($secure === 'ssl') ? ('ssl://' . $host) : $host;
        $fp = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, $this->smtpTimeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new Exception('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($fp, $this->smtpTimeout);
        $this->expectCode($fp, [220]);

        $hostname = preg_replace('/[^a-zA-Z0-9\.\-]/', '', ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($hostname === '') $hostname = 'localhost';

        $this->sendCommand($fp, 'EHLO ' . $hostname, [250]);

        if ($secure === 'tls') {
            $this->sendCommand($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                throw new Exception('Unable to enable STARTTLS');
            }
            $this->sendCommand($fp, 'EHLO ' . $hostname, [250]);
        }

        if ($auth) {
            $authed = false;
            $lastAuthError = null;

            try {
                $code = $this->sendCommand($fp, 'AUTH LOGIN', [235, 334, 503]);
                if ($code === 235 || $code === 503) {
                    $authed = true;
                } else {
                    $code = $this->sendCommand($fp, base64_encode($username), [334, 235, 535]);
                    if ($code === 334) {
                        $code = $this->sendCommand($fp, base64_encode($password), [235, 334, 535]);
                    }
                    if ($code === 235) {
                        $authed = true;
                    } else {
                        throw new Exception('AUTH LOGIN rejected by server');
                    }
                }
            } catch (Exception $loginEx) {
                $lastAuthError = $loginEx;
            }

            if (!$authed) {
                // Some servers prefer AUTH PLAIN over LOGIN.
                // Cancel any half-open AUTH exchange before trying another mechanism.
                try { $this->sendCommand($fp, '*', [501, 503, 504, 535]); } catch (Exception $ignore) {}

                try {
                    $authPlain = base64_encode("\0" . $username . "\0" . $password);
                    $plainCode = $this->sendCommand($fp, 'AUTH PLAIN ' . $authPlain, [235, 334, 503]);
                    if ((int)$plainCode === 334) {
                        // Some SMTP servers return challenge 334 and expect payload in next frame.
                        $plainCode = $this->sendCommand($fp, $authPlain, [235, 334, 535]);
                    }
                    if ((int)$plainCode === 235 || (int)$plainCode === 503) {
                        $authed = true;
                    }
                } catch (Exception $plainEx) {
                    $msg = 'SMTP authentication failed';
                    if ($lastAuthError) {
                        $msg .= ' (LOGIN: ' . $lastAuthError->getMessage() . ')';
                    }
                    $msg .= ' (PLAIN: ' . $plainEx->getMessage() . ')';
                    throw new Exception($msg);
                }
            }
            if (!$authed) {
                $msg = 'SMTP authentication failed';
                if ($lastAuthError) {
                    $msg .= ': ' . $lastAuthError->getMessage();
                }
                throw new Exception($msg);
            }
        }

        $this->sendCommand($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        $this->sendCommand($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->sendCommand($fp, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $boundary = 'pms_mixed_' . md5(uniqid(time(), true));
        $relatedBoundary = 'pms_related_' . md5(uniqid(time(), true));

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'MIME-Version: 1.0';
        
        // Detect and prepare logo for embedding
        $logoPath = realpath(__DIR__ . '/../storage/SIS-Logo-3.png');
        $hasLogo = ($logoPath && file_exists($logoPath));

        if ($hasLogo) {
            $headers[] = 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"';
        } else {
            $headers[] = $isHtml ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
        }
        $headers[] = 'X-Mailer: PMS SMTP';

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", (string)$body);
        $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody); // dot-stuffing
        
        $emailContent = "";
        if ($hasLogo) {
            $emailContent .= "--" . $relatedBoundary . "\r\n";
            $emailContent .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $emailContent .= str_replace("\n", "\r\n", $normalizedBody) . "\r\n\r\n";
            
            // Attach Logo
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoFilename = basename($logoPath);
            $emailContent .= "--" . $relatedBoundary . "\r\n";
            $emailContent .= "Content-Type: image/png; name=\"" . $logoFilename . "\"\r\n";
            $emailContent .= "Content-Transfer-Encoding: base64\r\n";
            $emailContent .= "Content-ID: <company_logo>\r\n";
            $emailContent .= "Content-Disposition: inline; filename=\"" . $logoFilename . "\"\r\n\r\n";
            $emailContent .= chunk_split($logoData, 76, "\r\n") . "\r\n";
            $emailContent .= "--" . $relatedBoundary . "--\r\n";
        } else {
            $emailContent .= str_replace("\n", "\r\n", $normalizedBody) . "\r\n";
        }

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $emailContent . "\r\n.\r\n";
        fwrite($fp, $data);
        $this->expectCode($fp, [250]);

        $this->sendCommand($fp, 'QUIT', [221]);
        fclose($fp);
        return true;
    }

    private function sendCommand($fp, $command, $expectedCodes) {
        fwrite($fp, $command . "\r\n");
        return $this->expectCode($fp, $expectedCodes);
    }

    private function expectCode($fp, $expectedCodes) {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        $this->lastSmtpResponse = $response;

        if ($response === '') {
            $meta = @stream_get_meta_data($fp);
            if (is_array($meta) && !empty($meta['timed_out'])) {
                throw new Exception('SMTP read timed out');
            }
            throw new Exception('Empty SMTP response');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new Exception('SMTP error ' . $code . ': ' . trim($response));
        }
        return $code;
    }
    
    /**
     * Render a template within the base layout
     * 
     * @param string $templateName Name of the template in templates/email/
     * @param array $data Data to be passed to the template
     * @return string Rendered HTML
     */
    public function renderTemplate($templateName, $data = []) {
        $data['app_name'] = $this->settings['app_name'] ?? 'Project Management System';
        $data['company_name'] = $this->settings['company_name'] ?? 'Sakshi Infotech Solutions LLP';
        $data['app_url'] = $this->settings['app_url'] ?? '';
        $data['company_logo'] = $this->settings['company_logo'] ?? '';
        
        // Add camelCase aliases for templates
        $data['appName'] = $data['app_name'];
        $data['companyName'] = $data['company_name'];
        $data['appUrl'] = $data['app_url'];
        $data['companyLogo'] = $data['company_logo'];
        
        // Extract variables for the template
        extract($data);
        
        $templatePath = __DIR__ . '/../templates/email/' . $templateName . '.php';
        $baseLayoutPath = __DIR__ . '/../templates/email/base_layout.php';
        
        if (!file_exists($templatePath)) {
            error_log("Email template not found: $templatePath");
            return $data['content'] ?? '';
        }

        // Start buffering for template content
        ob_start();
        include $templatePath;
        $content = ob_get_clean();
        
        // Pass the rendered content and other vars to the base layout
        if (file_exists($baseLayoutPath)) {
            ob_start();
            $data['content'] = $content;
            // Subject might be overridden in $data
            $subject = $data['subject'] ?? ($data['header_subtitle'] ?? 'Notification');
            include $baseLayoutPath;
            return ob_get_clean();
        }
        
        return $content; // Fallback if base layout missing
    }
    
    public function sendWelcomeEmail($userEmail, $userName) {
        $subject = "Welcome to Project Management System";
        $headerSubtitle = "Your journey starts here";
        
        $body = $this->renderTemplate('welcome_email', [
            'userName' => $userName,
            'subject' => $subject,
            'header_subtitle' => $headerSubtitle,
            'appUrl' => $this->settings['app_url']
        ]);
        
        return $this->send($userEmail, $subject, $body, true);
    }
    
    public function sendAssignmentNotification($userEmail, $userName, $projectTitle, $role) {
        $subject = "New Assignment: $projectTitle";
        // Convert single project into array format for the improved template
        $projects = [[
            'title' => $projectTitle,
            'role_name' => ucfirst(str_replace('_', ' ', $role))
        ]];
        
        $body = $this->renderTemplate('assignment_notification', [
            'userName' => $userName,
            'projects' => $projects,
            'adminName' => 'System Administrator',
            'subject' => $subject,
            'header_subtitle' => 'New Project Access',
            'appUrl' => $this->settings['app_url']
        ]);
        
        return $this->send($userEmail, $subject, $body, true);
    }
    
    public function sendMentionNotification($userEmail, $userName, $mentionedBy, $message, $link) {
        $subject = "You were mentioned in a conversation";
        
        $body = $this->renderTemplate('mention_notification', [
            'userName' => $userName,
            'mentionedBy' => $mentionedBy,
            'message' => $message,
            'link' => $link,
            'subject' => $subject,
            'header_subtitle' => 'Collaboration Insight',
            'appUrl' => $this->settings['app_url']
        ]);
        
        return $this->send($userEmail, $subject, $body, true);
    }

    public function send2FAReminderEmail($userEmail, $userName) {
        $subject = "Security Update: Enable Two-Factor Authentication (2FA)";
        $appUrl = $this->settings['app_url'] ?? '';
        $profileUrl = rtrim($appUrl, '/') . '/modules/profile.php';
        
        $body = $this->renderTemplate('2fa_reminder', [
            'userName' => $userName,
            'profileUrl' => $profileUrl,
            'subject' => $subject,
            'header_subtitle' => 'Security First',
            'appUrl' => $appUrl
        ]);
        
        return $this->send($userEmail, $subject, $body, true);
    }
}
