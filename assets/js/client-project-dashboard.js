/**
 * client-project-dashboard.js
 * Extracted from modules/client/project_dashboard.php inline script
 * Requires window._clientProjectConfig.baseDir
 */
document.addEventListener('DOMContentLoaded', function () {
    initializeProjectAnalytics();
    setupProjectNavigation();
});

function initializeProjectAnalytics() {
    // Project-specific initialization - config available via window._clientProjectConfig
}

function setupProjectNavigation() {
    var baseDir = (window._clientProjectConfig && window._clientProjectConfig.baseDir) ? window._clientProjectConfig.baseDir : '';
    var projectSelect = document.getElementById('projectNavSelect');
    if (projectSelect) {
        projectSelect.addEventListener('change', function () {
            if (this.value) {
                window.location.href = baseDir + '/modules/client/project_dashboard.php?id=' + encodeURIComponent(this.value);
            }
        });
    }
}
