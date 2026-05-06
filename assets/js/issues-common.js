/**
 * issues-common.js
 * Extracted from modules/projects/issues_common.php inline script
 */
document.addEventListener('pms:issues-changed', function () {
    if (typeof window.loadCommonIssues === 'function') {
        window.loadCommonIssues({ silent: true });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var refreshBtn = document.getElementById('commonIssuesRefreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            if (typeof window.loadCommonIssues === 'function') {
                window.loadCommonIssues();
            }
        });
    }
});
