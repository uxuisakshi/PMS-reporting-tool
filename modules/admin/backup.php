<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole(['admin']);

$backupManager = new BackupManager();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: backup.php');
        exit;
    }
    if (isset($_POST['create_backup'])) {
        $filename = $backupManager->createBackup();
        if ($filename) {
            $_SESSION['success'] = "Backup created successfully: $filename";
        } else {
            $_SESSION['error'] = "Failed to create backup";
        }
    } elseif (isset($_POST['restore_backup'])) {
        $filename = $_POST['backup_file'];
        if ($backupManager->restoreBackup($filename)) {
            $_SESSION['success'] = "Backup restored successfully";
        } else {
            $_SESSION['error'] = "Failed to restore backup";
        }
    }
    
    redirect($_SERVER['PHP_SELF']);
}

// Get backup list
$backups = $backupManager->getBackupList();

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid">
    <h2>Database Backup & Restore</h2>
    
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Warning:</strong> Backup and restore operations affect the entire database. 
        Always ensure you have a current backup before performing a restore.
    </div>
    
    <!-- Create Backup -->
    <div class="card mb-3">
        <div class="card-header">
            <h5>Create New Backup</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <p>Click the button below to create a complete backup of the database.</p>
                <button type="submit" name="create_backup" class="btn btn-primary">
                    <i class="fas fa-database"></i> Create Backup Now
                </button>
            </form>
        </div>
    </div>
    
    <!-- Restore Backup -->
    <div class="card mb-3">
        <div class="card-header">
            <h5>Restore from Backup</h5>
        </div>
        <div class="card-body">
            <?php if (empty($backups)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No backups available.
            </div>
            <?php else: ?>
            <form id="restoreBackupForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="restore_backup" value="1">
                <div class="mb-3">
                    <label>Select Backup File</label>
                    <select name="backup_file" class="form-select" required>
                        <?php foreach ($backups as $backup): ?>
                        <option value="<?php echo $backup['filename']; ?>">
                            <?php echo $backup['filename']; ?> 
                            (<?php echo round($backup['size'] / 1024, 2); ?> KB) - 
                            <?php echo $backup['modified']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Danger:</strong> Restoring will overwrite all current data. 
                    Make sure this is what you want to do!
                </div>
                <button type="button" class="btn btn-danger" 
                        onclick="confirmForm('restoreBackupForm', 'Are you sure you want to restore this backup? All current data will be lost!')">
                    <i class="fas fa-undo"></i> Restore Selected Backup
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Available Backups -->
    <div class="card">
        <div class="card-header">
            <h5>Available Backups</h5>
        </div>
        <div class="card-body">
            <?php if (empty($backups)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No backups available.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?php echo $backup['filename']; ?></td>
                            <td><?php echo round($backup['size'] / 1024, 2); ?> KB</td>
                            <td><?php echo $backup['modified']; ?></td>
                            <td>
                                <a href="/backups/<?php echo $backup['filename']; ?>" 
                                   class="btn btn-sm btn-success" download>
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Backup Information -->
    <div class="card mt-3">
        <div class="card-header">
            <h5>Backup Information</h5>
        </div>
        <div class="card-body">
            <ul>
                <li>Backups are stored in the <code>/backups/</code> directory</li>
                <li>Backup files are compressed using GZIP compression</li>
                <li>Only the last 30 backups are kept to save disk space</li>
                <li>Backup includes all tables and data</li>
                <li>Recommended to download important backups to external storage</li>
                <li>Automatic daily backups can be set up using cron jobs</li>
            </ul>
            
            <h6>Sample Cron Job for Automatic Daily Backup:</h6>
            <pre class="bg-light p-3 border rounded">
# Run daily at 2 AM
0 2 * * * php /path/to/project-management-system/backup_cron.php >> /var/log/pms_backup.log</pre>
            
            <h6>Backup Script (backup_cron.php):</h6>
            <pre class="bg-light p-3 border rounded">
&lt;?php
require_once 'config/database.php';
require_once 'includes/backup.php';

$backupManager = new BackupManager();
$backupManager->createBackup();
?&gt;</pre>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>