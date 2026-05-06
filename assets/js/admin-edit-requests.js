function openAdminViewModal(date, userId, requestId) {
    document.getElementById('adminRequestId').value = requestId;
    document.getElementById('adminUserId').value = userId;
    document.getElementById('adminDate').value = date;

    loadRequestDetails(requestId);
    loadAdminProductionHours(userId, date);

    loadCurrentData(userId, date).then(() => {
        loadPendingData(userId, date);
    });

    var modal = new bootstrap.Modal(document.getElementById('adminViewModal'));
    modal.show();
}

function loadCurrentData(userId, date) {
    const baseDir = window._editRequestsConfig.baseDir;
    return fetch(baseDir + '/api/get_original_data.php?user_id=' + userId + '&date=' + encodeURIComponent(date))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('currentStatus').value = data.status ? data.status.charAt(0).toUpperCase() + data.status.slice(1) : 'Not Updated';
                document.getElementById('currentNotes').value = data.notes || '';
                document.getElementById('currentPersonalNote').value = data.personal_note || '';
            } else {
                document.getElementById('currentStatus').value = 'Not Updated';
                document.getElementById('currentNotes').value = '';
                document.getElementById('currentPersonalNote').value = '';
            }
        })
        .catch(error => {
            console.error('Failed to load current data:', error);
            document.getElementById('currentStatus').value = 'Error loading data';
            document.getElementById('currentNotes').value = 'Error loading data';
            document.getElementById('currentPersonalNote').value = 'Error loading data';
        });
}

