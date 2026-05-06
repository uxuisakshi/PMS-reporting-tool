<?php
/**
 * SecurityValidator
 * Comprehensive input validation and XSS protection for client interfaces
 * Implements SQL injection prevention and security best practices
 */

class SecurityValidator {
    private $auditLogger;
    
    // Common XSS patterns
    private $xssPatterns = [
        '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
        '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/mi',
        '/javascript:/i',
        '/vbscript:/i',
        '/onload\s*=/i',
        '/onerror\s*=/i',
        '/onclick\s*=/i',
        '/onmouseover\s*=/i',
        '/<object\b[^<]*(?:(?!<\/object>)<[^<]*)*<\/object>/mi',
        '/<embed\b[^<]*(?:(?!<\/embed>)<[^<]*)*<\/embed>/mi'
    ];
    
    // SQL injection patterns
    private $sqlPatterns = [
        '/(\s|^)(union|select|insert|update|delete|drop|create|alter|exec|execute)\s/i',
        '/(\s|^)(or|and)\s+\d+\s*=\s*\d+/i',
        '/(\s|^)(or|and)\s+[\'"].*[\'"]?\s*=\s*[\'"].*[\'"]?/i',
        '/--\s*$/m',
        '/\/\*.*\*\//s',
        '/;\s*(drop|delete|update|insert|create|alter)/i'
    ];
    
    public function __construct($auditLogger = null) {
        $this->auditLogger = $auditLogger;
    }
    
    /**
     * Validate and sanitize input data
     */
    public function validateInput($data, $rules = []) {
        $errors = [];
        $sanitized = [];
        
        foreach ($data as $field => $value) {
            $fieldRules = $rules[$field] ?? [];
            $result = $this->validateField($field, $value, $fieldRules);
            
            if ($result['valid']) {
                $sanitized[$field] = $result['value'];
            } else {
                $errors[$field] = $result['errors'];
            }
        }
        
        return [
            'valid' => empty($errors),
            'data' => $sanitized,
            'errors' => $errors
        ];
    }
    
    /**
     * Validate individual field
     */
    private function validateField($field, $value, $rules) {
        $errors = [];
        $sanitizedValue = $value;
        
        // Required validation
        if (isset($rules['required']) && $rules['required'] && empty($value)) {
            $errors[] = "$field is required";
            return ['valid' => false, 'errors' => $errors, 'value' => null];
        }
        
        // Skip further validation if empty and not required
        if (empty($value) && (!isset($rules['required']) || !$rules['required'])) {
            return ['valid' => true, 'errors' => [], 'value' => ''];
        }
        
        // Type validation
        if (isset($rules['type'])) {
            $typeResult = $this->validateType($value, $rules['type']);
            if (!$typeResult['valid']) {
                $errors = array_merge($errors, $typeResult['errors']);
            } else {
                $sanitizedValue = $typeResult['value'];
            }
        }
        
        // Length validation
        if (isset($rules['max_length']) && strlen($sanitizedValue) > $rules['max_length']) {
            $errors[] = "$field must not exceed {$rules['max_length']} characters";
        }
        
        if (isset($rules['min_length']) && strlen($sanitizedValue) < $rules['min_length']) {
            $errors[] = "$field must be at least {$rules['min_length']} characters";
        }
        
        // Pattern validation
        if (isset($rules['pattern']) && !preg_match($rules['pattern'], $sanitizedValue)) {
            $errors[] = "$field format is invalid";
        }
        
        // XSS protection
        if ($this->detectXSS($sanitizedValue)) {
            $errors[] = "$field contains potentially malicious content";
            $this->logSecurityViolation('xss_attempt', $field, $value);
        }
        
        // SQL injection protection
        if ($this->detectSQLInjection($sanitizedValue)) {
            $errors[] = "$field contains potentially malicious SQL";
            $this->logSecurityViolation('sql_injection_attempt', $field, $value);
        }
        
        // Custom validation
        if (isset($rules['custom']) && is_callable($rules['custom'])) {
            $customResult = $rules['custom']($sanitizedValue);
            if ($customResult !== true) {
                $errors[] = is_string($customResult) ? $customResult : "$field failed custom validation";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'value' => $sanitizedValue
        ];
    }
    
    /**
     * Validate data type
     */
    private function validateType($value, $type) {
        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false, 'errors' => ['Invalid email format'], 'value' => $value];
                }
                return ['valid' => true, 'errors' => [], 'value' => filter_var($value, FILTER_SANITIZE_EMAIL)];
                
