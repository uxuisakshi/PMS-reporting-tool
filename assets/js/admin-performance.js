const activeFilters = window._performanceConfig.activeFilters;

function buildUrlParams() {
    return `start_date=${activeFilters.startDate}&end_date=${activeFilters.endDate}&severity_level=${activeFilters.severityLevel}&_t=${Date.now()}`;
}

function toggleMainRow(id, btnElement, mode) {
    const container = document.getElementById('main-breakdown-container-' + id);
    const icon = btnElement.querySelector('.transition-icon');
    const isExpanded = btnElement.getAttribute('aria-expanded') === 'true';

    if (isExpanded) {
        container.style.display = 'none';
        btnElement.setAttribute('aria-expanded', 'false');
        btnElement.classList.remove('btn-primary');
        btnElement.classList.add('btn-outline-primary');
        icon.className = 'fas fa-chevron-down ms-1 transition-icon';
    } else {
        container.style.display = 'table-row';
        btnElement.setAttribute('aria-expanded', 'true');
        btnElement.classList.remove('btn-outline-primary');
        btnElement.classList.add('btn-primary');
        icon.className = 'fas fa-chevron-up ms-1 transition-icon';

        const dataDiv = document.getElementById('main-breakdown-data-' + id);
        if (dataDiv.innerHTML.includes('Loading data')) {
            if (mode === 'user') {
                loadUserProjects(id, dataDiv);
            } else {
                loadProjectUsers(id, dataDiv);
            }
        }
    }
}

function loadUserProjects(userId, containerDiv) {
    const url = window._performanceConfig.baseDir + `/api/admin_user_projects.php?user_id=${userId}&` + buildUrlParams();

    fetch(url, { method: 'GET', credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.projects) {
            renderSubTable(data.projects, 'project', containerDiv, userId);
        } else {
            containerDiv.innerHTML = '<div class="alert alert-danger m-3">Failed to load projects.</div>';
        }
    })
    .catch(e => {
        containerDiv.innerHTML = '<div class="alert alert-danger m-3">Error fetching projects.</div>';
    });
}

function loadProjectUsers(projectId, containerDiv) {
    const url = window._performanceConfig.baseDir + `/api/admin_project_users.php?project_id=${projectId}&` + buildUrlParams();

    fetch(url, { method: 'GET', credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.users) {
            renderSubTable(data.users, 'user', containerDiv, projectId);
        } else {
            containerDiv.innerHTML = '<div class="alert alert-danger m-3">Failed to load users.</div>';
        }
    })
    .catch(e => {
        containerDiv.innerHTML = '<div class="alert alert-danger m-3">Error fetching users.</div>';
    });
}

