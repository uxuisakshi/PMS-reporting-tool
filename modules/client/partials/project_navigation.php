<?php
/**
 * Digital Asset Navigation Partial
 * 
 * Navigation breadcrumbs and digital asset switcher
 */
?>

<div class="row mb-3">
    <div class="col-12">
        <nav aria-label="Digital asset navigation" class="project-nav">
            <!-- Breadcrumb Navigation -->
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="<?php echo $baseDir; ?>/client/dashboard">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="<?php echo $baseDir; ?>/modules/client/projects.php">
                        <i class="fas fa-folder-open"></i> Digital Assets
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <i class="fas fa-chart-line"></i> <?php echo htmlspecialchars($project['title']); ?>
                </li>
            </ol>
            
            <!-- Digital Asset Switcher -->
            <div class="project-switcher">
                <label for="projectNavSelect" class="form-label small text-muted mb-1">Switch Digital Asset</label>
                <select id="projectNavSelect" class="form-select form-select-sm">
                    <option value="">Select a digital asset...</option>
                    <?php foreach ($assignedProjects as $proj): ?>
                        <option value="<?php echo htmlspecialchars(buildClientProjectUrl((int) $proj['id'], (string) ($proj['title'] ?? ''), (string) ($proj['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" 
                            <?php echo ((int) $proj['id'] === (int) $projectId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proj['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
<script nonce="<?php echo htmlspecialchars($cspNonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
document.getElementById('projectNavSelect').addEventListener('change', function() {
    var url = this.value;
    if (url) {
        window.location.href = url;
    }
});
</script>
            </div>
        </nav>
    </div>
</div>

<style>
.project-nav {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 16px 20px;
    border: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.breadcrumb {
    margin: 0;
    background: none;
    padding: 0;
    font-size: 0.9rem;
}

.breadcrumb-item a {
    color: #2563eb;
    text-decoration: none;
    transition: color 0.2s ease;
}

.breadcrumb-item a:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.breadcrumb-item.active {
    color: #6c757d;
    font-weight: 500;
}

.breadcrumb-item i {
    margin-right: 4px;
    font-size: 0.85rem;
    opacity: 0.8;
}

.project-switcher {
    min-width: 200px;
}

.project-switcher .form-label {
    font-weight: 600;
    margin-bottom: 4px;
}

.project-switcher .form-select {
    border-radius: 6px;
    border: 1px solid #ced4da;
    font-size: 0.875rem;
}

@media (max-width: 768px) {
    .project-nav {
        flex-direction: column;
        align-items: stretch;
        padding: 12px 16px;
    }
    
    .breadcrumb {
        font-size: 0.8rem;
    }
    
    .project-switcher {
        min-width: auto;
        width: 100%;
    }
}
</style>