<?php
/**
 * No Assigned Assets Template
 * Displayed when no digital assets are assigned
 */

$clientUser = $clientUser ?? [];
$baseDir = $baseDir ?? '';

$pageTitle = "No Digital Assets Assigned";
require_once __DIR__ . '/../../header.php';
?>

<div class="container py-5">
    <div class="client-no-projects">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-5">
                    <div class="mb-4">
                        <i class="fas fa-project-diagram fa-4x text-muted opacity-50"></i>
                    </div>
                    
                    <h3 class="h4 mb-3">No Digital Assets Assigned</h3>
                    
                    <p class="text-muted mb-4">
                        Welcome, <?php echo htmlspecialchars($clientUser['full_name'] ?? ($clientUser['username'] ?? 'User'), ENT_QUOTES, 'UTF-8'); ?>! 
                        You don't have any digital assets assigned to your account yet.
                    </p>
                    
                    <div class="alert alert-info text-start">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle me-2"></i>
                            What's Next?
                        </h6>
                        <ul class="mb-0 ps-3">
                            <li>Your administrator will assign digital assets to your account</li>
                            <li>Once assigned, you'll see accessibility analytics and reports here</li>
                            <li>You'll receive an email notification when digital assets are assigned</li>
                        </ul>
                    </div>
                    
                    <div class="mt-4">
                        <p class="small text-muted mb-3">
                            Need help or have questions?
                        </p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <a href="mailto:support@example.com" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-envelope me-1"></i>
                                Contact Support
                            </a>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-1"></i>
                                Refresh Page
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Help Section -->
    <div class="row justify-content-center mt-4">
        <div class="col-md-10 col-lg-8">
            <div class="card border-0 bg-light">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-question-circle text-primary me-2"></i>
                        About This Dashboard
                    </h5>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="text-center">
                                <i class="fas fa-chart-pie fa-2x text-primary mb-2"></i>
                                <h6>Analytics Reports</h6>
                                <p class="small text-muted mb-0">
                                    View comprehensive accessibility analytics including user impact, WCAG compliance, and issue severity.
                                </p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <i class="fas fa-download fa-2x text-success mb-2"></i>
                                <h6>Export Capabilities</h6>
                                <p class="small text-muted mb-0">
                                    Export your reports as PDF or Excel files for sharing with stakeholders and offline analysis.
                                </p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <i class="fas fa-eye fa-2x text-info mb-2"></i>
                                <h6>Real-time Updates</h6>
                                <p class="small text-muted mb-0">
                                    Dashboard automatically refreshes to show the latest accessibility testing results and progress.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?php 
require_once __DIR__ . '/../../footer.php';
?>