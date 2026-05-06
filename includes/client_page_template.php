<?php
/**
 * Client Page Template
 * 
 * Use this template for creating new client pages with consistent headers
 * 
 * Usage:
 * 1. Copy this file to your new page location
 * 2. Update the page title and content
 * 3. Add your specific functionality
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';

$auth = new Auth();
$auth->requireLogin();

// Set page title
$pageTitle = 'Your Page Title - Client Portal';

// Ensure baseDir is set
if (!isset($baseDir)) {
    $baseDir = '/PMS';
}

// Handle flash messages
$globalFlashSuccess = isset($_SESSION['success']) ? (string)$_SESSION['success'] : '';
$globalFlashError = isset($_SESSION['error']) ? (string)$_SESSION['error'] : '';
if ($globalFlashSuccess !== '' || $globalFlashError !== '') {
    unset($_SESSION['success'], $_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <!-- Bootstrap and FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Add any additional CSS here -->
</head>
<body>
    <!-- Universal Header - ALWAYS USE THIS -->
    <?php include __DIR__ . '/universal_header.php'; ?>

    <!-- Your Page Content -->
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-your-icon"></i> Your Page Title</h5>
                    </div>
                    <div class="card-body">
                        <!-- Add your content here -->
                        <p>Your page content goes here...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Add any additional scripts here -->

    <script nonce="<?php echo htmlspecialchars($cspNonce ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    // Flash message handler - ALWAYS INCLUDE THIS
    <?php if ($globalFlashSuccess): ?>
        setTimeout(() => {
            const alert = document.createElement('div');
            alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
            alert.style.top = '80px';
            alert.style.right = '20px';
            alert.style.zIndex = '9999';
            alert.innerHTML = `<?php echo addslashes($globalFlashSuccess); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }, 100);
    <?php endif; ?>
    
    <?php if ($globalFlashError): ?>
        setTimeout(() => {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger alert-dismissible fade show position-fixed';
            alert.style.top = '80px';
            alert.style.right = '20px';
            alert.style.zIndex = '9999';
            alert.innerHTML = `<?php echo addslashes($globalFlashError); ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }, 100);
    <?php endif; ?>
    
    // Add your custom JavaScript here
    </script>
</body>
</html>