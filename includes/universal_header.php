<?php
/**
 * Universal Header Component
 * 
 * Consistent header styling across all pages
 * Fixes logo overlap and ensures proper spacing
 */

if (!isset($baseDir)) {
    $baseDir = '/PMS';
}

// Get current page for active navigation
$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['REQUEST_URI'];
$clientHeaderProjects = [];
$clientAccessibilityProjectId = 0;
$clientAccessibilityReportLink = $baseDir . '/modules/client/issues_overview.php';

if (($_SESSION['role'] ?? '') === 'client') {
    try {
        require_once __DIR__ . '/models/ClientAccessControlManager.php';
        $clientHeaderAccessControl = new ClientAccessControlManager();
        $clientHeaderProjects = $clientHeaderAccessControl->getAssignedProjects((int)($_SESSION['user_id'] ?? 0));
    } catch (Exception $e) {
        error_log('Universal header client projects load failed: ' . $e->getMessage());
        $clientHeaderProjects = [];
    }

    $clientAccessibilityProjectId = (int) ($_GET['project_id'] ?? ($_GET['id'] ?? 0));
    if ($clientAccessibilityProjectId <= 0 && !empty($clientHeaderProjects)) {
        $clientAccessibilityProjectId = (int) ($clientHeaderProjects[0]['id'] ?? 0);
    }
    if ($clientAccessibilityProjectId > 0) {
        $clientAccessibilityReportLink = $baseDir . '/modules/projects/issues.php?project_id=' . $clientAccessibilityProjectId;
    }
}

$isClientProjectDetailPage = strpos($currentPath, '/modules/projects/view.php') !== false;
?>

<!-- Universal Header Styles -->
<style>
/* UNIVERSAL HEADER STYLES - CONSISTENT ACROSS ALL PAGES */
.pms-universal-header {
    background-color: #0755C6 !important;
    background: #0755C6 !important;
    border: none !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
    min-height: 60px !important;
}

/* Brand Section - Fix Logo Overlap */
.pms-brand {
    color: white !important;
    text-decoration: none !important;
    background-color: transparent !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 8px 0 !important;
}

.pms-brand:hover,
.pms-brand:focus {
    color: white !important;
    text-decoration: none !important;
}

.pms-logo-container {
    width: 40px !important;
    height: 40px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}

.pms-logo {
    max-height: 36px !important;
    max-width: 36px !important;
    width: auto !important;
    height: auto !important;
    display: block !important;
}

.pms-logo-fallback {
    width: 36px !important;
    height: 36px !important;
    background: rgba(255,255,255,0.2) !important;
    border-radius: 6px !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    font-weight: bold !important;
    font-size: 16px !important;
    color: white !important;
}

.pms-brand-text {
    color: white !important;
    font-weight: 600 !important;
    font-size: 1.25rem !important;
    margin: 0 !important;
    line-height: 1 !important;
}

/* Navigation Links */
.pms-nav-link {
    color: rgba(255, 255, 255, 0.9) !important;
    background-color: transparent !important;
    padding: 8px 12px !important;
    border-radius: 6px !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.pms-nav-link:hover,
.pms-nav-link:focus {
    color: white !important;
    background-color: rgba(255, 255, 255, 0.15) !important;
    text-decoration: none !important;
}

.pms-nav-link.active {
    color: white !important;
    background-color: rgba(255, 255, 255, 0.2) !important;
}

.pms-nav-link i {
    color: inherit !important;
    opacity: 0.8 !important;
}

/* User Dropdown */
.pms-user-avatar {
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    background: rgba(255, 255, 255, 0.2) !important;
    border: 2px solid rgba(255,255,255,0.3) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: white !important;
    font-weight: bold !important;
    font-size: 14px !important;
}

.pms-user-name {
    color: white !important;
    font-weight: 500 !important;
    max-width: 150px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}

/* Dropdown Menu */
.pms-dropdown-menu {
    background: white !important;
    border: none !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15) !important;
    border-radius: 8px !important;
    padding: 8px 0 !important;
    min-width: 200px !important;
}

.pms-dropdown-item {
    color: #212529 !important;
    padding: 10px 16px !important;
    background-color: transparent !important;
    border: none !important;
    text-decoration: none !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    transition: all 0.2s ease !important;
}

.pms-dropdown-item:hover,
.pms-dropdown-item:focus {
    background-color: #f8f9fa !important;
    color: #2563eb !important;
}

.pms-dropdown-item.text-danger {
    color: #dc3545 !important;
}

.pms-dropdown-item.text-danger:hover {
    background-color: #fef2f2 !important;
    color: #dc2626 !important;
}

.pms-dropdown-divider {
    margin: 8px 0 !important;
    border-color: #e5e7eb !important;
}

.pms-user-info {
    padding: 12px 16px 8px 16px !important;
    border-bottom: 1px solid #e5e7eb !important;
    margin-bottom: 8px !important;
}

.pms-user-info-name {
    font-weight: 600 !important;
    color: #111827 !important;
    font-size: 14px !important;
    margin: 0 !important;
}

.pms-user-info-email {
    color: #6b7280 !important;
    font-size: 12px !important;
    margin: 2px 0 0 0 !important;
}

