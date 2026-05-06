/* Client Project Header JS - extracted from modules/client/partials/project_header.php */

function exportProject(format, button) {
    var cfg = window._projectHeaderConfig || {};
    var projectId = cfg.projectId || 0;
    var baseDir = cfg.baseDir || '';
    var csrfToken = window._csrfToken || '';
    var exportUrl = baseDir + '/api/export_client_report.php?project_id=' + encodeURIComponent(projectId) + '&format=' + encodeURIComponent(format) + '&client_ready_only=1';

    if (csrfToken !== '') {
        exportUrl += '&csrf_token=' + encodeURIComponent(csrfToken);
    }

    var originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    button.disabled = true;

    window.location.href = exportUrl;

    setTimeout(function() {
        button.innerHTML = originalText;
        button.disabled = false;
    }, 3000);
}

function refreshProject(button) {
    var originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;

    var url = new URL(window.location);
    url.searchParams.set('refresh', '1');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-project-export]').forEach(function (button) {
        button.addEventListener('click', function () {
            exportProject(this.getAttribute('data-project-export'), this);
        });
    });

    document.querySelectorAll('[data-project-refresh]').forEach(function (button) {
        button.addEventListener('click', function () {
            refreshProject(this);
        });
    });
});
