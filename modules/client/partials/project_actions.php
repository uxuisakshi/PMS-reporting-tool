<?php
/**
 * Digital Asset Actions Partial
 * 
 * Action buttons and navigation for individual project
 */

$assignedProjects = $assignedProjects ?? [];
$currentIndex = array_search($projectId, array_column($assignedProjects, 'id'));
$prevProject = null;
$nextProject = null;

if ($currentIndex !== false) {
    if ($currentIndex > 0) {
        $prevProject = $assignedProjects[$currentIndex - 1];
    }
    if ($currentIndex < count($assignedProjects) - 1) {
        $nextProject = $assignedProjects[$currentIndex + 1];
    }
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="section-title">
            <i class="fas fa-tools text-primary"></i>
            Digital Asset Actions
        </h2>
    </div>
</div>

<!-- Main Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="project-actions-grid">
            
            <!-- Export Reports -->
            <div class="action-group">
                <h4 class="action-group-title">
                    <i class="fas fa-download"></i>
                    Export Reports
                </h4>
                <div class="action-buttons">
                    <button type="button" data-project-export="pdf" class="btn btn-success action-btn">
                        <i class="fas fa-file-pdf"></i>
                        <span class="btn-text">
                            <strong>PDF Report</strong>
                            <small>Comprehensive digital asset analytics</small>
                        </span>
                    </button>
                    <button type="button" data-project-export="excel" class="btn btn-primary action-btn">
                        <i class="fas fa-file-excel"></i>
                        <span class="btn-text">
                            <strong>Excel Data</strong>
                            <small>Raw data for analysis</small>
                        </span>
                    </button>
                </div>
            </div>

            <!-- View Details -->
            <div class="action-group">
                <h4 class="action-group-title">
                    <i class="fas fa-eye"></i>
                    View Details
                </h4>
                <div class="action-buttons">
                          <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $projectId, (string) ($project['title'] ?? ''), (string) ($project['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" 
                       class="btn btn-info action-btn">
                        <i class="fas fa-project-diagram"></i>
                        <span class="btn-text">
                            <strong>Digital Asset Analytics</strong>
                                <small>Asset overview</small>
                        </span>
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/client/issues_overview.php?project_id=<?php echo $projectId; ?>" 
                       class="btn btn-warning action-btn">
                        <i class="fas fa-list-ul"></i>
                        <span class="btn-text">
                            <strong>Issue Summary</strong>
                                <small>Review visible issue counts</small>
                        </span>
                    </a>
                </div>
            </div>

            <!-- Navigation -->
            <div class="action-group">
                <h4 class="action-group-title">
                    <i class="fas fa-compass"></i>
                    Navigation
                </h4>
                <div class="action-buttons">
                          <a href="<?php echo $baseDir; ?>/client/dashboard" 
                       class="btn btn-secondary action-btn">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="btn-text">
                            <strong>Dashboard</strong>
                            <small>Return to analytics dashboard</small>
                        </span>
                    </a>
                    <a href="<?php echo $baseDir; ?>/modules/client/projects.php" 
                       class="btn btn-outline-secondary action-btn">
                        <i class="fas fa-folder-open"></i>
                        <span class="btn-text">
                            <strong>All Digital Assets</strong>
                            <small>Browse digital asset list</small>
                        </span>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Project Navigation -->
<?php if ($prevProject || $nextProject): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="project-navigation-card">
            <h4 class="nav-title">
                <i class="fas fa-arrows-alt-h text-primary"></i>
                Navigate Between Digital Assets
            </h4>
            
            <div class="project-nav-buttons">
                <?php if ($prevProject): ?>
                                         <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $prevProject['id'], (string) ($prevProject['title'] ?? ''), (string) ($prevProject['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" 
                   class="nav-project-btn prev-project">
                    <div class="nav-direction">
                        <i class="fas fa-chevron-left"></i>
                        <span>Previous</span>
                    </div>
                    <div class="nav-project-info">
                        <div class="nav-project-title"><?php echo htmlspecialchars($prevProject['title']); ?></div>
                        <div class="nav-project-meta">
                            <span class="badge bg-<?php 
                                echo $prevProject['status'] === 'completed' ? 'success' : 
                                     ($prevProject['status'] === 'in_progress' ? 'primary' : 'secondary');
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $prevProject['status'])); ?>
                            </span>
                        </div>
                    </div>
                </a>
                <?php else: ?>
                <div class="nav-project-btn disabled">
                    <div class="nav-direction">
                        <i class="fas fa-chevron-left"></i>
                        <span>Previous</span>
                    </div>
                    <div class="nav-project-info">
                        <div class="nav-project-title text-muted">No previous digital asset</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="nav-separator">
                    <span class="current-indicator">
                        <?php echo ($currentIndex + 1); ?> of <?php echo count($assignedProjects); ?>
                    </span>
                </div>

                <?php if ($nextProject): ?>
                                         <a href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $nextProject['id'], (string) ($nextProject['title'] ?? ''), (string) ($nextProject['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" 
                   class="nav-project-btn next-project">
                    <div class="nav-project-info text-end">
                        <div class="nav-project-title"><?php echo htmlspecialchars($nextProject['title']); ?></div>
                        <div class="nav-project-meta">
                            <span class="badge bg-<?php 
                                echo $nextProject['status'] === 'completed' ? 'success' : 
                                     ($nextProject['status'] === 'in_progress' ? 'primary' : 'secondary');
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $nextProject['status'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="nav-direction">
                        <span>Next</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                </a>
                <?php else: ?>
                <div class="nav-project-btn disabled">
                    <div class="nav-project-info text-end">
                        <div class="nav-project-title text-muted">No next digital asset</div>
                    </div>
                    <div class="nav-direction">
                        <span>Next</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.project-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 2rem;
}

.action-group {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.action-group-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.action-group-title i {
    opacity: 0.8;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.action-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    text-align: left;
    border-radius: 8px;
    transition: all 0.2s ease;
    text-decoration: none;
    border: 1px solid transparent;
}

.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    text-decoration: none;
}

.action-btn i {
    font-size: 1.25rem;
    opacity: 0.8;
    flex-shrink: 0;
}

.btn-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.btn-text strong {
    font-size: 0.95rem;
    line-height: 1.2;
}

.btn-text small {
    font-size: 0.8rem;
    opacity: 0.8;
    line-height: 1.2;
}

.project-navigation-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.nav-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 20px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.project-nav-buttons {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 20px;
    align-items: center;
}

.nav-project-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
}

.nav-project-btn:not(.disabled):hover {
    background: #e9ecef;
    border-color: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    text-decoration: none;
    color: inherit;
}

.nav-project-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.nav-direction {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 500;
}

.nav-project-info {
    flex: 1;
}

.nav-project-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
    line-height: 1.2;
}

.nav-project-meta {
    font-size: 0.8rem;
}

.nav-separator {
    text-align: center;
    padding: 0 16px;
}

.current-indicator {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

/* Responsive Design */
@media (max-width: 992px) {
    .project-actions-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .action-group {
        padding: 20px;
    }
}

@media (max-width: 768px) {
    .project-nav-buttons {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .nav-separator {
        order: -1;
        padding: 0;
    }
    
    .nav-project-btn {
        padding: 12px;
    }
    
    .nav-project-title {
        font-size: 0.9rem;
    }
    
    .action-btn {
        padding: 10px 12px;
    }
    
    .action-btn i {
        font-size: 1.1rem;
    }
}

@media (max-width: 576px) {
    .project-navigation-card,
    .action-group {
        padding: 16px;
    }
    
    .action-buttons {
        gap: 8px;
    }
    
    .btn-text strong {
        font-size: 0.9rem;
    }
    
    .btn-text small {
        font-size: 0.75rem;
    }
}
</style>