            case 'int':
                if (!filter_var($value, FILTER_VALIDATE_INT)) {
                    return ['valid' => false, 'errors' => ['Must be a valid integer'], 'value' => $value];
                }
                return ['valid' => true, 'errors' => [], 'value' => (int)$value];
                
            case 'float':
                if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
                    return ['valid' => false, 'errors' => ['Must be a valid number'], 'value' => $value];
                }
                return ['valid' => true, 'errors' => [], 'value' => (float)$value];
                
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return ['valid' => false, 'errors' => ['Invalid URL format'], 'value' => $value];
                }
                return ['valid' => true, 'errors' => [], 'value' => filter_var($value, FILTER_SANITIZE_URL)];
                
            case 'string':
                return ['valid' => true, 'errors' => [], 'value' => $this->sanitizeString($value)];
                
            case 'html':
                return ['valid' => true, 'errors' => [], 'value' => $this->sanitizeHTML($value)];
                
            default:
                return ['valid' => true, 'errors' => [], 'value' => $value];
        }
    }
    
    /**
     * Detect XSS attempts
     */
    public function detectXSS($input) {
        foreach ($this->xssPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        // Check for encoded XSS attempts
        $decoded = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $input) {
            foreach ($this->xssPatterns as $pattern) {
                if (preg_match($pattern, $decoded)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Detect SQL injection attempts
     */
    public function detectSQLInjection($input) {
        foreach ($this->sqlPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Sanitize string input
     */
    public function sanitizeString($input) {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Convert special characters to HTML entities
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $input;
    }
    
    /**
     * Sanitize HTML input (for rich text fields)
     */
    public function sanitizeHTML($input) {
        // Allow only safe HTML tags
        $allowedTags = '<p><br><strong><em><u><ol><ul><li><h1><h2><h3><h4><h5><h6><blockquote>';
        
        // Strip dangerous tags
        $input = strip_tags($input, $allowedTags);
        
        // Remove dangerous attributes
        $input = preg_replace('/(<[^>]+)\s+(on\w+|style|class|id)\s*=\s*["\'][^"\']*["\']([^>]*>)/i', '$1$3', $input);
        
        return $input;
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCSRFToken($token, $sessionToken) {
        if (!$token || !$sessionToken) {
            $this->logSecurityViolation('csrf_missing', 'csrf_token', '');
            return false;
        }
        
        if (!hash_equals($sessionToken, $token)) {
            $this->logSecurityViolation('csrf_mismatch', 'csrf_token', $token);
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }
    
    /**
     * Validate file upload
     */
    public function validateFileUpload($file, $allowedTypes = [], $maxSize = 5242880) { // 5MB default
        $errors = [];
        
        if (!isset($file['error']) || is_array($file['error'])) {
            $errors[] = 'Invalid file upload';
            return ['valid' => false, 'errors' => $errors];
        }
        
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = 'No file was uploaded';
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = 'File exceeds maximum size limit';
                break;
            default:
                $errors[] = 'Unknown file upload error';
                break;
        }
        
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds limit';
        }
        
        // Check file type
        if (!empty($allowedTypes)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            
            if (!in_array($mimeType, $allowedTypes)) {
                $errors[] = 'File type not allowed';
                $this->logSecurityViolation('invalid_file_type', 'file_upload', $mimeType);
            }
        }
        
        // Check for executable files
        $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'exe', 'bat', 'cmd', 'sh', 'js'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($extension, $dangerousExtensions)) {
            $errors[] = 'File type not allowed for security reasons';
            $this->logSecurityViolation('dangerous_file_upload', 'file_upload', $extension);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Log security violation
     */
    private function logSecurityViolation($type, $field, $value) {
        if ($this->auditLogger) {
            $details = [
                'violation_type' => $type,
                'field' => $field,
                'value_hash' => hash('sha256', $value),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];
            
            $this->auditLogger->logSecurityViolation(
                $_SESSION['client_user_id'] ?? 0,
                $type,
                json_encode($details),
                'high'
            );
        }
    }
    
    /**
     * Rate limiting check
     */
    public function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 300) { // 5 attempts per 5 minutes
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $key = "rate_limit_$identifier";
        $now = time();
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        // Clean old attempts
        $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        // Check if limit exceeded
        if (count($_SESSION[$key]) >= $maxAttempts) {
            $this->logSecurityViolation('rate_limit_exceeded', $identifier, count($_SESSION[$key]));
            return false;
        }
        
        // Add current attempt
        $_SESSION[$key][] = $now;
        return true;
    }
}
