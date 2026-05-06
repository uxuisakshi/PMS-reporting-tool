/**
 * tab-performance.js
 * Extracted from modules/projects/partials/tab_performance.php inline script
 * Requires window.ProjectConfig.baseDir and window.ProjectConfig.projectId
 */

function toggleUserDetails(userId) {
    var performanceContent = document.getElementById('performance-content-' + userId);
    var qaBreakdownContent = document.getElementById('qa-breakdown-content-' + userId);
    var button = document.querySelector('[onclick="toggleUserDetails(' + userId + ')"]');

    if (qaBreakdownContent.style.display === 'none' || qaBreakdownContent.style.display === '') {
        performanceContent.style.display = 'none';
        qaBreakdownContent.style.display = 'block';
        button.innerHTML = '<i class="fas fa-chart-line me-1"></i> Hide QA Breakdown <i class="fas fa-chevron-up ms-1 details-chevron"></i>';
        var dataDiv = document.getElementById('qa-breakdown-data-' + userId);
        if (dataDiv.innerHTML.includes('Loading breakdown')) {
            loadQaBreakdown(userId);
        }
    } else {
        performanceContent.style.display = 'block';
        qaBreakdownContent.style.display = 'none';
        button.innerHTML = '<i class="fas fa-chart-pie me-1"></i> View QA Breakdown <i class="fas fa-chevron-down ms-1 details-chevron"></i>';
    }
}

function loadQaBreakdown(userId) {
    var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
    var contentDiv = document.getElementById('qa-breakdown-data-' + userId);

    contentDiv.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading breakdown...</div>';

    var url = baseDir + '/api/qa_breakdown.php?project_id=' + projectId + '&user_id=' + userId;

    fetch(url, { method: 'GET', credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (text) {
            try {
                var data = JSON.parse(text);
                if (data.success && data.breakdown) {
                    var html = '';
                    var totalUniqueIssues = data.total_unique_issues || 0;

                    data.breakdown.forEach(function (item) {
                        var badgeColor = item.error_points > 0 ? 'danger' : 'success';
                        var statusId = 'status-' + userId + '-' + item.status_key.replace(/[^a-zA-Z0-9]/g, '');

                        html += '<div class="mb-3">'
                            + '<div class="d-flex justify-content-between align-items-center mb-2">'
                            + '<div class="d-flex align-items-center">'
                            + '<span class="badge bg-' + badgeColor + ' me-2">' + item.issue_count + '</span>'
                            + '<strong>' + item.status_label + '</strong>'
                            + '<small class="text-muted ms-2">(' + item.error_points + ' error points)</small>'
                            + '</div>'
                            + '<button class="btn btn-sm btn-outline-secondary" onclick="toggleStatusIssues(\'' + statusId + '\')">'
                            + '<i class="fas fa-chevron-down" id="status-icon-' + statusId + '"></i>'
                            + '</button></div>'
                            + '<div class="collapse" id="' + statusId + '">'
                            + '<div class="bg-white border rounded p-2 issues-container">';

                        if (item.issues && item.issues.length > 0) {
                            item.issues.forEach(function (issue) {
                                html += '<div class="d-flex justify-content-between align-items-center py-1 px-2 mb-1 issue-item-new border-bottom"'
                                    + ' style="cursor: pointer;" onclick="openIssueDetail(' + issue.id + ', ' + (issue.page_id || 0) + ')">'
                                    + '<div><small class="text-primary fw-bold">' + (issue.issue_key || '#' + issue.id) + '</small>'
                                    + '<div class="small">' + issue.title + '</div></div>'
                                    + '<i class="fas fa-external-link-alt text-muted small"></i></div>';
                            });
                        } else {
                            html += '<div class="text-muted text-center py-2">No issues found</div>';
                        }
                        html += '</div></div></div>';
                    });

                    if (html === '') {
                        html = '<div class="text-center text-muted py-4">No QA status data found</div>';
                    } else {
                        html = '<div class="mb-3 text-center"><small class="text-muted">Total Issues: ' + totalUniqueIssues + '</small></div>' + html;
                    }
                    contentDiv.innerHTML = html;
                } else {
                    contentDiv.innerHTML = '<div class="text-center text-danger py-4">Failed to load breakdown data</div>';
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                contentDiv.innerHTML = '<div class="text-center text-danger py-4">Invalid response format</div>';
            }
        })
        .catch(function (error) {
            console.error('Error loading QA breakdown:', error);
            contentDiv.innerHTML = '<div class="text-center text-danger py-4">Network error</div>';
        });
}

function toggleStatusIssues(statusId) {
    var issuesList = document.getElementById(statusId);
    var icon = document.getElementById('status-icon-' + statusId);
    if (issuesList.classList.contains('show')) {
        issuesList.classList.remove('show');
        icon.className = 'fas fa-chevron-down';
    } else {
        issuesList.classList.add('show');
        icon.className = 'fas fa-chevron-up';
    }
}

function openIssueDetail(issueId, pageId) {
    var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
    var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
    var baseUrl = baseDir + '/modules/projects';
    if (pageId && pageId > 0) {
        window.open(baseUrl + '/issues_page_detail.php?project_id=' + projectId + '&page_id=' + pageId + '&expand=' + issueId, '_blank');
    } else {
        window.open(baseUrl + '/issues_all.php?project_id=' + projectId + '&expand=' + issueId, '_blank');
    }
}