function renderSubTable(items, type, containerDiv, parentId) {
    if (items.length === 0) {
        containerDiv.innerHTML = '<div class="text-muted text-center py-4">No data found in this breakdown.</div>';
        return;
    }

    const baseDir = window._performanceConfig.baseDir;
    let html = `
    <div class="table-responsive m-0">
        <table class="table table-sm table-hover align-middle mb-0 bg-white">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">${type === 'project' ? 'Project' : 'User'}</th>
                    <th class="text-center">Grade</th>
                    <th>Score</th>
                    <th class="text-center">Errors</th>
                    <th class="text-center">Pts</th>
                    <th class="text-center pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
    `;

    items.forEach(item => {
        const id = type === 'project' ? item.project_id : item.user_id;
        const name = type === 'project' ?
            `<a href="${baseDir}/modules/projects/view.php?id=${id}" target="_blank" class="fw-bold text-dark text-decoration-none"><i class="fas fa-folder text-primary me-2"></i>${item.project_title}</a>` :
            `<span class="fw-bold"><i class="fas fa-user text-primary me-2"></i>${item.full_name}</span>`;

        const rowId = parentId + '-' + id;

        let qaParams = {};
        if (type === 'project') {
            qaParams.userId = parentId;
            qaParams.projectId = id;
        } else {
            qaParams.projectId = parentId;
            qaParams.userId = id;
        }

        const paramsJson = JSON.stringify(qaParams).replace(/"/g, '&quot;');

        html += `
            <tr class="bg-white">
                <td class="ps-3">${name}</td>
                <td class="text-center"><span class="badge bg-${item.grade_color}">${item.grade}</span></td>
                <td><span class="fw-bold text-${item.grade_color}">${item.performance_score}%</span></td>
                <td class="text-center">${item.error_rate}</td>
                <td class="text-center text-danger">${parseFloat(item.total_error_points).toFixed(2)}</td>
                <td class="text-center pe-3">
                    <button class="btn btn-sm btn-light border text-primary" aria-expanded="false" onclick="toggleNestedQA('${rowId}', this, ${paramsJson})">
                        QA <i class="fas fa-chevron-down ms-1 nested-transition-icon"></i>
                    </button>
                </td>
            </tr>
            <tr id="nested-qa-container-${rowId}" style="display:none;" class="bg-light">
                <td colspan="6" class="p-3 shadow-inner">
                    <div id="nested-qa-data-${rowId}" class="bg-white p-3 rounded border">
                        <div class="text-center text-muted"><div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div><br>Loading QA Breakdown...</div>
                    </div>
                </td>
            </tr>
        `;
    });

    html += `</tbody></table></div>`;
    containerDiv.innerHTML = html;
}

function toggleNestedQA(rowId, btnElement, params) {
    const container = document.getElementById('nested-qa-container-' + rowId);
    const icon = btnElement.querySelector('.nested-transition-icon');
    const isExpanded = btnElement.getAttribute('aria-expanded') === 'true';

    if (isExpanded) {
        container.style.display = 'none';
        btnElement.setAttribute('aria-expanded', 'false');
        btnElement.classList.replace('btn-primary', 'btn-light');
        btnElement.classList.replace('text-white', 'text-primary');
        icon.className = 'fas fa-chevron-down ms-1 nested-transition-icon';
    } else {
        container.style.display = 'table-row';
        btnElement.setAttribute('aria-expanded', 'true');
        btnElement.classList.replace('btn-light', 'btn-primary');
        btnElement.classList.replace('text-primary', 'text-white');
        icon.className = 'fas fa-chevron-up ms-1 nested-transition-icon';

        const dataDiv = document.getElementById('nested-qa-data-' + rowId);
        if (dataDiv.innerHTML.includes('Loading QA')) {
            loadFinalQaBreakdown(rowId, dataDiv, params);
        }
    }
}

function loadFinalQaBreakdown(rowId, dataDiv, params) {
    const baseDir = window._performanceConfig.baseDir;
    const url = `${baseDir}/api/admin_qa_breakdown.php?user_id=${params.userId}&project_id=${params.projectId}&start_date=${activeFilters.startDate}&end_date=${activeFilters.endDate}&severity_level=${activeFilters.severityLevel}&_t=${Date.now()}`;

    fetch(url, { method: 'GET', credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.breakdown) {
            let html = '<div class="row row-cols-1 row-cols-md-2 g-2">';
            let totalUniqueIssues = data.total_unique_issues || 0;

            if (data.breakdown.length === 0) {
                dataDiv.innerHTML = '<div class="text-center text-muted py-2"><i class="fas fa-check-circle text-success mb-2"></i><br>No issues with QA status in this scope.</div>';
                return;
            }

            data.breakdown.forEach(item => {
                const badgeColor = item.error_points > 0 ? 'danger' : 'success';
                const statusId = 'status-' + rowId + '-' + item.status_key.replace(/[^a-zA-Z0-9]/g, '');

                html += `
                    <div class="col">
                        <div class="card h-100 border rounded shadow-none">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center py-2" style="cursor: pointer;" onclick="toggleStatusIssues('${statusId}')">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-${badgeColor} rounded-pill me-2">${item.issue_count}</span>
                                    <strong class="text-truncate" style="max-width: 150px;" title="${item.status_label}">${item.status_label}</strong>
                                </div>
                                <div class="d-flex align-items-center">
                                    <small class="text-muted border-end pe-2 me-2">${item.error_points} pts</small>
                                    <i class="fas fa-chevron-down text-muted" id="status-icon-${statusId}"></i>
                                </div>
                            </div>
                            <div class="collapse" id="${statusId}">
                                <div class="card-body p-0 issues-container" style="max-height: 150px;">
                `;

                if (item.issues && item.issues.length > 0) {
                    html += '<div class="list-group list-group-flush">';
                    item.issues.forEach(issue => {
                        html += `
                            <div class="list-group-item list-group-item-action py-1 px-2 issue-item-new" onclick="openIssueDetail(${issue.id}, ${issue.page_id || 0}, ${params.projectId})">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-truncate" style="max-width: 85%;">
                                        <small class="text-primary fw-bold me-1">${issue.issue_key || '#' + issue.id}</small>
                                        <span class="small" style="font-size: 0.75rem;">${issue.title}</span>
                                    </div>
                                    <i class="fas fa-external-link-alt text-muted" style="font-size: 0.6rem;"></i>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                } else {
                    html += '<div class="text-muted text-center py-2 small">No issues found</div>';
                }

                html += `</div></div></div></div>`;
            });
            html += `</div>`;

            dataDiv.innerHTML = `<h6 class="mb-2 text-primary border-bottom pb-1" style="font-size: 0.85rem;"><i class="fas fa-sitemap me-1"></i> Final QA Breakown <span class="badge bg-secondary ms-2">${totalUniqueIssues} Unique Issues</span></h6>` + html;
        } else {
            dataDiv.innerHTML = '<div class="alert alert-danger m-0 py-2"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load breakdown</div>';
        }
    })
    .catch(e => {
        dataDiv.innerHTML = '<div class="alert alert-danger m-0 py-2"><i class="fas fa-wifi me-2"></i>Network error</div>';
    });
}

function toggleStatusIssues(statusId) {
    const issuesList = document.getElementById(statusId);
    const icon = document.getElementById('status-icon-' + statusId);

    if (issuesList.classList.contains('show')) {
        issuesList.classList.remove('show');
        icon.className = 'fas fa-chevron-down text-muted';
    } else {
        issuesList.classList.add('show');
        icon.className = 'fas fa-chevron-up text-primary';
    }
}

function openIssueDetail(issueId, pageId, projectId) {
    const baseDir = window._performanceConfig.baseDir;
    if (projectId && projectId !== "") {
        if (pageId && pageId > 0) {
            const issuesUrl = `${baseDir}/modules/projects/issues_page_detail.php?project_id=${projectId}&page_id=${pageId}&expand=${issueId}`;
            window.open(issuesUrl, '_blank');
        } else {
            const issuesUrl = `${baseDir}/modules/projects/view.php?id=${projectId}&tab=issues&expand=${issueId}`;
            window.open(issuesUrl, '_blank');
        }
    } else {
        alert('Missing project context to open this issue.');
    }
}
