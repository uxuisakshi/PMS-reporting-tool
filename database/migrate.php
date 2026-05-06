<?php
/**
 * Database Migration Tool
 * Allows running migrations and checking migration status
 */

// Simple password protection - set via environment variable
$MIGRATION_PASSWORD = getenv('MIGRATION_PASSWORD') ?: null;
if (!$MIGRATION_PASSWORD) {
    http_response_code(403);
    die('Migration tool is disabled. Set MIGRATION_PASSWORD environment variable to enable.');
}

session_start();

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $MIGRATION_PASSWORD) {
        $_SESSION['migrate_auth'] = true;
    } else {
        $error = "Invalid password!";
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['migrate_auth']);
    header('Location: migrate.php');
    exit;
}

// Check authentication
if (!isset($_SESSION['migrate_auth'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Database Migration Tool - Login</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 50px; }
            .login-box { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h2 { color: #333; margin-top: 0; }
            input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
            button:hover { background: #0056b3; }
            .error { color: #dc3545; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔒 Migration Tool Login</h2>
            <?php if (isset($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="password" autocomplete="off" placeholder="Enter password" required autofocus>
                <button type="submit" name="login">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Load database connection
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

// Create migrations tracking table if not exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `migration_history` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `migration_file` varchar(255) NOT NULL,
            `executed_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `executed_by` varchar(100) DEFAULT NULL,
            `status` enum('success','failed') DEFAULT 'success',
            `error_message` text DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `migration_file` (`migration_file`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {
    // Table might already exist, ignore error
}

// Handle migration execution
$migrationResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $migrationFile = $_POST['migration_file'];
    $migrationPath = __DIR__ . '/migrations/' . basename($migrationFile);
    
    if (file_exists($migrationPath)) {
        try {
            $db->beginTransaction();
            
            // Execute migration
            $sql = file_get_contents($migrationPath);
            $db->exec($sql);
            
            // Record in migration history
            $stmt = $db->prepare("
                INSERT INTO migration_history (migration_file, executed_by, status) 
                VALUES (?, 'admin', 'success')
                ON DUPLICATE KEY UPDATE 
                    executed_at = current_timestamp(),
                    executed_by = 'admin',
                    status = 'success',
                    error_message = NULL
            ");
            $stmt->execute([$migrationFile]);
            
            $db->commit();
            $migrationResult = ['success' => true, 'message' => "Migration executed successfully: $migrationFile"];
        } catch (PDOException $e) {
            $db->rollBack();
            
            // Record failure in migration history
            try {
                $stmt = $db->prepare("
                    INSERT INTO migration_history (migration_file, executed_by, status, error_message) 
                    VALUES (?, 'admin', 'failed', ?)
                    ON DUPLICATE KEY UPDATE 
                        executed_at = current_timestamp(),
                        executed_by = 'admin',
                        status = 'failed',
                        error_message = ?
                ");
                $stmt->execute([$migrationFile, $e->getMessage(), $e->getMessage()]);
            } catch (Exception $logError) {
                // Ignore logging error
            }
            
            $migrationResult = ['success' => false, 'message' => "Migration failed: " . $e->getMessage()];
        }
    } else {
        $migrationResult = ['success' => false, 'message' => "Migration file not found: $migrationFile"];
    }
}

// Get migration history
function getMigrationHistory($db) {
    try {
        $stmt = $db->query("
            SELECT migration_file, executed_at, executed_by, status, error_message 
            FROM migration_history 
            ORDER BY executed_at DESC
        ");
        $history = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $history[$row['migration_file']] = $row;
        }
        return $history;
    } catch (Exception $e) {
        return [];
    }
}

$migrationHistory = getMigrationHistory($db);

// Check migration status
function checkMigrationStatus($db) {
    $status = [];
    
    // Check if project_id column exists in client_permissions
    try {
        $result = $db->query("SHOW COLUMNS FROM client_permissions LIKE 'project_id'")->fetch();
        $status['project_id_column'] = $result ? true : false;
    } catch (Exception $e) {
        $status['project_id_column'] = false;
    }
    
    // Check if unique constraint is updated
    try {
        $result = $db->query("SHOW INDEXES FROM client_permissions WHERE Key_name = 'unique_project_user_permission'")->fetch();
        $status['unique_constraint_updated'] = $result ? true : false;
    } catch (Exception $e) {
        $status['unique_constraint_updated'] = false;
    }
    
    // Check if old client-level permissions exist
    try {
        $result = $db->query("SELECT COUNT(*) as count FROM client_permissions WHERE project_id IS NULL AND is_active = 0")->fetch();
        $status['old_permissions_count'] = $result['count'];
    } catch (Exception $e) {
        $status['old_permissions_count'] = 0;
    }
    
    // Check if new project-level permissions exist
    try {
        $result = $db->query("SELECT COUNT(*) as count FROM client_permissions WHERE project_id IS NOT NULL AND is_active = 1")->fetch();
        $status['new_permissions_count'] = $result['count'];
    } catch (Exception $e) {
        $status['new_permissions_count'] = 0;
    }
    
    // Check if client_permissions table exists
    try {
        $result = $db->query("SHOW TABLES LIKE 'client_permissions'")->fetch();
        $status['table_exists'] = $result ? true : false;
    } catch (Exception $e) {
        $status['table_exists'] = false;
    }
    
    return $status;
}

$migrationStatus = checkMigrationStatus($db);

// Get list of migration files
$migrationFiles = glob(__DIR__ . '/migrations/*.sql');
sort($migrationFiles);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration Tool</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-top: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .logout-btn { padding: 8px 16px; background: #dc3545; color: white; text-decoration: none; border-radius: 4px; }
        .logout-btn:hover { background: #c82333; }
        .status-card { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #007bff; }
        .status-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #dee2e6; }
        .status-item:last-child { border-bottom: none; }
        .status-label { font-weight: bold; color: #495057; }
        .status-value { color: #6c757d; }
        .status-value.success { color: #28a745; font-weight: bold; }
        .status-value.error { color: #dc3545; font-weight: bold; }
        .migration-list { margin-top: 20px; }
        .migration-item { background: #fff; border: 1px solid #dee2e6; padding: 15px; margin-bottom: 10px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; }
        .migration-name { font-weight: bold; color: #333; }
        .migration-desc { color: #6c757d; font-size: 14px; margin-top: 5px; }
        .run-btn { padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .run-btn:hover { background: #218838; }
        .run-btn:disabled { background: #6c757d; cursor: not-allowed; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning-box { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .warning-box h3 { margin-top: 0; color: #856404; }
        .info-box { background: #d1ecf1; border: 1px solid #17a2b8; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .info-box h3 { margin-top: 0; color: #0c5460; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6; }
        table th { background: #f8f9fa; font-weight: bold; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-success { background: #28a745; color: white; }
        .badge-danger { background: #dc3545; color: white; }
        .badge-warning { background: #ffc107; color: #212529; }
        .badge-info { background: #17a2b8; color: white; }
        .badge-secondary { background: #6c757d; color: white; }
        .migration-status { display: inline-block; margin-left: 10px; }
        .migration-executed { color: #28a745; font-size: 12px; }
        .migration-failed { color: #dc3545; font-size: 12px; }
        .migration-pending { color: #6c757d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Database Migration Tool</h1>
            <a href="?logout" class="logout-btn">Logout</a>
        </div>

        <?php if ($migrationResult): ?>
            <div class="alert alert-<?php echo $migrationResult['success'] ? 'success' : 'danger'; ?>">
                <?php echo htmlspecialchars($migrationResult['message']); ?>
            </div>
        <?php endif; ?>

        <div class="warning-box">
            <h3>⚠️ Important Warning</h3>
            <p><strong>Always backup your database before running migrations!</strong></p>
            <p>Migrations can modify your database structure and data. Make sure you have a recent backup.</p>
        </div>

        <div class="info-box">
            <h3>📊 Migration Status - Project-Level Permissions</h3>
            <table>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
                <tr>
                    <td>Client Permissions Table</td>
                    <td>
                        <?php if ($migrationStatus['table_exists']): ?>
                            <span class="badge badge-success">✓ Exists</span>
                        <?php else: ?>
                            <span class="badge badge-danger">✗ Missing</span>
                        <?php endif; ?>
                    </td>
                    <td>Base table for permissions</td>
                </tr>
                <tr>
                    <td>Project ID Column</td>
                    <td>
                        <?php if ($migrationStatus['project_id_column']): ?>
                            <span class="badge badge-success">✓ Added</span>
                        <?php else: ?>
                            <span class="badge badge-warning">⚠ Not Added</span>
                        <?php endif; ?>
                    </td>
                    <td>Required for project-level permissions</td>
                </tr>
                <tr>
                    <td>Unique Constraint</td>
                    <td>
                        <?php if ($migrationStatus['unique_constraint_updated']): ?>
                            <span class="badge badge-success">✓ Updated</span>
                        <?php else: ?>
                            <span class="badge badge-warning">⚠ Not Updated</span>
                        <?php endif; ?>
                    </td>
                    <td>Ensures unique project-user-permission combination</td>
                </tr>
                <tr>
                    <td>Old Client-Level Permissions</td>
                    <td>
                        <?php if ($migrationStatus['old_permissions_count'] > 0): ?>
                            <span class="badge badge-info"><?php echo $migrationStatus['old_permissions_count']; ?> found</span>
                        <?php else: ?>
                            <span class="badge badge-success">0 found</span>
                        <?php endif; ?>
                    </td>
                    <td>Deactivated permissions from old system</td>
                </tr>
                <tr>
                    <td>New Project-Level Permissions</td>
                    <td>
                        <?php if ($migrationStatus['new_permissions_count'] > 0): ?>
                            <span class="badge badge-success"><?php echo $migrationStatus['new_permissions_count']; ?> active</span>
                        <?php else: ?>
                            <span class="badge badge-warning">0 active</span>
                        <?php endif; ?>
                    </td>
                    <td>Active project-level permissions</td>
                </tr>
            </table>

            <?php 
            $migrationComplete = $migrationStatus['project_id_column'] && 
                                 $migrationStatus['unique_constraint_updated'];
            ?>
            
            <div style="margin-top: 20px; padding: 15px; background: <?php echo $migrationComplete ? '#d4edda' : '#fff3cd'; ?>; border-radius: 4px;">
                <?php if ($migrationComplete): ?>
                    <strong style="color: #155724;">✓ Migration Status: COMPLETE</strong>
                    <p style="margin: 5px 0 0 0; color: #155724;">Project-level permissions system is active and ready to use.</p>
                <?php else: ?>
                    <strong style="color: #856404;">⚠ Migration Status: INCOMPLETE</strong>
                    <p style="margin: 5px 0 0 0; color: #856404;">Please run the "convert_to_project_permissions.sql" migration below.</p>
                <?php endif; ?>
            </div>
        </div>

        <h2>Available Migrations</h2>
        <div class="migration-list">
            <?php if (empty($migrationFiles)): ?>
                <p>No migration files found in /database/migrations/</p>
            <?php else: ?>
                <?php 
                $executedCount = 0;
                $pendingCount = 0;
                $failedCount = 0;
                
                foreach ($migrationFiles as $file) {
                    $filename = basename($file);
                    if (isset($migrationHistory[$filename])) {
                        if ($migrationHistory[$filename]['status'] === 'success') {
                            $executedCount++;
                        } else {
                            $failedCount++;
                        }
                    } else {
                        $pendingCount++;
                    }
                }
                ?>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Migration Summary:</strong>
                    <span class="badge badge-success" style="margin-left: 10px;">✓ Executed: <?php echo $executedCount; ?></span>
                    <span class="badge badge-warning" style="margin-left: 5px;">⏳ Pending: <?php echo $pendingCount; ?></span>
                    <?php if ($failedCount > 0): ?>
                        <span class="badge badge-danger" style="margin-left: 5px;">✗ Failed: <?php echo $failedCount; ?></span>
                    <?php endif; ?>
                </div>
                
                <?php foreach ($migrationFiles as $file): 
                    $filename = basename($file);
                    $isProjectMigration = strpos($filename, 'convert_to_project_permissions') !== false;
                    $isExecuted = isset($migrationHistory[$filename]) && $migrationHistory[$filename]['status'] === 'success';
                    $isFailed = isset($migrationHistory[$filename]) && $migrationHistory[$filename]['status'] === 'failed';
                    $isPending = !isset($migrationHistory[$filename]);
                    
                    $borderColor = $isExecuted ? '#28a745' : ($isFailed ? '#dc3545' : ($isProjectMigration ? '#ffc107' : '#dee2e6'));
                ?>
                    <div class="migration-item" style="border-left: 4px solid <?php echo $borderColor; ?>; <?php echo $isExecuted ? 'opacity: 0.7;' : ''; ?>">
                        <div style="flex: 1;">
                            <div class="migration-name">
                                <?php echo htmlspecialchars($filename); ?>
                                
                                <?php if ($isExecuted): ?>
                                    <span class="badge badge-success">✓ Executed</span>
                                    <span class="migration-executed">
                                        on <?php echo date('M d, Y H:i', strtotime($migrationHistory[$filename]['executed_at'])); ?>
                                    </span>
                                <?php elseif ($isFailed): ?>
                                    <span class="badge badge-danger">✗ Failed</span>
                                    <span class="migration-failed">
                                        on <?php echo date('M d, Y H:i', strtotime($migrationHistory[$filename]['executed_at'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning">⏳ Pending</span>
                                    <?php if ($isProjectMigration): ?>
                                        <span class="badge badge-warning">IMPORTANT</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="migration-desc">
                                <?php 
                                // Read first comment line from SQL file
                                $firstLine = '';
                                $handle = fopen($file, 'r');
                                while (($line = fgets($handle)) !== false) {
                                    if (strpos(trim($line), '--') === 0) {
                                        $firstLine = trim(substr($line, 2));
                                        if (strpos($firstLine, 'Migration:') === 0) {
                                            $firstLine = trim(substr($firstLine, 10));
                                        }
                                        break;
                                    }
                                }
                                fclose($handle);
                                echo htmlspecialchars($firstLine ?: 'No description available');
                                ?>
                            </div>
                            <?php if ($isFailed && !empty($migrationHistory[$filename]['error_message'])): ?>
                                <div style="margin-top: 8px; padding: 8px; background: #f8d7da; border-radius: 4px; font-size: 12px; color: #721c24;">
                                    <strong>Error:</strong> <?php echo htmlspecialchars($migrationHistory[$filename]['error_message']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST" style="margin: 0;" onsubmit="return confirm('<?php echo $isExecuted ? 'This migration has already been executed. Are you sure you want to run it again?' : 'Are you sure you want to run this migration? Make sure you have a database backup!'; ?>');">
                            <input type="hidden" name="migration_file" value="<?php echo htmlspecialchars($filename); ?>">
                            <button type="submit" name="run_migration" class="run-btn" <?php echo $isExecuted && !$isFailed ? 'style="background: #6c757d;"' : ''; ?>>
                                <?php echo $isExecuted ? 'Re-run' : ($isFailed ? 'Retry' : 'Run Migration'); ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 4px; font-size: 14px; color: #6c757d;">
            <strong>Note:</strong> This tool is for development and testing. For production, use proper migration tools or run SQL files manually through phpMyAdmin.
        </div>

        <?php if (!empty($migrationHistory)): ?>
        <h2 style="margin-top: 40px;">Migration History</h2>
        <div style="background: white; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
            <table>
                <thead>
                    <tr>
                        <th>Migration File</th>
                        <th>Status</th>
                        <th>Executed At</th>
                        <th>Executed By</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($migrationHistory as $filename => $history): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($filename); ?></td>
                        <td>
                            <?php if ($history['status'] === 'success'): ?>
                                <span class="badge badge-success">✓ Success</span>
                            <?php else: ?>
                                <span class="badge badge-danger">✗ Failed</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M d, Y H:i:s', strtotime($history['executed_at'])); ?></td>
                        <td><?php echo htmlspecialchars($history['executed_by'] ?: 'Unknown'); ?></td>
                        <td>
                            <?php if ($history['status'] === 'failed' && !empty($history['error_message'])): ?>
                                <details>
                                    <summary style="cursor: pointer; color: #dc3545;">View Error</summary>
                                    <div style="margin-top: 8px; padding: 8px; background: #f8d7da; border-radius: 4px; font-size: 12px; color: #721c24; max-width: 400px; word-wrap: break-word;">
                                        <?php echo htmlspecialchars($history['error_message']); ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <span style="color: #28a745;">Executed successfully</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