/* Mobile Toggle */
.pms-navbar-toggler {
    border-color: rgba(255, 255, 255, 0.3) !important;
    padding: 6px 8px !important;
}

.pms-navbar-toggler:focus {
    box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.25) !important;
}

.pms-navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.9%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e") !important;
    width: 20px !important;
    height: 20px !important;
}

/* Mobile Responsive */
@media (max-width: 991px) {
    .pms-navbar-collapse {
        background-color: rgba(7, 85, 198, 0.95) !important;
        margin-top: 12px !important;
        border-radius: 8px !important;
        padding: 16px !important;
        backdrop-filter: blur(10px) !important;
    }
    
    .pms-nav-link {
        margin: 2px 0 !important;
    }
    
    .pms-user-name {
        display: block !important;
    }
}

/* Ensure no conflicts with other styles */
.pms-universal-header * {
    box-sizing: border-box !important;
}
</style>

<header>
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top pms-universal-header">
        <div class="container-fluid">
            <!-- Brand Section - Fixed Logo Overlap -->
            <a class="navbar-brand pms-brand" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/" aria-label="PMS Home">
                <div class="pms-logo-container">
                    <img src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/storage/SIS-Logo-3.png" 
                         alt="SIS Logo" 
                         class="pms-logo"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="pms-logo-fallback">S</div>
                </div>
                <span class="pms-brand-text">PMS</span>
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler pms-navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pmsNavbarContent" aria-controls="pmsNavbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon pms-navbar-toggler-icon"></span>
            </button>

            <!-- Navbar Content -->
            <div class="collapse navbar-collapse pms-navbar-collapse" id="pmsNavbarContent">
                <ul class="navbar-nav me-auto">
                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link pms-nav-link <?php echo (strpos($currentPath, 'dashboard') !== false) ? 'active' : ''; ?>" 
                           href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/client/dashboard">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    <!-- Digital Assets -->
                    <li class="nav-item">
                        <a class="nav-link pms-nav-link <?php echo (strpos($currentPath, 'projects') !== false) ? 'active' : ''; ?>" 
                           href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/projects.php">
                            <i class="fas fa-folder-open"></i>
                            <span>My Digital Assets</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link pms-nav-link <?php echo (strpos($currentPath, 'issues_overview') !== false) ? 'active' : ''; ?>" 
                           href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/issues_overview.php">
                            <i class="fas fa-list-ul"></i>
                            <span>Issue Overview</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link pms-nav-link <?php echo (strpos($currentPath, 'analytics') !== false || strpos($currentPath, 'view=analytics') !== false) ? 'active' : ''; ?>" 
                           href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/client/dashboard">
                            <i class="fas fa-chart-line"></i>
                            <span>Analytics</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link pms-nav-link <?php echo (strpos($currentPath, 'preferences') !== false) ? 'active' : ''; ?>" 
                           href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/preferences.php">
                            <i class="fas fa-sliders-h"></i>
                            <span>Preferences</span>
                        </a>
                    </li>

                    <?php if (!empty($clientHeaderProjects)): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle pms-nav-link <?php echo ($isClientProjectDetailPage || strpos($currentPath, 'project_dashboard.php') !== false) ? 'active' : ''; ?>" href="#" id="clientAssetDetailsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-eye"></i>
                            <span>Asset Analytics</span>
                        </a>
                        <ul class="dropdown-menu shadow-sm" aria-labelledby="clientAssetDetailsDropdown">
                            <li>
                                <a class="dropdown-item" href="<?php echo htmlspecialchars($clientAccessibilityReportLink, ENT_QUOTES, 'UTF-8'); ?>">
                                    Accessibility Report
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <?php foreach ($clientHeaderProjects as $clientHeaderProject): ?>
                            <li>
                                <a class="dropdown-item" href="<?php echo htmlspecialchars(buildClientProjectUrl((int) $clientHeaderProject['id'], (string) ($clientHeaderProject['title'] ?? ''), (string) ($clientHeaderProject['project_code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($clientHeaderProject['title'] ?? ('Asset #' . (int) $clientHeaderProject['id']), ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Right Side Items -->
                <ul class="navbar-nav ms-auto">
                    <!-- User Profile -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle pms-nav-link d-flex align-items-center" href="#" id="pmsUserDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="pms-user-avatar">
                                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span class="pms-user-name d-none d-lg-block ms-2">
                                <?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8') : 'User'; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end pms-dropdown-menu" aria-labelledby="pmsUserDropdown">
                            <li class="pms-user-info">
                                <div class="pms-user-info-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                                <div class="pms-user-info-email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                            </li>
                            <li>
                                <a class="dropdown-item pms-dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/profile.php">
                                    <i class="fas fa-user-circle"></i>
                                    <span>Profile</span>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider pms-dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item pms-dropdown-item text-danger" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/auth/logout.php?csrf_token=<?php echo urlencode(generateCsrfToken()); ?>">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <span>Logout</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</header>

<script src="<?php echo htmlspecialchars(getBaseDir(), ENT_QUOTES, 'UTF-8'); ?>/assets/js/universal-header.js"></script>