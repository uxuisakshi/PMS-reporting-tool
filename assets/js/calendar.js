/* calendar.js - extracted from modules/calendar.php */
document.addEventListener('DOMContentLoaded', function() {
    var canEditFuture = window._calendarConfig.canEditFuture;
    var assignedProjects = window._calendarConfig.assignedProjects || [];
    var calendarEl = document.getElementById('calendar');
    var lastClickedDate = null;
    var lastClickedEditRequest = null;
    var isAdmin = window._calendarConfig.isAdmin;

    // Helper function to check if date is editable by the user directly.
    // Allow only: today, and the previous business day (yesterday or last Friday if today is Monday).
    // Future dates are NOT editable here (production-hours form will be hidden for future dates).
    function isEditableDate(dateStr) {
        try {
            var date = new Date(dateStr + 'T00:00:00');
            var today = new Date();
            today.setHours(0, 0, 0, 0);

            // Allow today
            if (date.getTime() === today.getTime()) return true;

            // Allow previous business day: usually yesterday, except when today is Monday -> last Friday
            var prev = new Date(today);
            prev.setDate(prev.getDate() - 1);
            if (today.getDay() === 1) { // Monday
                var lastFriday = new Date(today);
                lastFriday.setDate(lastFriday.getDate() - 3);
                return date.getTime() === lastFriday.getTime();
            }

            return date.getTime() === prev.getTime();
        } catch (e) {
            return false;
        }
    }

    function resetModal() {
        var modalTitle = document.querySelector('#calendarEditModal .modal-title');
        if (modalTitle) {
            modalTitle.textContent = 'Update My Availability';
            modalTitle.className = 'modal-title';
        }
        
        document.getElementById('calDate').value = '';
        try { document.getElementById('calendarEditModal').dataset.activeDate = ''; } catch (e) {}
        document.getElementById('calStatus').value = 'not_updated';
        document.getElementById('calNotes').value = '';
        document.getElementById('calPersonalNote').value = '';
        
        document.getElementById('editRequestStatus').style.display = 'none';
        lastClickedEditRequest = null;
        var reqFooterBtn = document.getElementById('requestEditFooterBtn');
        if (reqFooterBtn) {
            reqFooterBtn.style.display = 'none';
            reqFooterBtn.onclick = null;
        }
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (modalFooter) {
            var dynamicButtons = modalFooter.querySelectorAll('.dynamic-btn');
            dynamicButtons.forEach(btn => btn.remove());
        }
        
        document.getElementById('totalHours').textContent = '0.00 hrs';
        document.getElementById('utilizedHours').textContent = '0.00';
        document.getElementById('benchHours').textContent = '0.00';
        document.getElementById('utilizedProgress').style.width = '0%';
        document.getElementById('benchProgress').style.width = '100%';
        document.getElementById('hoursEntries').innerHTML = '<p class="text-muted text-center">Loading...</p>';
    }

    function enableEditing() {
        try {
            var el = document.getElementById('calStatus'); if (el) el.disabled = false;
            var n = document.getElementById('calNotes'); if (n) n.readOnly = false;
            var p = document.getElementById('calPersonalNote'); if (p) p.readOnly = false;

            // Production form fields
            var selectors = [
                '#logProductionHoursForm select[name="project_id"]', '#productionProjectSelect',
                '#productionPageSelect', '#productionEnvSelect', '#taskTypeSelect', '#testingTypeSelect', '#productionIssueSelect',
                '#logHoursInput', '#logDescriptionInput', '#logTimeBtn'
            ];
            selectors.forEach(function(s){
                var el = document.querySelector(s);
                if (!el) return;
                try { el.disabled = false; } catch(e) {}
                try { el.readOnly = false; } catch(e) {}
            });
        } catch (e) {}
    }

    function disableEditing() {
        try {
            var el = document.getElementById('calStatus'); if (el) el.disabled = true;
            var n = document.getElementById('calNotes'); if (n) n.readOnly = true;
            var p = document.getElementById('calPersonalNote'); if (p) p.readOnly = true;

            // Production form fields
            var selectors = [
                '#logProductionHoursForm select[name="project_id"]', '#productionProjectSelect',
                '#productionPageSelect', '#productionEnvSelect', '#taskTypeSelect', '#testingTypeSelect', '#productionIssueSelect',
                '#logHoursInput', '#logDescriptionInput', '#logTimeBtn'
            ];
            selectors.forEach(function(s){
                var el = document.querySelector(s);
                if (!el) return;
                try { el.disabled = true; } catch(e) {}
                try { el.readOnly = true; } catch(e) {}
            });
        } catch (e) {}
    }

    function loadProductionHours(date) {
        document.getElementById('hoursDate').textContent = '(' + date + ')';
        
        var url = window._calendarConfig.baseDir + '/api/user_hours.php?user_id=' + window._calendarConfig.userId + '&date=' + encodeURIComponent(date);
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                
                if (data.success) {
                    var totalHours = parseFloat(data.total_hours || 0);
                    var utilizedHours = 0;
                    var benchHours = 0;
                    
                    document.getElementById('totalHours').textContent = totalHours.toFixed(2) + ' hrs';
                    
                    if (data.entries && data.entries.length > 0) {
                        var productionEntries = data.entries.filter(function(entry) {
                            return entry.po_number !== 'OFF-PROD-001';
                        });
                        var benchEntries = data.entries.filter(function(entry) {
                            return entry.po_number === 'OFF-PROD-001';
                        });
                        
                        var html = '<div class="list-group list-group-flush">';
                        
                        if (productionEntries.length > 0) {
                            html += '<div class="list-group-item bg-light"><strong class="text-success">Production Hours</strong></div>';
                            productionEntries.forEach(function(entry) {
                                var hours = parseFloat(entry.hours_spent || 0);
                                utilizedHours += hours;
                                
                                html += '<div class="list-group-item py-2">';
                                html += '<div class="d-flex justify-content-between align-items-start">';
                                html += '<div class="flex-grow-1">';
                                html += '<h6 class="mb-1">' + escapeHtml(entry.project_title || 'Unknown Project') + '</h6>';
                                
                                // Show task type and details
                                if (entry.task_type === 'page_testing' && entry.page_name) {
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-desktop"></i> Page: ' + escapeHtml(entry.page_name) + '</p>';
                                    if (entry.environment_name) {
                                        html += '<p class="mb-1 text-muted small"><i class="fas fa-cog"></i> Environment: ' + escapeHtml(entry.environment_name) + '</p>';
                                    }
                                    if (entry.testing_type) {
                                        html += '<p class="mb-1 text-muted small"><i class="fas fa-tasks"></i> Type: ' + escapeHtml(entry.testing_type.replace('_', ' ')) + '</p>';
                                    }
                                } else if (entry.task_type === 'project_phase' && entry.phase_name) {
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-project-diagram"></i> Phase: ' + escapeHtml(entry.phase_name) + '</p>';
                                } else if (entry.task_type === 'generic_task' && entry.generic_category_name) {
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-tag"></i> Category: ' + escapeHtml(entry.generic_category_name) + '</p>';
                                } else if (entry.page_name) {
                                    // Fallback for older entries without task_type
                                    html += '<p class="mb-1 text-muted small"><i class="fas fa-desktop"></i> Page: ' + escapeHtml(entry.page_name) + '</p>';
                                    if (entry.environment_name) {
                                        html += '<p class="mb-1 text-muted small"><i class="fas fa-cog"></i> Environment: ' + escapeHtml(entry.environment_name) + '</p>';
                                    }
                                }
                                
                                if (entry.comments) {
                                    html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                                }
                                html += '</div>';
                                html += '<div class="text-end">';
                                html += '<span class="badge bg-success">' + hours.toFixed(2) + 'h</span>';
                                html += '</div>';
                                html += '</div>';
                                html += '</div>';
                            });
                        }
                        
                        if (benchEntries.length > 0) {
                            html += '<div class="list-group-item bg-light"><strong class="text-secondary">Off-Production/Bench Hours</strong></div>';
                            benchEntries.forEach(function(entry) {
                                var hours = parseFloat(entry.hours_spent || 0);
                                benchHours += hours;
                                
                                html += '<div class="list-group-item py-2">';
                                html += '<div class="d-flex justify-content-between align-items-start">';
                                html += '<div class="flex-grow-1">';
                                html += '<h6 class="mb-1 text-secondary">Off-Production Activity</h6>';
                                if (entry.comments) {
                                    html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                                }
                                html += '</div>';
                                html += '<div class="text-end">';
                                html += '<span class="badge bg-secondary">' + hours.toFixed(2) + 'h</span>';
                                html += '</div>';
                                html += '</div>';
                                html += '</div>';
                            });
                        }
                        
                        html += '</div>';
                        document.getElementById('hoursEntries').innerHTML = html;
                    } else {
                        document.getElementById('hoursEntries').innerHTML = '<p class="text-muted text-center">No time logged for this date</p>';
                    }
                    
                    document.getElementById('utilizedHours').textContent = utilizedHours.toFixed(2);
                    document.getElementById('benchHours').textContent = benchHours.toFixed(2);
                    
                    if (totalHours > 0) {
                        var utilizedPercent = (utilizedHours / totalHours) * 100;
                        var benchPercent = (benchHours / totalHours) * 100;
                        document.getElementById('utilizedProgress').style.width = utilizedPercent + '%';
                        document.getElementById('benchProgress').style.width = benchPercent + '%';
                    }

                    // Add Edit/Delete capability for today's logs
                    var t = new Date();
                    var todayStr = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
                    if (date === todayStr && !isAdmin) {
                        var items = document.querySelectorAll('#hoursEntries .list-group-item:not(.bg-light)');
                        var logEntries = data.entries || [];
                        items.forEach(function(item, idx) {
                            var entry = logEntries[idx];
                            if (!entry) return;
                            var actions = document.createElement('div');
                            actions.className = 'ms-2 mt-1';
                            actions.innerHTML = `
                                <a href="my_daily_status.php?date=${date}&delete_log=${entry.id}" class="text-danger me-2 small" onclick="return confirm('Delete this log?')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                                <a href="my_daily_status.php?date=${date}" class="text-primary small">
                                    <i class="fas fa-external-link-alt"></i> Go to Daily Status to Edit
                                </a>
                            `;
                            item.querySelector('.flex-grow-1').appendChild(actions);
                        });
                    }
                } else {
                    document.getElementById('hoursEntries').innerHTML = '<p class="text-danger text-center">Failed to load production hours: ' + (data.error || 'Unknown error') + '</p>';
                }
            })
            .catch(error => {
                document.getElementById('hoursEntries').innerHTML = '<p class="text-danger text-center">Error loading production hours: ' + error.message + '</p>';
            });
    }

    function addModalButtons(date) {
        var normalizedDate = String(date || '').slice(0, 10);
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (!modalFooter) return;
        var oldDynamicButtons = modalFooter.querySelectorAll('.dynamic-btn');
        oldDynamicButtons.forEach(function(btn){ btn.remove(); });
        
        var cancelBtn = modalFooter.querySelector('button[data-bs-dismiss="modal"]');
        if (!cancelBtn) return;
        
        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        var isFutureDate = normalizedDate > todayStr;
        var isPastDate = normalizedDate < todayStr;
        var reqFooterBtn = document.getElementById('requestEditFooterBtn');
        function showRequestFooter(show) {
            if (!reqFooterBtn) return;
            reqFooterBtn.style.display = show ? 'inline-block' : 'none';
            reqFooterBtn.onclick = show ? function() { openEditRequestModal(normalizedDate); } : null;
        }
        // Default hidden; each branch decides explicitly.
        showRequestFooter(false);
        
        // NEVER show Request Edit if it's today
        if (normalizedDate === todayStr) {
            showRequestFooter(false);
        } else if (isPastDate) {
            // Past dates - handled by logic below
        }

        if (isEditableDate(normalizedDate) || isFutureDate) {
            // Today / previous business day OR future dates -> allow saving availability changes
            enableEditing();
            var saveBtn = document.createElement('button');
            saveBtn.type = 'submit';
            saveBtn.className = 'btn btn-success dynamic-btn';
            saveBtn.textContent = 'Save Changes';
            modalFooter.insertBefore(saveBtn, cancelBtn);
            // Never show Request Edit for future dates
            if (isFutureDate) return;

            checkEditRequestStatus(normalizedDate, function(pending, approved, status, pendingLocked) {
                if (pending) {
                    // Pending exists: if submitted, keep read-only. Otherwise allow pending edit.
                    showRequestFooter(false);
                    if (pendingLocked) {
                        disableEditing();
                    } else {
                        var pendingBtnEditable = document.createElement('button');
                        pendingBtnEditable.type = 'button';
                        pendingBtnEditable.className = 'btn btn-warning dynamic-btn';
                        pendingBtnEditable.textContent = 'Edit Pending Changes';
                        pendingBtnEditable.onclick = function() {
                            enableEditingForPendingRequest(normalizedDate);
                        };
                        modalFooter.insertBefore(pendingBtnEditable, cancelBtn);
                    }
                } else {
                    showRequestFooter(true);
                }
            });

        } else {
            // Past dates - check approval status
            checkEditRequestStatus(normalizedDate, function(pending, approved, status, pendingLocked) {
                if (approved) {
                    showRequestFooter(false);
                    enableEditingForPendingRequest(normalizedDate);
                } else if (pending) {
                    showRequestFooter(false);
                    if (pendingLocked) {
                        disableEditing();
                    } else {
                        disableEditing();
                    }
                } else {
                    // No pending/approved request: show only Request Edit.
                    showRequestFooter(true);
                }
            });
        }
    }

    function checkEditRequestStatus(date, callback) {
        fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php?action=check_edit_request&date=' + encodeURIComponent(date))
            .then(response => response.json())
            .then(data => {
                callback(data.pending || false, data.approved || false, data.status || null, data.pending_locked || false);
            })
            .catch(() => {
                callback(false, false, null, false);
            });
    }

    function loadDateData(date) {
        fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php?action=get_personal_note&date=' + encodeURIComponent(date))
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    document.getElementById('calStatus').value = data.status || 'not_updated';
                    document.getElementById('calNotes').value = data.notes || '';
                    document.getElementById('calPersonalNote').value = data.personal_note || '';

                    var modalTitle = document.querySelector('#calendarEditModal .modal-title');
                    if (modalTitle) {
                        modalTitle.textContent = 'Update My Availability';
                        modalTitle.className = 'modal-title';
                    }
                }
            })
            .catch(error => {
                // suppressed date data fetch error
                document.getElementById('calStatus').value = 'not_updated';
                document.getElementById('calNotes').value = '';
                document.getElementById('calPersonalNote').value = '';
            });
    }

    function displayEditRequestInfo(editRequest) {
        var editRequestStatus = document.getElementById('editRequestStatus');
        var statusBadge = document.getElementById('editRequestStatusBadge');
        var reasonRow = document.getElementById('editRequestReasonRow');
        var reason = document.getElementById('editRequestReason');
        var datesRow = document.getElementById('editRequestDatesRow');
        var requestDate = document.getElementById('editRequestDate');
        var updatedRow = document.getElementById('editRequestUpdatedRow');
        var updated = document.getElementById('editRequestUpdated');
        
        if (!editRequest) {
            editRequestStatus.style.display = 'none';
            return;
        }
        
        editRequestStatus.style.display = 'block';
        
        var status = editRequest.status;
        statusBadge.className = 'badge ';
        statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        
        switch (status) {
            case 'pending':
                statusBadge.className += 'bg-warning text-dark';
                break;
            case 'approved':
                statusBadge.className += 'bg-success';
                break;
            case 'rejected':
                statusBadge.className += 'bg-danger';
                break;
            case 'used':
                statusBadge.className += 'bg-secondary';
                break;
        }
        
        if (editRequest.reason) {
            reasonRow.style.display = 'block';
            reason.textContent = editRequest.reason;
        } else {
            reasonRow.style.display = 'none';
        }
        
        if (editRequest.created_at) {
            datesRow.style.display = 'block';
            requestDate.textContent = new Date(editRequest.created_at).toLocaleString();
        } else {
            datesRow.style.display = 'none';
        }
        
        if (editRequest.updated_at && (status === 'approved' || status === 'rejected' || status === 'used')) {
            updatedRow.style.display = 'block';
            updated.textContent = new Date(editRequest.updated_at).toLocaleString();
        } else {
            updatedRow.style.display = 'none';
        }
    }

    function openModalForDate(date, eventInfo) {
        resetModal();
        document.getElementById('calDate').value = date;
        try { document.getElementById('calendarEditModal').dataset.activeDate = date; } catch (e) {}
        lastClickedDate = date;
        lastClickedEditRequest = (eventInfo && eventInfo.extendedProps && eventInfo.extendedProps.edit_request)
            ? eventInfo.extendedProps.edit_request
            : null;
        
        if (eventInfo && eventInfo.extendedProps && eventInfo.extendedProps.edit_request) {
            displayEditRequestInfo(eventInfo.extendedProps.edit_request);
        }
        
        loadDateData(date);
        loadProductionHours(date);
        
        var modal = new bootstrap.Modal(document.getElementById('calendarEditModal'));
        modal.show();
        // production form visibility will be handled below based on date rules

        // Populate project select to ensure options show in the modal
        (function populateProjectSelect(){
            try {
                var projSel = document.querySelector('#logProductionHoursForm select[name="project_id"]');
                if (!projSel) return;
                projSel.innerHTML = '';
                var blank = document.createElement('option'); blank.value = ''; blank.textContent = 'Select Project';
                projSel.appendChild(blank);
                assignedProjects.forEach(function(p){
                    var opt = document.createElement('option');
                    opt.value = p.id || p.ID || '';
                    opt.textContent = (p.po_number ? ('['+p.po_number+'] ') : '') + (p.title || p.title_name || p.name || 'Project');
                    projSel.appendChild(opt);
                });
            } catch (e) {}
        })();

        // Adjust modal: future dates hide production form; only today/previous business day allow direct edits;
        // older past dates require an edit request (show Request Edit button and hide production form).
        (function adjustModalForDate(){
            try {
                var dt = new Date(date + 'T00:00:00');
                var today = new Date(); today.setHours(0,0,0,0);
                var formContainer = document.getElementById('calendarModalLogFormContainer');
                var openLogBtn = document.getElementById('openLogHoursModalBtn');
                var modalFooter = document.querySelector('#calendarEditModal .modal-footer');

                // Clean up any previous dynamic buttons
                if (modalFooter) {
                    var oldBtns = modalFooter.querySelectorAll('.dynamic-btn, .request-edit-btn');
                    oldBtns.forEach(function(b){ b.remove(); });
                }

                // Future dates: hide production form (only availability should be editable here)
                if (dt.getTime() > today.getTime()) {
                    if (formContainer) formContainer.style.display = 'none';
                    if (openLogBtn) openLogBtn.style.display = 'none';
                    // availability fields editable
                    try { document.getElementById('calStatus').disabled = false; document.getElementById('calNotes').readOnly = false; document.getElementById('calPersonalNote').readOnly = false; } catch(e) {}
                    return;
                }

                // Today or previous business day: allow direct edits and show form
                if (isEditableDate(date)) {
                    if (formContainer) formContainer.style.display = '';
                    if (openLogBtn) openLogBtn.style.display = '';
                    enableEditing();
                    return;
                }

                // Older past dates: show production form but keep it read-only; Request Edit button provided by addModalButtons
                if (formContainer) formContainer.style.display = '';
                if (openLogBtn) openLogBtn.style.display = '';
                disableEditing();
            } catch (e) {}
        })();
    }

    function openEditRequestModal(date) {
        document.getElementById('requestDate').value = date;
        document.getElementById('editReason').value = '';
        var sendBtn = document.getElementById('editRequestSendBtn');
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Request';
        }
        var modal = new bootstrap.Modal(document.getElementById('editRequestModal'));
        modal.show();
    }

    function sendEditRequestWithReason(date, reason) {
        var sendBtn = document.getElementById('editRequestSendBtn');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
        }
        var modalEl = document.getElementById('editRequestModal');
        try {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        } catch (e) {}

        var formData = new FormData();
        formData.append('action', 'request_edit');
        formData.append('date', date);
        formData.append('reason', reason);
        
        fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                disableEditing();
                addModalButtons(date);
                showToast(data.message || 'Edit access request sent successfully. You can edit after admin approval.', 'success');
            } else {
                disableEditing();
                addModalButtons(date);
                showToast('Failed to send edit request: ' + (data.error || 'Unknown error'), 'danger');
                try {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                } catch (e) {}
            }
        })
        .catch(error => {
            disableEditing();
            addModalButtons(date);
            showToast('Failed to send edit request. Please try again.', 'danger');
            try {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } catch (e) {}
        })
        .finally(() => {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send Request';
            }
        });
    }

    function enableEditingForPendingRequest(date) {
        var modalFooter = document.querySelector('#calendarEditModal .modal-footer');
        if (modalFooter) {
            var dynamicButtons = modalFooter.querySelectorAll('.dynamic-btn');
            dynamicButtons.forEach(btn => btn.remove());
            
            var cancelBtn = modalFooter.querySelector('button[data-bs-dismiss="modal"]');
            
            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-warning dynamic-btn';
            saveBtn.textContent = 'Save Pending Changes';
            saveBtn.onclick = function() {
                savePendingChanges(date, false);
            };
            modalFooter.insertBefore(saveBtn, cancelBtn);

            var submitBtn = document.createElement('button');
            submitBtn.type = 'button';
            submitBtn.className = 'btn btn-primary dynamic-btn';
            submitBtn.textContent = 'Submit Pending';
            submitBtn.onclick = function() {
                confirmModal('Submit pending changes now? After submit, you will not be able to change them until admin reviews.', function() {
                    submitPendingChanges(date);
                }, {
                    title: 'Submit Pending Changes',
                    confirmText: 'Submit',
                    confirmClass: 'btn-primary'
                });
            };
            modalFooter.insertBefore(submitBtn, cancelBtn);
        }

        var reqFooterBtn = document.getElementById('requestEditFooterBtn');
        if (reqFooterBtn) {
            reqFooterBtn.style.display = 'none';
            reqFooterBtn.onclick = null;
        }
        
        enableEditing();
    }

    function savePendingChanges(date, closeOnSuccess) {
        var shouldClose = (closeOnSuccess === true);
        var formData = new FormData();
        formData.append('action', 'save_pending');
        formData.append('date', date);
        formData.append('status', document.getElementById('calStatus').value);
        formData.append('notes', document.getElementById('calNotes').value);
        formData.append('personal_note', document.getElementById('calPersonalNote').value);

        fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (window._myCalendar) {
                    window._myCalendar.refetchEvents();
                }
                if (shouldClose) {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('calendarEditModal'));
                    if (modal) modal.hide();
                }
                showToast('Pending changes saved.', 'success');
            } else {
                showToast('Failed to save pending changes: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
            .catch(error => {
            showToast('Request failed. Please try again.', 'danger');
        });
    }

    function submitPendingChanges(date) {
        var formData = new FormData();
        formData.append('action', 'save_pending');
        formData.append('date', date);
        formData.append('status', document.getElementById('calStatus').value);
        formData.append('notes', document.getElementById('calNotes').value);
        formData.append('personal_note', document.getElementById('calPersonalNote').value);

        fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(function(saveResp) {
            if (!saveResp || !saveResp.success) {
                throw new Error((saveResp && saveResp.error) ? saveResp.error : 'Failed to save pending changes.');
            }
            var submitFd = new FormData();
            submitFd.append('action', 'submit_pending');
            submitFd.append('date', date);
            return fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php', {
                method: 'POST',
                body: submitFd
            });
        })
        .then(response => response.json())
        .then(function(submitResp) {
            if (!submitResp || !submitResp.success) {
                throw new Error((submitResp && submitResp.error) ? submitResp.error : 'Failed to submit pending changes.');
            }
            disableEditing();
            addModalButtons(date);
            if (window._myCalendar) {
                window._myCalendar.refetchEvents();
            }
            showToast('Pending changes submitted. You can no longer edit until admin reviews.', 'success');
        })
        .catch(function(err) {
            showToast(err.message || 'Failed to submit pending changes.', 'danger');
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
        });
    }

    function getSelectedStatusFilters() {
        var checks = document.querySelectorAll('.status-filter-check:checked');
        var values = Array.prototype.map.call(checks, function (el) { return String(el.value || '').trim(); })
            .filter(function (v) { return v.length > 0; });
        return values.length ? values.join(',') : 'all';
    }

    function getEventsUrl() {
        var sel = document.getElementById('admin_user_select');
        var user = sel ? sel.value : '';
        var editFilter = document.getElementById('edit_request_filter');
        var editRequestFilter = editFilter ? editFilter.value : '';
        var statusFilter = getSelectedStatusFilters();
        
        var url = window._calendarConfig.baseDir + '/modules/calendar.php?action=get_events';
        if (user) url += '&user_id=' + encodeURIComponent(user);
        if (editRequestFilter) url += '&edit_request_filter=' + encodeURIComponent(editRequestFilter);
        if (statusFilter) url += '&status_filter=' + encodeURIComponent(statusFilter);
        return url;
    }

    // Initialize FullCalendar
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listWeek'
        },
        events: getEventsUrl(),
        dateClick: function(info) {
            if (!isAdmin) {
                openModalForDate(info.dateStr, null);
            }
        },
        eventClick: function(info) {
            if (!isAdmin) {
                openModalForDate(info.event.startStr, info.event);
            }
        },
        eventDidMount: function(info) {
            // Add tooltip with event details
            if (info.event.extendedProps.notes) {
                info.el.setAttribute('title', info.event.extendedProps.notes);
            }
        }
    });

    window._myCalendar = calendar;
    calendar.render();

    function setCalendarLogStatus(message, type) {
        var statusEl = document.getElementById('calendarLogStatus');
        if (!statusEl) return;
        statusEl.className = 'alert py-2 px-3 small mb-3';
        if (type === 'success') statusEl.classList.add('alert-success');
        else if (type === 'warning') statusEl.classList.add('alert-warning');
        else statusEl.classList.add('alert-danger');
        statusEl.textContent = message;
        statusEl.classList.remove('d-none');
    }

    function notifyCalendar(message, type) {
        if (typeof showToast === 'function') {
            try { showToast(message, type || 'info'); } catch (e) {}
        }
        setCalendarLogStatus(message, type || 'danger');
    }

    function submitCalendarLogHours(e) {
        if (e) e.preventDefault();
        try {
            var dateEl = document.getElementById('calDate');
            var date = dateEl ? String(dateEl.value || '').slice(0, 10) : '';
            if (!date) {
                try {
                    var modalDate = document.getElementById('calendarEditModal').dataset.activeDate || '';
                    date = String(modalDate).slice(0, 10);
                } catch (err) {}
            }
            if (!date && lastClickedDate) {
                date = String(lastClickedDate).slice(0, 10);
            }
            if (dateEl && date) {
                dateEl.value = date;
            }
            var projectEl = document.getElementById('productionProjectSelect') || document.querySelector('#logProductionHoursForm select[name="project_id"]');
            var hoursEl = document.getElementById('logHoursInput');
            var submitBtn = document.getElementById('logTimeBtn');
            var taskTypeEl = document.getElementById('taskTypeSelect');
            var pageEl = document.getElementById('productionPageSelect');
            var envEl = document.getElementById('productionEnvSelect');
            var testingTypeEl = document.getElementById('testingTypeSelect');
            var descEl = document.getElementById('logDescriptionInput');
            var pageColEl = document.getElementById('pageTestingContainer');
            var envColEl = document.getElementById('productionEnvCol');
            var calFormEl = document.getElementById('logProductionHoursForm');
            if (submitBtn && submitBtn.dataset.logging === '1') {
                return false;
            }

            if (!date) {
                notifyCalendar('Date is missing. Reopen the modal and try again.', 'warning');
                return false;
            }
            var projectValue = '';
            if (projectEl) {
                projectValue = String(projectEl.value || '').trim();
                if (!projectValue && typeof projectEl.selectedIndex === 'number' && projectEl.selectedIndex >= 0 && projectEl.options && projectEl.options[projectEl.selectedIndex]) {
                    projectValue = String(projectEl.options[projectEl.selectedIndex].value || '').trim();
                }
            }
            if (!projectValue && calFormEl) {
                try {
                    var fdProbe = new FormData(calFormEl);
                    projectValue = String(fdProbe.get('project_id') || '').trim();
                } catch (err) {}
            }
            if (!projectValue) {
                notifyCalendar('Please select a project.', 'warning');
                return false;
            }
            if (!hoursEl || !hoursEl.value || parseFloat(hoursEl.value) <= 0) {
                notifyCalendar('Please enter valid hours.', 'warning');
                return false;
            }

            var oldLabel = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.logging = '1';
                submitBtn.textContent = 'Logging...';
            }

            var fd = new FormData();
            fd.append('action', 'log');
            fd.append('user_id', String(window._calendarConfig.userId));
            fd.append('project_id', projectValue);
            fd.append('task_type', taskTypeEl ? taskTypeEl.value : '');
            var pages = pageEl ? Array.from(pageEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [];
            if (pages.length) fd.append('page_id', pages[0]);
            var envs = envEl ? Array.from(envEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [];
            if (envs.length) fd.append('environment_id', envs[0]);
            fd.append('testing_type', testingTypeEl ? testingTypeEl.value : '');
            fd.append('log_date', date);
            fd.append('hours', hoursEl.value);
            fd.append('description', descEl ? descEl.value : '');
            fd.append('is_utilized', 1);

            // For older past dates, hours should be saved into pending changes (not directly logged).
            var t = new Date();
            var todayStr = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
            var isOlderPastDate = date < todayStr && !isEditableDate(date);
            if (!isAdmin && isOlderPastDate) {
                checkEditRequestStatus(date, function(pending, approved, status, pendingLocked) {
                    if (!approved) {
                        if (pending && !pendingLocked) {
                            notifyCalendar('Edit access for this date is still waiting for admin approval.', 'warning');
                        } else if (pendingLocked) {
                            notifyCalendar('Pending changes are already submitted and locked. You cannot edit until admin reviews.', 'warning');
                        } else {
                            notifyCalendar('Please get edit access approved first for this date.', 'warning');
                        }
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = oldLabel || 'Log Hours';
                        }
                        return;
                    }
                    if (pendingLocked) {
                        notifyCalendar('Pending changes are already submitted and locked. You cannot edit until admin reviews.', 'warning');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = oldLabel || 'Log Hours';
                        }
                        return;
                    }

                    var pendingEntry = {
                        project_id: projectValue,
                        task_type: taskTypeEl ? taskTypeEl.value : '',
                        page_ids: pageEl ? Array.from(pageEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [],
                        environment_ids: envEl ? Array.from(envEl.selectedOptions || []).map(function(o){ return o.value; }).filter(Boolean) : [],
                        testing_type: testingTypeEl ? testingTypeEl.value : '',
                        issue_id: '',
                        hours: hoursEl.value,
                        description: descEl ? descEl.value : '',
                        is_utilized: 1
                    };

                    var pendingFd = new FormData();
                    pendingFd.append('action', 'save_pending');
                    pendingFd.append('date', date);
                    pendingFd.append('status', document.getElementById('calStatus') ? document.getElementById('calStatus').value : 'not_updated');
                    pendingFd.append('notes', document.getElementById('calNotes') ? document.getElementById('calNotes').value : '');
                    pendingFd.append('personal_note', document.getElementById('calPersonalNote') ? document.getElementById('calPersonalNote').value : '');
                    pendingFd.append('pending_time_logs', JSON.stringify([pendingEntry]));
                    pendingFd.append('pending_time_logs_append', '1');

                    fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php', {
                        method: 'POST',
                        body: pendingFd,
                        credentials: 'same-origin'
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        if (resp && resp.success) {
                            loadProductionHours(date);
                            if (window._myCalendar && typeof window._myCalendar.refetchEvents === 'function') window._myCalendar.refetchEvents();
                            addModalButtons(date);
                            try {
                                var logModalInst = bootstrap.Modal.getOrCreateInstance(document.getElementById('calendarLogHoursModal'));
                                logModalInst.hide();
                            } catch (err) {}
                            if (calFormEl) calFormEl.reset();
                            if (pageColEl) pageColEl.style.display = 'none';
                            if (envColEl) envColEl.style.display = 'none';
                            notifyCalendar('Hours saved to pending changes.', 'success');
                        } else {
                            notifyCalendar('Failed to save pending hours: ' + ((resp && (resp.error || resp.message)) || 'Unknown error'), 'danger');
                        }
                    })
                    .catch(function(err){
                        notifyCalendar('Error saving pending hours: ' + err.message, 'danger');
                    })
                    .finally(function(){
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            delete submitBtn.dataset.logging;
                            submitBtn.textContent = oldLabel || 'Log Hours';
                        }
                    });
                });
                return false;
            }

            fetch(window._calendarConfig.baseDir + '/api/project_hours.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){
                    return r.text().then(function(text){
                        var parsed = null;
                        try { parsed = JSON.parse(text); } catch (e) {}
                        return { ok: r.ok, status: r.status, body: parsed, raw: text };
                    });
                })
                .then(function(resp){
                    if (resp && resp.body && resp.body.success) {
                        loadProductionHours(date);
                        if (window._myCalendar && typeof window._myCalendar.refetchEvents === 'function') window._myCalendar.refetchEvents();
                        addModalButtons(date);
                        try {
                            var logModalInst = bootstrap.Modal.getOrCreateInstance(document.getElementById('calendarLogHoursModal'));
                            logModalInst.hide();
                        } catch (err) {}
                        if (calFormEl) calFormEl.reset();
                        if (pageColEl) pageColEl.style.display = 'none';
                        if (envColEl) envColEl.style.display = 'none';
                        notifyCalendar('Hours logged successfully.', 'success');
                    } else {
                        var msg = 'Failed to log hours.';
                        if (resp && resp.body && (resp.body.error || resp.body.message)) {
                            msg += ' ' + (resp.body.error || resp.body.message);
                        } else if (resp && resp.raw) {
                            msg += ' Response: ' + resp.raw.substring(0, 200);
                        }
                        notifyCalendar(msg, 'danger');
                    }
                })
                .catch(function(err){
                    notifyCalendar('Error logging hours: ' + err.message, 'danger');
                })
                .finally(function(){
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        delete submitBtn.dataset.logging;
                        submitBtn.textContent = oldLabel || 'Log Hours';
                    }
                });
        } catch (err) {
            var submitBtnOnError = document.getElementById('logTimeBtn');
            if (submitBtnOnError) {
                submitBtnOnError.disabled = false;
                delete submitBtnOnError.dataset.logging;
                submitBtnOnError.textContent = 'Log Hours';
            }
            notifyCalendar('Log form error: ' + err.message, 'danger');
        }
        return false;
    }

    window.submitCalendarLogHours = submitCalendarLogHours;
    var globalCalForm = document.getElementById('logProductionHoursForm');
    if (globalCalForm && !globalCalForm.dataset.boundSubmit) {
        globalCalForm.addEventListener('submit', submitCalendarLogHours);
        globalCalForm.dataset.boundSubmit = '1';
    }
    var logTimeBtn = document.getElementById('logTimeBtn');
    if (logTimeBtn && !logTimeBtn.dataset.boundClick) {
        logTimeBtn.addEventListener('click', submitCalendarLogHours);
        logTimeBtn.dataset.boundClick = '1';
    }
    var openLogBtn = document.getElementById('openLogHoursModalBtn');
    if (openLogBtn && !openLogBtn.dataset.boundClick) {
        openLogBtn.addEventListener('click', function() {
            try {
                var d = '';
                var dEl = document.getElementById('calDate');
                if (dEl && dEl.value) d = String(dEl.value).slice(0, 10);
                if (!d) {
                    d = String((document.getElementById('calendarEditModal').dataset.activeDate || lastClickedDate || '')).slice(0, 10);
                }
                if (dEl && d) dEl.value = d;
            } catch (err) {}
        });
        openLogBtn.dataset.boundClick = '1';
    }

    // Handle admin user selection change
    var adminUserSelect = document.getElementById('admin_user_select');
    if (adminUserSelect) {
        adminUserSelect.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    }

    // Handle edit request filter change
    var editRequestFilter = document.getElementById('edit_request_filter');
    if (editRequestFilter) {
        editRequestFilter.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    }

    document.querySelectorAll('.status-filter-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            calendar.refetchEvents();
        });
    });

    // Handle modal form submission
    document.getElementById('calendarEditForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        formData.append('update_status', '1');
        formData.set('notes', document.getElementById('calNotes').value);
        formData.set('personal_note', document.getElementById('calPersonalNote').value);

        fetch(window._calendarConfig.baseDir + '/modules/my_daily_status.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                calendar.refetchEvents();
                var modal = bootstrap.Modal.getInstance(document.getElementById('calendarEditModal'));
                if (modal) modal.hide();
                showToast('Status updated successfully!', 'success');
            } else {
                showToast('Failed to update status: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
        .catch(error => {
            showToast('Request failed. Please try again.', 'danger');
        });
    });

    // Handle edit request form submission
    document.getElementById('editRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        var date = document.getElementById('requestDate').value;
        var reason = document.getElementById('editReason').value;
        
        if (!reason.trim()) {
            showToast('Please provide a reason for the edit request.', 'warning');
            return;
        }
        
        sendEditRequestWithReason(date, reason);
    });

    // Initialize modal event handlers
    document.getElementById('calendarEditModal').addEventListener('shown.bs.modal', function() {
        var dateFromField = '';
        try { dateFromField = document.getElementById('calDate').value || ''; } catch (e) {}
        var dateFromDataset = '';
        try { dateFromDataset = document.getElementById('calendarEditModal').dataset.activeDate || ''; } catch (e) {}
        var activeDate = dateFromField || dateFromDataset || lastClickedDate || '';
        if (!activeDate) {
            var t = new Date();
            activeDate = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
            try { document.getElementById('calDate').value = activeDate; } catch (e) {}
        }
        if (activeDate) {
            addModalButtons(activeDate);
        }

        // Initialize calendar modal production-hours quick form bindings
        (function(){
            var projSel = document.querySelector('#logProductionHoursForm select[name="project_id"]') || document.getElementById('productionProjectSelect');
            var pageSel = document.getElementById('productionPageSelect');
            var envSel = document.getElementById('productionEnvSelect');
            var taskSel = document.getElementById('taskTypeSelect');
            var pageCol = document.getElementById('pageTestingContainer');
            var envCol = document.getElementById('productionEnvCol');
            if (!projSel) return;
            if (!projSel.dataset.boundChange) {
                projSel.addEventListener('change', function(){
                    var pid = projSel.value;
                    if (!pageSel) return;
                    pageSel.innerHTML = '<option>Loading pages...</option>';
                    fetch(window._calendarConfig.baseDir + '/api/tasks.php?project_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
                        .then(r => r.json()).then(function(pages){
                            pageSel.innerHTML = '';
                            pageSel.appendChild(new Option('(none)',''));
                            if (Array.isArray(pages)) pages.forEach(function(pg){ pageSel.appendChild(new Option(pg.page_name||pg.title||('Page '+pg.id), pg.id)); });
                        }).catch(function(){ pageSel.innerHTML = '<option value="">Error loading pages</option>'; });
                });
                projSel.dataset.boundChange = '1';
            }

            if (pageSel && !pageSel.dataset.boundChange) {
                pageSel.addEventListener('change', function(){
                    if (!envSel) return;
                    var val = pageSel.value;
                    var pid = Array.isArray(val) ? (val[0] || '') : (val || '');
                    envSel.innerHTML = '<option>Loading envs...</option>';
                    if (!pid) { envSel.innerHTML = '<option value="">Select page first</option>'; return; }
                    fetch(window._calendarConfig.baseDir + '/api/tasks.php?page_id=' + encodeURIComponent(pid), {credentials:'same-origin'})
                        .then(r => r.json()).then(function(page){
                            envSel.innerHTML = '';
                            if (page && page.environments && page.environments.length) {
                                page.environments.forEach(function(env){ envSel.appendChild(new Option(env.name||env.environment_name||('Env '+(env.id||env.environment_id)), env.id||env.environment_id)); });
                            } else envSel.appendChild(new Option('No environments',''));
                        }).catch(function(){ envSel.innerHTML = '<option value="">Error loading environments</option>'; });
                });
                pageSel.dataset.boundChange = '1';
            }

            if (taskSel && !taskSel.dataset.boundChange) {
                taskSel.addEventListener('change', function(){
                    var t = taskSel.value;
                    if (t === 'page_testing' || t === 'page_qa') { pageCol.style.display='block'; envCol.style.display='block'; }
                    else { pageCol.style.display='none'; envCol.style.display='none'; }
                });
                taskSel.dataset.boundChange = '1';
            }

            var calForm = document.getElementById('logProductionHoursForm');
            if (calForm && !calForm.dataset.boundSubmit) {
                calForm.addEventListener('submit', submitCalendarLogHours);
                calForm.dataset.boundSubmit = '1';
            }
        })();
    });

    document.getElementById('calendarEditModal').addEventListener('hidden.bs.modal', function() {
        try {
            var logModalEl = document.getElementById('calendarLogHoursModal');
            var logModalInst = bootstrap.Modal.getInstance(logModalEl);
            if (logModalInst) logModalInst.hide();
        } catch (e) {}
        resetModal();
    });

    // Move the log form into dedicated log-hours modal body
    (function moveLogFormToDedicatedModal() {
        try {
            var formContainer = document.getElementById('calendarModalLogFormContainer');
            var targetBody = document.getElementById('calendarLogHoursModalBody');
            if (formContainer && targetBody && formContainer.parentElement !== targetBody) {
                formContainer.classList.remove('d-none');
                targetBody.appendChild(formContainer);
            }
        } catch (e) {}
    })();
});