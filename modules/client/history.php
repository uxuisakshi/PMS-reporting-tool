<?php
/**
 * Client Export History
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

if ($_SESSION['role'] !== 'client' && !isset($_SESSION['client_id'])) {
    header('Location: /index.php');
    exit;
}

$baseDir = getBaseDir();
$pageTitle = 'Export History';

// Currently functioning as an empty/placeholder data loader as the background queue handles PDF creation.
$exports = []; 

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4 mb-5">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-history text-primary"></i> Export History</h2>
            <p class="text-muted">View and download your previously generated PDF and Excel analytics reports.</p>
        </div>
    </div>

    <!-- Export History Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Exports</h5>
            <a href="<?php echo $baseDir; ?>/client/dashboard" class="btn btn-sm btn-outline-primary">
                Return to Dashboard
            </a>
        </div>
        <div class="card-body">
            <?php if (!empty($exports)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Report Name</th>
                                <th>Format</th>
                                <th>Date Generated</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Data would loop here -->
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox mb-3" style="font-size: 3rem; opacity: 0.5;"></i>
                    <h4>No Exports Found</h4>
                    <p>You haven't requested any detailed reports yet. Use the Quick Actions on your dashboard to generate PDF or Excel data dumps.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
