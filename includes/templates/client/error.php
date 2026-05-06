<?php
/**
 * Error Template
 * Displays error messages for portal users
 */

$errorMessage = $errorMessage ?? 'An unexpected error occurred.';
$clientUser = $clientUser ?? [];
$baseDir = $baseDir ?? '';

$pageTitle = "Error";
require_once __DIR__ . '/../../header.php';
?>
                    
                    <h3 class="h4 mb-3 text-danger">Oops! Something went wrong</h3>
                    
                    <div class="alert alert-danger text-start">
                        <i class="fas fa-times-circle me-2"></i>
                        <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    
                    <div class="mt-4">
                        <p class="text-muted mb-3">
                            What you can do:
                        </p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <button type="button" class="btn btn-primary" onclick="history.back()">
                                <i class="fas fa-arrow-left me-1"></i>
                                Go Back
                            </button>
                            <a href="<?php echo $baseDir; ?>/client/dashboard" class="btn btn-outline-primary">
                                <i class="fas fa-home me-1"></i>
                                Dashboard
                            </a>
                            <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-1"></i>
                                Retry
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
                <div class="card-body text-center">
                    <h6 class="card-title">
                        <i class="fas fa-life-ring text-primary me-2"></i>
                        Need Help?
                    </h6>
                    <p class="text-muted mb-3">
                        If this problem persists, please contact our support team with the following information:
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="small">
                                <strong>Time:</strong><br>
                                <?php echo date('Y-m-d H:i:s'); ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small">
                                <strong>User:</strong><br>
                                <?php echo htmlspecialchars($clientUser['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="small">
                                <strong>Page:</strong><br>
                                <?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="mailto:support@example.com" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-envelope me-1"></i>
                            Contact Support
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once __DIR__ . '/../../footer.php';
?>