function loadPendingData(userId, date) {
    const baseDir = window._editRequestsConfig.baseDir;
    fetch(baseDir + '/api/get_pending_changes.php?user_id=' + userId + '&date=' + encodeURIComponent(date))
        .then(response => response.json())
        .then(data => {
            var logDiffCard = document.getElementById('adminLogDiffCard');
            var logDiffContent = document.getElementById('adminLogDiffContent');
            if (logDiffCard) logDiffCard.style.display = 'none';
            if (logDiffContent) logDiffContent.innerHTML = '';

            if (data.success && data.pending) {
                document.getElementById('requestedStatus').value = data.pending.status ? data.pending.status.charAt(0).toUpperCase() + data.pending.status.slice(1) : 'Not Updated';
                document.getElementById('requestedNotes').value = data.pending.notes || '';
                document.getElementById('requestedPersonalNote').value = data.pending.personal_note || '';
                var pendingLogs = data.pending.pending_time_logs_decoded || [];
                var hoursContainer = document.getElementById('adminHoursEntries');
                if (pendingLogs.length > 0) {
                    var html = '<div class="mb-2"><strong>Requested Time Log Changes:</strong></div>';
                    html += '<div class="list-group list-group-flush">';
                    pendingLogs.forEach(function(pl){
                        html += '<div class="list-group-item py-2">';
                        html += '<div><strong>Project:</strong> ' + (pl.project_id || 'N/A') + '</div>';
                        if (pl.page_ids && pl.page_ids.length) html += '<div><strong>Pages:</strong> ' + pl.page_ids.join(', ') + '</div>';
                        if (pl.environment_ids && pl.environment_ids.length) html += '<div><strong>Envs:</strong> ' + pl.environment_ids.join(', ') + '</div>';
                        html += '<div><strong>Testing Type:</strong> ' + (pl.testing_type || '') + '</div>';
                        html += '<div><strong>Hours:</strong> ' + (pl.hours || '') + '</div>';
                        html += '<div><strong>Description:</strong> ' + (pl.description || '') + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    hoursContainer.innerHTML = html + hoursContainer.innerHTML;
                }
            } else {
                document.getElementById('requestedStatus').value = 'No changes requested';
                document.getElementById('requestedNotes').value = 'No changes requested';
                document.getElementById('requestedPersonalNote').value = 'No changes requested';
            }

            var editDiffs = Array.isArray(data.pending_log_edit_diffs) ? data.pending_log_edit_diffs : [];
            var deleteDiffs = Array.isArray(data.pending_log_delete_diffs) ? data.pending_log_delete_diffs : [];
            if (logDiffCard && logDiffContent && (editDiffs.length > 0 || deleteDiffs.length > 0)) {
                var html = '';
                editDiffs.forEach(function(diff) {
                    html += renderLogDiffBlock(diff, false);
                });
                deleteDiffs.forEach(function(diff) {
                    html += renderLogDiffBlock(diff, true);
                });
                logDiffContent.innerHTML = html;
                logDiffCard.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Failed to load pending data:', error);
            document.getElementById('requestedStatus').value = 'No changes requested';
            document.getElementById('requestedNotes').value = 'No changes requested';
            document.getElementById('requestedPersonalNote').value = 'No changes requested';
            var logDiffCard = document.getElementById('adminLogDiffCard');
            var logDiffContent = document.getElementById('adminLogDiffContent');
            if (logDiffCard) logDiffCard.style.display = 'none';
            if (logDiffContent) logDiffContent.innerHTML = '';
        });
}

function formatTaskTypeLabel(taskType) {
    var t = String(taskType || '').trim();
    if (!t) return 'N/A';
    return t.replace(/_/g, ' ').replace(/\b\w/g, function(ch){ return ch.toUpperCase(); });
}

function renderLogSide(title, d, isDeleteSide) {
    d = d || {};
    var project = d.project_title || (d.project_id ? ('Project #' + d.project_id) : 'N/A');
    var page = d.page_name || (d.page_id ? ('Page #' + d.page_id) : 'N/A');
    var env = d.environment_name || (d.environment_id ? ('Environment #' + d.environment_id) : 'N/A');
    var issue = d.issue_id ? ('Issue #' + d.issue_id) : 'N/A';
    var taskType = formatTaskTypeLabel(d.task_type);
    var testingType = d.testing_type ? String(d.testing_type) : 'N/A';
    var hours = (d.hours_spent !== null && d.hours_spent !== undefined && d.hours_spent !== '') ? d.hours_spent : (isDeleteSide ? 'Will be deleted' : 'N/A');
    var desc = d.description || (isDeleteSide ? 'This log will be deleted' : '');
    var mode = (d.is_utilized === 0 || String(d.is_utilized) === '0') ? 'Off-Production/Bench' : 'Production';

    var html = '';
    html += '<div class="col-md-6">';
    html += '  <div class="border rounded p-2 h-100">';
    html += '    <h6 class="mb-2">' + escapeHtml(title) + '</h6>';
    html += '    <div class="small"><strong>Project:</strong> ' + escapeHtml(project) + '</div>';
    html += '    <div class="small"><strong>Task Type:</strong> ' + escapeHtml(taskType) + '</div>';
    html += '    <div class="small"><strong>Page/Task:</strong> ' + escapeHtml(page) + '</div>';
    html += '    <div class="small"><strong>Environment:</strong> ' + escapeHtml(env) + '</div>';
    html += '    <div class="small"><strong>Issue:</strong> ' + escapeHtml(issue) + '</div>';
    html += '    <div class="small"><strong>Testing Type:</strong> ' + escapeHtml(testingType) + '</div>';
    html += '    <div class="small"><strong>Mode:</strong> ' + escapeHtml(mode) + '</div>';
    html += '    <div class="small"><strong>Hours:</strong> ' + escapeHtml(String(hours)) + '</div>';
    html += '    <div class="small"><strong>Description:</strong> ' + escapeHtml(desc || 'N/A') + '</div>';
    html += '  </div>';
    html += '</div>';
    return html;
}

function renderLogDiffBlock(diff, isDelete) {
    var current = diff && diff.current ? diff.current : {};
    var requested = diff && diff.requested ? diff.requested : {};
    var titleBadge = isDelete
        ? '<span class="badge bg-danger ms-2">Delete Request</span>'
        : '<span class="badge bg-warning text-dark ms-2">Edit Request</span>';
    var html = '';
    html += '<div class="mb-3">';
    html += '  <div class="d-flex align-items-center mb-2"><strong>Time Log ID #' + escapeHtml(diff.log_id || '') + '</strong>' + titleBadge + '</div>';
    html += '  <div class="row g-2">';
    html += renderLogSide('Current', current, false);
    html += renderLogSide(isDelete ? 'Requested Action' : 'Requested', requested, isDelete);
    html += '  </div>';
    html += '</div>';
    return html;
}

function loadAdminProductionHours(userId, date) {
    const baseDir = window._editRequestsConfig.baseDir;
    document.getElementById('adminHoursDate').textContent = '(' + date + ')';

    var url = baseDir + '/api/user_hours.php?user_id=' + userId + '&date=' + encodeURIComponent(date);

    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var totalHours = parseFloat(data.total_hours || 0);
                var utilizedHours = 0;
                var benchHours = 0;

                document.getElementById('adminTotalHours').textContent = totalHours.toFixed(2) + ' hrs';

                if (data.entries && data.entries.length > 0) {
                    var html = '<div class="list-group list-group-flush">';
                    data.entries.forEach(function(entry) {
                        var hours = parseFloat(entry.hours_spent || 0);
                        var isUtilized = entry.is_utilized == 1 || entry.po_number !== 'OFF-PROD-001';

                        if (isUtilized) {
                            utilizedHours += hours;
                        } else {
                            benchHours += hours;
                        }

                        html += '<div class="list-group-item py-2">';
                        html += '<div class="d-flex justify-content-between align-items-start">';
                        html += '<div class="flex-grow-1">';
                        html += '<h6 class="mb-1">' + escapeHtml(entry.project_title || 'Unknown Project') + '</h6>';
                        if (entry.page_name) {
                            html += '<p class="mb-1 text-muted small">Page: ' + escapeHtml(entry.page_name) + '</p>';
                        }
                        if (entry.comments) {
                            html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                        }
                        html += '</div>';
                        html += '<div class="text-end">';
                        html += '<span class="badge ' + (isUtilized ? 'bg-success' : 'bg-secondary') + '">' + hours.toFixed(2) + 'h</span>';
                        html += '</div>';
                        html += '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    document.getElementById('adminHoursEntries').innerHTML = html;
                } else {
                    document.getElementById('adminHoursEntries').innerHTML = '<p class="text-muted text-center">No time logged for this date</p>';
                }

                document.getElementById('adminUtilizedHours').textContent = utilizedHours.toFixed(2);
                document.getElementById('adminBenchHours').textContent = benchHours.toFixed(2);

                if (totalHours > 0) {
                    var utilizedPercent = (utilizedHours / totalHours) * 100;
                    var benchPercent = (benchHours / totalHours) * 100;
                    document.getElementById('adminUtilizedProgress').style.width = utilizedPercent + '%';
                    document.getElementById('adminBenchProgress').style.width = benchPercent + '%';
                }
            } else {
                document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Failed to load production hours</p>';
            }
        })
        .catch(error => {
            document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Error loading production hours</p>';
        });
}

function loadRequestDetails(requestId) {
    var row = document.querySelector('input[value="' + requestId + '"]').closest('tr');
    var cells = row.querySelectorAll('td');

    document.getElementById('adminUserName').textContent = cells[1].querySelector('strong').textContent;
    document.getElementById('adminRequestDate').textContent = cells[3].querySelector('strong').textContent;
    document.getElementById('adminRequestReason').textContent = cells[4].textContent.trim() || 'No reason provided';
}

function adminApproveRequest() {
    confirmModal('Are you sure you want to approve this edit request?', function() {
        var requestId = document.getElementById('adminRequestId').value;
        var userId = document.getElementById('adminUserId').value;
        var date = document.getElementById('adminDate').value;

        var formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('user_id', userId);
        formData.append('date', date);
        formData.append('action', 'approved');

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            var modalElement = document.getElementById('adminViewModal');
            var modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) modal.hide();
            location.reload();
        })
        .catch(error => {
            showToast('Failed to approve request. Please try again.', 'danger');
        });
    });
}

