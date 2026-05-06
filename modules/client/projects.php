<?php
/**
 * Client Projects Listing Page
 * 
 * Lists all assigned projects with navigation to individual project dashboards
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/ClientAccessControlManager.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Initialize access control
$accessControl = new ClientAccessControlManager();

// Get client user ID
$clientUserId = $userId;
if (in_array($userRole, ['admin']) && isset($_GET['client_id'])) {
    $clientUserId = intval($_GET['client_id']);
}

// Get assigned projects with statistics
$assignedProjects = $accessControl->getAssignedProjects($clientUserId);

// Set page title
$pageTitle = 'My Digital Assets';

// Ensure baseDir is set
if (!isset($baseDir)) {
    require_once __DIR__ . '/../../includes/helpers.php';
    $baseDir = getBaseDir();
}

// Handle flash messages
// picked up by header.php if needed
?>
<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid" id="main-content" tabindex="-1">

    
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="<?php echo $baseDir; ?>/client/dashboard">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">
                            <i class="fas fa-folder-open"></i> Digital Assets
                        </li>
                    </ol>
                </nav>
                
                <div class="header-content">
                    <h1 class="page-title">
                        <i class="fas fa-folder-open text-primary"></i>
                        My Digital Assets
                    </h1>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($assignedProjects)): ?>
    
    <div class="table-responsive projects-table-wrap">
        <table class="table table-hover align-middle projects-table mb-0">
            <thead>
                <tr>
                    <th>Digital Asset</th>
                    <th>Status</th>
                    <th class="text-end">Total Issues</th>
                    <th class="text-end">Open Issues</th>
                    <th class="text-end">Resolved</th>
                    <th class="text-end">Compliance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignedProjects as $project): 
                    $projectStats = $accessControl->getProjectStatistics($clientUserId, $project['id']);
                ?>
                <tr>
                    <td>
                        <div class="project-name-cell">
                            <div class="project-name"><?php echo htmlspecialchars($project['title']); ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-<?php 
                            echo $project['status'] === 'completed' ? 'success' : 
                                 ($project['status'] === 'in_progress' ? 'primary' : 'secondary');
                        ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                        </span>
                    </td>
                    <td class="text-end fw-semibold"><?php echo (int) ($projectStats['client_ready_issues'] ?? 0); ?></td>
                    <td class="text-end text-warning fw-semibold"><?php echo (int) ($projectStats['open_issues'] ?? 0); ?></td>
                    <td class="text-end text-success fw-semibold"><?php echo (int) ($projectStats['resolved_issues'] ?? 0); ?></td>
                    <td class="text-end text-info fw-semibold"><?php echo round((float) ($projectStats['compliance_score'] ?? 0), 1); ?>%</td>
                    <td>
                        <div class="project-actions">
                            <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $project['id'], (string) ($project['title'] ?? ''), (string) ($project['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-chart-line"></i> Analytics
                            </a>
                            <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $project['id'], (string) ($project['title'] ?? ''), (string) ($project['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-eye"></i> Overview
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php else: ?>
    
    <!-- No Digital Assets State -->
    <div class="row">
        <div class="col-12">
            <div class="no-projects-state">
                <div class="no-projects-icon">
                    <i class="fas fa-folder-open fa-4x text-muted"></i>
                </div>
                <h3>No Digital Assets Assigned</h3>
                <p class="text-muted">
                    No digital assets are available yet.
                </p>
                <a href="<?php echo $baseDir; ?>/client/dashboard" class="btn btn-primary">
                    <i class="fas fa-tachometer-alt"></i> Return to Dashboard
                </a>
            </div>
        </div>
    </div>
    
    <?php endif; ?>

</div>

<style>
.page-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e9ecef;
    margin-bottom: 2rem;
}

.breadcrumb {
    background: none;
    padding: 0;
    margin-bottom: 16px;
}

.breadcrumb-item a {
    color: #2563eb;
    text-decoration: none;
}

.breadcrumb-item a:hover {
    text-decoration: underline;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 8px;
}

.page-subtitle {
    color: #6c757d;
    font-size: 1.1rem;
    margin: 0;
}

.projects-table-wrap {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.projects-table thead th {
    background: #f8f9fa;
    color: #495057;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    border-bottom: 1px solid #dee2e6;
    padding: 14px 16px;
}

.projects-table tbody td {
    padding: 16px;
    border-color: #eef2f7;
    vertical-align: middle;
}

.projects-table tbody tr:hover {
    background: #f8fbff;
}

.project-name {
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
}

.project-meta {
    margin-top: 4px;
}

.project-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.project-actions .btn {
    flex: 1;
    font-size: 0.875rem;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 500;
}

.no-projects-state {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
}

.no-projects-icon {
    margin-bottom: 24px;
    opacity: 0.6;
}

.no-projects-state h3 {
    color: #2c3e50;
    margin-bottom: 16px;
}

.no-projects-state p {
    font-size: 1.1rem;
    margin-bottom: 24px;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

/* Responsive Design */
@media (max-width: 768px) {
    .page-header {
        padding: 20px;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .projects-table thead th,
    .projects-table tbody td {
        padding: 12px;
    }
    
    .project-actions {
        flex-direction: column;
    }
    
    .project-actions .btn {
        flex: none;
    }
}

@media (max-width: 576px) {
    .page-title {
        font-size: 1.25rem;
    }

    .project-name {
        font-size: 0.95rem;
    }
    
    .no-projects-state {
        padding: 40px 16px;
    }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>