<?php
/**
 * Client Help & Documentation
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

// Ensure user has client role or client permissions
if ($_SESSION['role'] !== 'client' && !isset($_SESSION['client_id'])) {
    header('Location: /index.php');
    exit;
}

$baseDir = getBaseDir();
$pageTitle = 'Help & Documentation';

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4 mb-5">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-question-circle text-primary"></i> Help & Documentation</h2>
            <p class="text-muted">Find answers to common questions and learn how to use the dashboard.</p>
        </div>
    </div>

    <div class="row">
        <!-- FAQ Section -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h4 class="mb-0">Frequently Asked Questions</h4>
                </div>
                <div class="card-body p-0">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item border-0 border-bottom">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                    How do I read my Unified Dashboard?
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    The unified dashboard aggregates analytics across all your active assigned projects. You can see your overall WCAG compliance score, the distribution of issues by severity, and what demographics might be affected. Click "View Projects" to narrow down into specific audits.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item border-0 border-bottom">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                    How do I export my data?
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    On your dashboard, you can use the "Quick Actions" to export your data into PDF or Excel layouts. Additionally, any data exports you request are logged in the <strong>Export History</strong> tab for later retrieval.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item border-0">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                    Why is my compliance score not updating?
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Compliance scores are refreshed automatically after QA engineers successfully verify an issue fix. If you recently modified a page, please allow time for our testing team to officially resolve the pending issues in the portal.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Support Info -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-primary">
                <div class="card-body text-center p-4">
                    <div class="mb-3">
                        <i class="fas fa-headset text-primary" style="font-size: 3rem;"></i>
                    </div>
                    <h4 class="card-title">Need direct support?</h4>
                    <p class="card-text text-muted mb-4">Our project managers and support team are available to help you with technical difficulties or queries about your results.</p>
                    <a href="<?php echo $baseDir; ?>/modules/feedback.php" class="btn btn-primary px-4 rounded-pill">
                        <i class="fas fa-comment-dots"></i> Submit Feedback
                    </a>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Technical Guides</h5>
                </div>
                <div class="list-group list-group-flush">
                    <a href="#" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="fas fa-file-pdf text-danger me-3"></i> 
                        <div>
                            <h6 class="mb-0">WCAG 2.1 Guide</h6>
                            <small class="text-muted">PDF Document (2.4 MB)</small>
                        </div>
                        <i class="fas fa-download ms-auto text-muted"></i>
                    </a>
                    <a href="#" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="fas fa-file-pdf text-danger me-3"></i> 
                        <div>
                            <h6 class="mb-0">Remediation Strategies</h6>
                            <small class="text-muted">PDF Document (1.1 MB)</small>
                        </div>
                        <i class="fas fa-download ms-auto text-muted"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
