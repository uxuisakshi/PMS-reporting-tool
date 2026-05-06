<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireRole(['admin']);

class BackupManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function createBackup() {
        $backupDir = __DIR__ . '/../backups/';
        if (!file_exists($backupDir)) {
            // Use secure permissions: 0750 (owner read/write/execute, group read/execute, others none)
            if (!mkdir($backupDir, 0750, true)) {
                throw new Exception("Failed to create backup directory");
            }
        }
        
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . $filename;
        
        // Get all tables
        $tables = [];
        $result = $this->db->query('SHOW TABLES');
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $output = '';
        
        foreach ($tables as $table) {
            // Validate table name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                continue; // Skip invalid table names
            }
            
            // Table structure
            $stmt = $this->db->prepare("SHOW CREATE TABLE `$table`");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_NUM);
            if ($row) {
                $output .= "\n\n" . $row[1] . ";\n\n";
            }
            
            // Table data
            $stmt = $this->db->prepare("SELECT * FROM `$table`");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $output .= "INSERT INTO `$table` VALUES(";
                $values = [];
                foreach ($row as $value) {
                    $values[] = $value === null ? 'NULL' : $this->db->quote($value);
                }
                $output .= implode(',', $values);
                $output .= ");\n";
            }
        }
        
        // Write to file
        if (file_put_contents($filepath, $output) === false) {
            throw new Exception("Failed to write backup file");
        }
        
        // Set secure file permissions
        chmod($filepath, 0640);
        
        // Compress the file
        $compressedPath = $this->compressBackup($filepath);
        
        // Keep only last 30 backups
        $this->cleanOldBackups($backupDir);
        
        return basename($compressedPath);
    }
    
    public function restoreBackup($filename) {
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        if (!preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql(\.gz)?$/', $filename)) {
            throw new Exception("Invalid backup filename format");
        }
        
        $filepath = __DIR__ . '/../backups/' . $filename;
        
        if (!file_exists($filepath) || !is_readable($filepath)) {
            throw new Exception("Backup file not found or not readable");
        }
        
        // Check if compressed
        if (pathinfo($filepath, PATHINFO_EXTENSION) === 'gz') {
            $filepath = $this->decompressBackup($filepath);
        }
        
        $sql = file_get_contents($filepath);
        if ($sql === false) {
            throw new Exception("Failed to read backup file");
        }
        
        try {
            $this->db->beginTransaction();
            
            // Disable foreign key checks
            $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
            
            // Split SQL by semicolon (but be careful with semicolons in strings)
            // Better approach: use a proper SQL parser or split more carefully
            $queries = array_filter(
                array_map('trim', explode(';', $sql)),
                function($query) {
                    return !empty($query) && !preg_match('/^\s*--/', $query);
                }
            );
            
            foreach ($queries as $query) {
                if (!empty(trim($query))) {
                    try {
                        $this->db->exec($query);
                    } catch (PDOException $e) {
                        // Log error but continue for non-critical errors
                        error_log("Restore error: " . $e->getMessage());
                        // Re-throw for critical errors
                        if (strpos($e->getMessage(), 'SQLSTATE') !== false) {
                            throw $e;
                        }
                    }
                }
            }
            
            // Enable foreign key checks
            $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            // Re-enable foreign key checks even on error
            try {
                $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (PDOException $e2) {
                error_log("Failed to re-enable foreign key checks: " . $e2->getMessage());
            }
            throw $e;
        }
    }
    
    private function compressBackup($filepath) {
        $compressed = $filepath . '.gz';
        
        $fp = gzopen($compressed, 'w9');
        if ($fp === false) {
            throw new Exception("Failed to create compressed backup file");
        }
        
        $content = file_get_contents($filepath);
        if ($content === false) {
            gzclose($fp);
            throw new Exception("Failed to read backup file for compression");
        }
        
        gzwrite($fp, $content);
        gzclose($fp);
        
        // Set secure file permissions
        chmod($compressed, 0640);
        
        // Delete original
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        return $compressed;
    }
    
    private function decompressBackup($filepath) {
        $decompressed = str_replace('.gz', '', $filepath);
        
        $fp = gzopen($filepath, 'r');
        $data = '';
        while (!gzeof($fp)) {
            $data .= gzread($fp, 4096);
        }
        gzclose($fp);
        
        file_put_contents($decompressed, $data);
        
        return $decompressed;
    }
    
    private function cleanOldBackups($backupDir, $keep = 30) {
        $files = glob($backupDir . 'backup_*.sql.gz');
        
        if (count($files) > $keep) {
            // Sort by modification time (newest first)
            usort($files, function($a, $b) {
                $timeA = filemtime($a);
                $timeB = filemtime($b);
                if ($timeA === false || $timeB === false) {
                    return 0;
                }
                return $timeB - $timeA;
            });
            
            // Remove old backups
            $filesToDelete = array_slice($files, $keep);
            foreach ($filesToDelete as $file) {
                if (is_file($file) && is_writable($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    public function getBackupList() {
        $backupDir = __DIR__ . '/../backups/';
        $files = glob($backupDir . 'backup_*.sql.gz');
        
        $backups = [];
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $fileSize = filesize($file);
                $fileTime = filemtime($file);
                
                if ($fileSize !== false && $fileTime !== false) {
                    $backups[] = [
                        'filename' => basename($file),
                        'size' => $fileSize,
                        'size_formatted' => $this->formatFileSize($fileSize),
                        'modified' => date('Y-m-d H:i:s', $fileTime)
                    ];
                }
            }
        }
        
        // Sort by modification time (newest first)
        usort($backups, function($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });
        
        return $backups;
    }
    
    private function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