function adminRejectRequest() {
    confirmModal('Are you sure you want to reject this edit request?', function() {
        var requestId = document.getElementById('adminRequestId').value;
        var userId = document.getElementById('adminUserId').value;
        var date = document.getElementById('adminDate').value;

        var formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('user_id', userId);
        formData.append('date', date);
        formData.append('action', 'rejected');

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            var modalElement = document.getElementById('adminViewModal');
            var modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) modal.hide();
            location.reload();
        })
        .catch(error => {
            showToast('Failed to reject request. Please try again.', 'danger');
        });
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&"'<>]/g, function (s) {
        return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
    });
}

// Bulk actions functionality
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const requestCheckboxes = document.querySelectorAll('.request-checkbox');
    const selectedCountSpan = document.getElementById('selectedCount');
    const bulkApproveBtn = document.getElementById('bulkApprove');
    const bulkRejectBtn = document.getElementById('bulkReject');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            requestCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
    }

    requestCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectAllState();
            updateBulkActions();
        });
    });

    function updateSelectAllState() {
        if (!selectAllCheckbox) return;
        const checkedCount = document.querySelectorAll('.request-checkbox:checked').length;
        const totalCount = requestCheckboxes.length;
        if (checkedCount === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (checkedCount === totalCount) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.indeterminate = true;
            selectAllCheckbox.checked = false;
        }
    }

    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.request-checkbox:checked').length;
        if (selectedCountSpan) {
            selectedCountSpan.textContent = checkedCount;
        }
        if (bulkApproveBtn && bulkRejectBtn) {
            const disabled = checkedCount === 0;
            bulkApproveBtn.disabled = disabled;
            bulkRejectBtn.disabled = disabled;
        }
    }

    if (bulkApproveBtn) {
        bulkApproveBtn.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.request-checkbox:checked');
            if (checkedBoxes.length === 0) return;
            confirmModal(`Are you sure you want to approve ${checkedBoxes.length} edit request(s)?`, function() {
                performBulkAction('approved', checkedBoxes);
            });
        });
    }

    if (bulkRejectBtn) {
        bulkRejectBtn.addEventListener('click', function() {
            const checkedBoxes = document.querySelectorAll('.request-checkbox:checked');
            if (checkedBoxes.length === 0) return;
            confirmModal(`Are you sure you want to reject ${checkedBoxes.length} edit request(s)?`, function() {
                performBulkAction('rejected', checkedBoxes);
            });
        });
    }

    function performBulkAction(action, checkedBoxes) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_action';
        actionInput.value = action;
        form.appendChild(actionInput);

        checkedBoxes.forEach(checkbox => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'request_ids[]';
            idInput.value = checkbox.value;
            form.appendChild(idInput);
        });

        document.body.appendChild(form);
        form.submit();
    }

    updateBulkActions();
});
