<?php
/**
 * Client-specific header with consistent styling
 */

if (!isset($baseDir)) {
    $baseDir = '/PMS'; // Set the correct base directory
}
?>


<header>
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="background-color: #0755C6 !important; background: #0755C6 !important; border: none !important;">
        <div class="container-fluid">
            <!-- Brand -->
            <a class="navbar-brand d-flex align-items-center gap-2 me-4" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/" aria-label="PMS Home" style="color: white !important; text-decoration: none !important;">
                <!-- Try to load logo, fallback to text if not available -->
                <span class="brand-logo-container" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; margin-right: 8px;">
                    <img src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/storage/SIS-Logo-3.png" 
                         alt="SIS Logo" 
                         class="brand-logo" 
                         style="max-height: 32px !important; width: auto !important; display: inline-block !important; vertical-align: middle !important;"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                    <span class="brand-logo-fallback" style="display: none; width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 4px; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; color: white;">S</span>
                </span>
                <span class="tracking-tight" style="color: white !important; font-weight: 600 !important;">PMS</span>
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#clientNavbarContent" aria-controls="clientNavbarContent" aria-expanded="false" aria-label="Toggle navigation" style="border-color: rgba(255, 255, 255, 0.3) !important;">
                <span class="navbar-toggler-icon" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 30 30\'%3e%3cpath stroke=\'rgba%28255, 255, 255, 0.8%29\' stroke-linecap=\'round\' stroke-miterlimit=\'10\' stroke-width=\'2\' d=\'M4 7h22M4 15h22M4 23h22\'/%3e%3c/svg%3e') !important;"></span>
            </button>

            <!-- Navbar Content -->
            <div class="collapse navbar-collapse" id="clientNavbarContent">
                <ul class="navbar-nav me-auto gap-1">
                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/client/dashboard" style="color: rgba(255, 255, 255, 0.9) !important; padding: 8px 12px !important; border-radius: 4px !important; transition: all 0.2s ease !important;">
                            <i class="fas fa-home me-1 opacity-50" style="color: white !important;"></i> <span style="color: white !important;">Dashboard</span>
                        </a>
                    </li>

                    <!-- Digital Assets -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/projects.php" style="color: rgba(255, 255, 255, 0.9) !important; padding: 8px 12px !important; border-radius: 4px !important; transition: all 0.2s ease !important;">
                            <i class="fas fa-folder-open me-1 opacity-50" style="color: white !important;"></i> <span style="color: white !important;">Digital Assets</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/client/dashboard" style="color: rgba(255, 255, 255, 0.9) !important; padding: 8px 12px !important; border-radius: 4px !important; transition: all 0.2s ease !important;">
                            <i class="fas fa-chart-line me-1 opacity-50" style="color: white !important;"></i> <span style="color: white !important;">Analytics</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/client/preferences.php" style="color: rgba(255, 255, 255, 0.9) !important; padding: 8px 12px !important; border-radius: 4px !important; transition: all 0.2s ease !important;">
                            <i class="fas fa-sliders-h me-1 opacity-50" style="color: white !important;"></i> <span style="color: white !important;">Preferences</span>
                        </a>
                    </li>
                </ul>

                <!-- Right Side Items -->
                <ul class="navbar-nav ms-auto align-items-center gap-2">
                    <!-- User Profile -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="clientUserDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="color: rgba(255, 255, 255, 0.9) !important;">
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white small fw-bold" style="width: 32px; height: 32px; border: 2px solid rgba(255,255,255,0.2); color: white !important;">
                                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span class="d-none d-lg-block small fw-semibold text-truncate" style="max-width: 150px; color: white !important;">
                                <?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8') : 'User'; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="clientUserDropdown" style="border-radius: 8px !important;">
                            <li>
                                <div class="px-3 py-2 border-bottom mb-2">
                                    <div class="fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                                    <div class="text-muted small" style="font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                                </div>
                            </li>
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/profile.php" style="padding: 8px 16px !important; transition: all 0.2s ease !important; color: #212529 !important;"><i class="fas fa-user-circle me-2 text-muted"></i> Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/auth/logout.php?csrf_token=<?php echo urlencode(generateCsrfToken()); ?>" style="padding: 8px 16px !important; transition: all 0.2s ease !important; color: #dc3545 !important;"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</header>
