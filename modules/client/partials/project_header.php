<?php
/**
 * Digital Asset Header Partial
 * 
 * Project title, metadata, and action buttons
 */

// Get project metadata
$projectMeta = [
    'created_at' => $project['created_at'] ?? null,
    'status' => $project['status'] ?? 'active',
    'client_name' => $project['client_name'] ?? 'Unknown Client',
    'last_updated' => $project['updated_at'] ?? $project['created_at']
];

// Get project statistics
$projectStats = $projectAnalytics['project_statistics'] ?? [];
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="project-header-section">
            <div class="row align-items-center">
                
                <!-- Project Title and Meta -->
                <div class="col-lg-8">
                    <div class="project-title-area">
                        <h1 class="project-title">
                            <i class="fas fa-project-diagram text-primary"></i>
                            <?php echo htmlspecialchars($project['title']); ?>
                        </h1>
                        
                        <div class="project-meta">
                            <?php if (($_SESSION['role'] ?? '') !== 'client'): ?>
                             <div class="meta-item">
                                 <i class="fas fa-building text-muted"></i>
                                 <span class="meta-label">Client:</span>
                                 <span class="meta-value"><?php echo htmlspecialchars($projectMeta['client_name']); ?></span>
                             </div>
                            <?php endif; ?>
                            
                            <div class="meta-item">
                                <i class="fas fa-info-circle text-muted"></i>
                                <span class="meta-label">Status:</span>
                                <span class="badge bg-<?php 
                                    echo $projectMeta['status'] === 'completed' ? 'success' : 
                                         ($projectMeta['status'] === 'in_progress' ? 'primary' : 'secondary');
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $projectMeta['status'])); ?>
                                </span>
                            </div>
                            
                            <div class="meta-item">
                                <i class="fas fa-calendar text-muted"></i>
                                <span class="meta-label">Last Updated:</span>
                                <span class="meta-value">
                                    <?php echo $projectMeta['last_updated'] ? date('M j, Y', strtotime($projectMeta['last_updated'])) : 'N/A'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if (!empty($project['description'])): ?>
                        <div class="project-description">
                            <p class="text-muted"><?php echo htmlspecialchars($project['description']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="col-lg-4">
                    <div class="project-actions text-lg-end">
                        <div class="btn-group-vertical btn-group-sm d-lg-none mb-3">
                            <button type="button" class="btn btn-success" data-project-export="pdf">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            <button type="button" class="btn btn-primary" data-project-export="excel">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                        </div>
                        
                        <div class="btn-group d-none d-lg-flex" role="group">
                            <button type="button" class="btn btn-success btn-sm" data-project-export="pdf">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" data-project-export="excel">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                        
                        <div class="mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-project-refresh="1">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
</div>

<style>
.project-header-section {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.project-title {
    font-size: 2.25rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 16px;
    line-height: 1.2;
}

.project-title i {
    margin-right: 12px;
    opacity: 0.8;
}

.project-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 12px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
}

.meta-item i {
    font-size: 0.8rem;
    opacity: 0.7;
}

.meta-label {
    font-weight: 500;
    color: #6c757d;
}

.meta-value {
    color: #495057;
    font-weight: 500;
}

.project-description {
    margin-top: 12px;
}

.project-description p {
    font-size: 1rem;
    line-height: 1.5;
    margin: 0;
}

.project-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.project-actions .btn {
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.project-actions .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

@media (max-width: 992px) {
    .project-header-section {
        padding: 20px;
    }
    
    .project-title {
        font-size: 1.75rem;
        margin-bottom: 12px;
    }
    
    .project-meta {
        gap: 16px;
    }
    
    .project-actions {
        margin-top: 16px;
    }
}

@media (max-width: 768px) {
    .project-header-section {
        padding: 16px;
    }
    
    .project-title {
        font-size: 1.5rem;
    }
    
    .project-meta {
        flex-direction: column;
        gap: 8px;
    }
    
    .meta-item {
        font-size: 0.85rem;
    }
}

@media (max-width: 576px) {
    .project-title {
        font-size: 1.25rem;
    }
    
    .project-title i {
        margin-right: 8px;
    }
}
</style>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window._projectHeaderConfig = {
    projectId: <?php echo json_encode((int)$projectId); ?>,
    clientId: <?php echo json_encode((int)($project['client_id'] ?? 0)); ?>,
    baseDir: <?php echo json_encode($baseDir, JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/client-project-header.js"></script>