-- Login attempts table for persistent rate limiting (not session-based)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username_hash VARCHAR(64) NOT NULL DEFAULT '',
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_user_time (username_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
