/* Client Dashboard Actions JS - extracted from modules/client/partials/dashboard_actions.php */
function exportDashboard(format, button) {
    var baseDir = window.baseUrl || '';
    var clientId = window.actualClientId || '';
    var selectedProjectId = window.selectedProjectId || '';
    var csrfToken = window._csrfToken || '';
    var exportUrl = baseDir + '/api/client_export.php?format=' + encodeURIComponent(format);

    if (clientId !== '') {
        exportUrl += '&client_id=' + encodeURIComponent(clientId);
    }
    if (selectedProjectId !== '') {
        exportUrl += '&project_id=' + encodeURIComponent(selectedProjectId);
    }
    if (csrfToken !== '') {
        exportUrl += '&csrf_token=' + encodeURIComponent(csrfToken);
    }

    var originalText = button ? button.innerHTML : '';
    if (button) {
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        button.disabled = true;
    }

    window.location.href = exportUrl;

    setTimeout(function() {
        if (button) {
            button.innerHTML = originalText;
            button.disabled = false;
        }
    }, 3000);
}

function refreshDashboard(button) {
    var originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    button.disabled = true;
    var url = new URL(window.location);
    url.searchParams.set('refresh', '1');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    var projectFilter = document.getElementById('projectFilter');
    if (projectFilter) {
        projectFilter.addEventListener('change', function() {
            var url = new URL(window.location);
            if (this.value) {
                url.searchParams.set('project_id', this.value);
            } else {
                url.searchParams.delete('project_id');
            }
            window.location.href = url.toString();
        });
    }

    document.querySelectorAll('[data-dashboard-export]').forEach(function(button) {
        button.addEventListener('click', function() {
            exportDashboard(this.getAttribute('data-dashboard-export'), this);
        });
    });

    document.querySelectorAll('[data-dashboard-refresh]').forEach(function(button) {
        button.addEventListener('click', function() {
            refreshDashboard(this);
        });
    });
});
