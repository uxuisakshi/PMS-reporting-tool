/* My Daily Status JS - extracted from modules/my_daily_status.php */

(function () {
    var cfg = window._dailyStatusConfig || {};
    // Toast on page load
    var toastData = cfg.toastData;
    if (toastData && toastData.message) {
        function fireToast() {
            if (typeof window.showToast !== 'function') return false;
            window.showToast(toastData.message, toastData.type || 'info');
            return true;
        }
        if (!fireToast()) { setTimeout(fireToast, 120); }
    }

    // Past-date edit logic
    var isPast = !!cfg.isPast;
    var hasPending = !!cfg.hasPending;
    var hasApprovedAccess = !!cfg.hasApprovedAccess;
    var hasSubmittedPending = !!cfg.hasSubmittedPending;
    var date = cfg.date || '';
    if (!isPast) return;

    var editBtn = document.getElementById('editToggleBtn');
    var saveBtn = document.getElementById('saveRequestBtn');
    var submitPendingBtn = document.getElementById('submitPendingBtn');
    var updateBtn = document.getElementById('updateStatusBtn');
    var statusSelect = document.getElementById('statusSelect');
    var notesField = document.getElementById('notesField');
    var personalNote = document.getElementById('personal_note');

    function loadDraftData() {
        return fetch(window.location.pathname + '?action=get_personal_note&date=' + encodeURIComponent(date))
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data.success && data.is_pending) {
                    if (statusSelect) statusSelect.value = data.status || statusSelect.value;
                    if (notesField) notesField.value = data.notes || '';
                    if (personalNote) personalNote.value = data.personal_note || '';
                }
            })
            .catch(function(){});
    }

    function buildPendingPayload() {
        var fd = new FormData();
        fd.append('action', 'save_pending');
        fd.append('date', date);
        fd.append('status', statusSelect ? statusSelect.value : '');
        fd.append('notes', notesField ? notesField.value : '');
        fd.append('personal_note', personalNote ? personalNote.value : '');
        try {
            var pendingLogs = [];
            var proj = document.querySelector('#logProductionHoursForm select[name="project_id"]');
            if (proj && proj.value) {
                var taskTypeSel = document.querySelector('#logProductionHoursForm select[name="task_type"]');
                pendingLogs.push({
                    project_id: proj.value || null,
                    task_type: taskTypeSel ? taskTypeSel.value : null,
                    page_ids: Array.from(document.querySelectorAll('#productionPageSelect option:checked')).map(function(o){ return o.value; }).filter(Boolean),
                    environment_ids: Array.from(document.querySelectorAll('#productionEnvSelect option:checked')).map(function(o){ return o.value; }).filter(Boolean),
                    testing_type: document.querySelector('#testingTypeSelect') ? document.querySelector('#testingTypeSelect').value : null,
                    issue_id: document.querySelector('#productionIssueSelect') ? document.querySelector('#productionIssueSelect').value : null,
                    hours: document.getElementById('logHoursInput') ? document.getElementById('logHoursInput').value : null,
                    description: document.getElementById('logDescriptionInput') ? document.getElementById('logDescriptionInput').value : null,
                    is_utilized: document.querySelector('#logProductionHoursForm input[name="is_utilized"]') ? (document.querySelector('#logProductionHoursForm input[name="is_utilized"]').checked ? 1 : 0) : 1
                });
            }
            fd.append('pending_time_logs', JSON.stringify(pendingLogs));
        } catch (e) {
            fd.append('pending_time_logs', '[]');
        }
        return fd;
    }

    function setEditable(on) {
        if (statusSelect) statusSelect.disabled = !on;
        if (notesField) notesField.disabled = !on;
        if (personalNote) personalNote.disabled = !on;
        var prodProj = document.querySelector('#logProductionHoursForm select[name="project_id"]');
        var pageSel = document.getElementById('productionPageSelect');
        var envSel = document.getElementById('productionEnvSelect');
        var testingSel = document.getElementById('testingTypeSelect');
        var issueSel = document.getElementById('productionIssueSelect');
        var hoursInput = document.getElementById('logHoursInput');
        var descInput = document.getElementById('logDescriptionInput');
        var logBtn = document.getElementById('logTimeBtn');
        if (prodProj) prodProj.disabled = !on;
        if (pageSel) pageSel.disabled = !on;
        if (envSel) envSel.disabled = !on;
        if (testingSel) testingSel.disabled = !on;
        if (issueSel) issueSel.disabled = !on;
        if (hoursInput) hoursInput.disabled = !on;
        if (descInput) descInput.disabled = !on;
        if (logBtn) logBtn.disabled = !on;
        if (on) {
            if (saveBtn) saveBtn.style.display = 'block';
            if (submitPendingBtn) submitPendingBtn.style.display = 'block';
            if (updateBtn) updateBtn.style.display = 'none';
            var pnc = document.getElementById('personalNoteContainer');
            if (pnc) pnc.style.display = 'block';
        } else {
            if (saveBtn) saveBtn.style.display = hasApprovedAccess ? 'block' : 'none';
            if (submitPendingBtn) submitPendingBtn.style.display = hasApprovedAccess ? 'block' : 'none';
            if (updateBtn) updateBtn.style.display = 'none';
            var pnc2 = document.getElementById('personalNoteContainer');
            if (pnc2) pnc2.style.display = 'none';
        }
    }

    if (hasPending || hasApprovedAccess) {
        loadDraftData();
    }

    if (hasApprovedAccess) {
        setEditable(true);
    } else if (hasPending || hasSubmittedPending) {
        setEditable(false);
    } else {
        setEditable(false);
    }

    if (editBtn) {
        editBtn.addEventListener('click', function() {
            var fd = new FormData();
            fd.append('action', 'request_edit');
            fd.append('date', date);
            fd.append('reason', 'Past-date edit access requested from daily status.');
            editBtn.disabled = true;
            fetch(window.location.pathname + '?action=request_edit', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success) {
                        throw new Error((resp && (resp.error || resp.message)) || 'Failed to request edit access.');
                    }
                    if (typeof showToast === 'function') {
                        showToast(resp.message || 'Edit access request sent to admin.', 'success');
                    }
                    window.location.reload();
                })
                .catch(function(err) {
                    if (typeof showToast === 'function') {
                        showToast(err.message || 'Failed to request edit access.', 'danger');
                    }
                    editBtn.disabled = false;
                });
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            var fd = buildPendingPayload();
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            fetch(window.location.pathname + '?action=save_pending', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (!resp || !resp.success) {
                    throw new Error((resp && (resp.error || resp.message)) || 'Failed to save pending changes.');
                }
                if (typeof showToast === 'function') {
                    showToast('Pending changes saved.', 'success');
                }
            })
            .catch(function(err) {
                if (typeof showToast === 'function') showToast('Error: ' + (err.message || 'unknown'), 'danger');
            })
            .finally(function() {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Pending Changes';
            });
        });
    }

    if (submitPendingBtn) {
        submitPendingBtn.addEventListener('click', function() {
            var fd = buildPendingPayload();
            submitPendingBtn.disabled = true;
            submitPendingBtn.textContent = 'Submitting...';

            fetch(window.location.pathname + '?action=save_pending', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success) {
                        throw new Error((resp && (resp.error || resp.message)) || 'Failed to save pending changes.');
                    }
                    var submitFd = new FormData();
                    submitFd.append('action', 'submit_pending');
                    submitFd.append('date', date);
                    return fetch(window.location.pathname + '?action=submit_pending', { method: 'POST', body: submitFd });
                })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success) {
                        throw new Error((resp && (resp.error || resp.message)) || 'Failed to submit pending changes.');
                    }
                    if (typeof showToast === 'function') {
                        showToast('Pending changes submitted for admin review.', 'success');
                    }
                    window.location.reload();
                })
                .catch(function(err) {
                    if (typeof showToast === 'function') showToast(err.message || 'Failed to submit pending changes.', 'danger');
                    submitPendingBtn.disabled = false;
                })
                .finally(function() {
                    submitPendingBtn.textContent = 'Submit Pending Changes';
                });
        });
    }
})();

/* ---- DOMContentLoaded: Production & Bench hours form handling ---- */
document.addEventListener('DOMContentLoaded', function() {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';

    var productionProjectSelect = document.querySelector('#logProductionHoursForm select[name="project_id"]');
    var taskTypeSelect = document.getElementById('taskTypeSelect');
    var pageTestingContainer = document.getElementById('pageTestingContainer');
    var projectPhaseContainer = document.getElementById('projectPhaseContainer');
    var genericTaskContainer = document.getElementById('genericTaskContainer');
    var regressionContainer = document.getElementById('regressionContainer');
    var productionPageSelect = document.getElementById('productionPageSelect');
    var productionEnvSelect = document.getElementById('productionEnvSelect');
    var productionIssueSelect = document.getElementById('productionIssueSelect');
    var projectPhaseSelect = document.getElementById('projectPhaseSelect');
    var genericCategorySelect = document.getElementById('genericCategorySelect');
    var testingTypeSelect = document.getElementById('testingTypeSelect');
    var productionDescInput = document.querySelector('#logProductionHoursForm input[name="description"]');
    var benchActivitySelect = document.querySelector('#logBenchHoursForm select[name="bench_activity"]');
    var benchDescInput = document.querySelector('#logBenchHoursForm input[name="description"]');

    function clearSelect(sel) {
        if (sel) sel.innerHTML = '<option value="">Select</option>';
    }

    function hideAllTaskContainers() {
        if (pageTestingContainer) pageTestingContainer.style.display = 'none';
        if (projectPhaseContainer) projectPhaseContainer.style.display = 'none';
        if (genericTaskContainer) genericTaskContainer.style.display = 'none';
        if (regressionContainer) regressionContainer.style.display = 'none';
    }

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', function() {
            var taskType = this.value;
            var projectId = productionProjectSelect ? productionProjectSelect.value : '';
            hideAllTaskContainers();
            if (!projectId) { if (typeof showToast === 'function') showToast('Please select a project first', 'warning'); return; }
            switch (taskType) {
                case 'page_testing': case 'page_qa': if (pageTestingContainer) pageTestingContainer.style.display = 'block'; loadProjectPages(); break;
                case 'regression_testing': if (regressionContainer) regressionContainer.style.display = 'block'; loadRegressionSummary(); break;
                case 'project_phase': if (projectPhaseContainer) projectPhaseContainer.style.display = 'block'; loadProjectPhases(); break;
                case 'generic_task': if (genericTaskContainer) genericTaskContainer.style.display = 'block'; loadGenericCategories(); break;
            }
        });
    }

    if (productionProjectSelect) {
        productionProjectSelect.addEventListener('change', function() {
            var projectId = this.value;
            var taskType = taskTypeSelect ? taskTypeSelect.value : '';
            clearSelect(productionPageSelect);
            clearSelect(productionEnvSelect);
            clearSelect(projectPhaseSelect);
            if (!projectId) { hideAllTaskContainers(); return; }
            if (taskType === 'page_testing' || taskType === 'page_qa') { if (pageTestingContainer) pageTestingContainer.style.display = 'block'; loadProjectPages(); }
            else if (taskType === 'regression_testing') { if (regressionContainer) regressionContainer.style.display = 'block'; loadRegressionSummary(); }
            else if (taskType === 'project_phase') { if (projectPhaseContainer) projectPhaseContainer.style.display = 'block'; loadProjectPhases(); }
            else if (taskType === 'generic_task') { if (genericTaskContainer) genericTaskContainer.style.display = 'block'; loadGenericCategories(); }
        });
    }

    function loadProjectPages() {
        var projectId = productionProjectSelect ? productionProjectSelect.value : '';
        if (!projectId) return;
        if (productionPageSelect) productionPageSelect.innerHTML = '<option value="">Loading pages...</option>';
        fetch(baseDir + '/api/tasks.php?project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(pages) {
            if (productionPageSelect) productionPageSelect.innerHTML = '';
            if (pages && Array.isArray(pages) && pages.length > 0) {
                pages.forEach(function(pg) {
                    var opt = document.createElement('option');
                    opt.value = pg.id;
                    opt.textContent = pg.page_name || pg.title || pg.url || ('Page ' + pg.id);
                    if (productionPageSelect) productionPageSelect.appendChild(opt);
                });
            } else {
                if (productionPageSelect) productionPageSelect.innerHTML = '<option value="">No pages found for this project</option>';
            }
        }).catch(function(error) { if (productionPageSelect) productionPageSelect.innerHTML = '<option value="">Error: ' + error.message + '</option>'; });
    }

    function loadProjectPhases() {
        var projectId = productionProjectSelect ? productionProjectSelect.value : '';
        if (!projectId) return;
        function formatPhaseLabel(raw) {
            var txt = String(raw || '').trim();
            if (!txt) return '';
            var known = { 'po_received': 'PO received', 'scoping_confirmation': 'Scoping confirmation', 'testing': 'Testing', 'regression': 'Regression', 'training': 'Training', 'vpat_acr': 'VPAT ACR' };
            if (known[txt]) return known[txt];
            return txt.replace(/[_-]+/g, ' ').split(/\s+/).map(function(w) {
                var lw = w.toLowerCase();
                if (lw === 'po') return 'PO'; if (lw === 'qa') return 'QA'; if (lw === 'uat') return 'UAT';
                if (lw === 'vpat') return 'VPAT'; if (lw === 'acr') return 'ACR';
                return lw.charAt(0).toUpperCase() + lw.slice(1);
            }).join(' ');
        }
        if (projectPhaseSelect) projectPhaseSelect.innerHTML = '<option value="">Loading phases...</option>';
        fetch(baseDir + '/api/projects.php?action=get_phases&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
        .then(function(res) { return res.text().then(function(txt) { if (!res.ok) throw new Error('HTTP ' + res.status); try { return JSON.parse(txt); } catch(e) { throw new Error('Invalid JSON'); } }); })
        .then(function(phases) {
            if (projectPhaseSelect) projectPhaseSelect.innerHTML = '<option value="">Select project phase</option>';
            if (phases && Array.isArray(phases) && phases.length > 0) {
                phases.forEach(function(phase) {
                    var opt = document.createElement('option');
                    opt.value = phase.id;
                    opt.textContent = formatPhaseLabel(phase.phase_name || phase.name || phase.id) + ' (' + (phase.actual_hours || 0) + '/' + (phase.planned_hours || 0) + ' hrs)';
                    if (projectPhaseSelect) projectPhaseSelect.appendChild(opt);
                });
            } else {
                if (projectPhaseSelect) projectPhaseSelect.innerHTML = '<option value="">No phases found</option>';
            }
        }).catch(function(error) { if (projectPhaseSelect) projectPhaseSelect.innerHTML = '<option value="">Error: ' + (error.message || 'Unknown') + '</option>'; });
    }

    function loadRegressionSummary() {
        function escHtml(s) { if (!s) return ''; return String(s).replace(/[&"'<>]/g, function(m) { return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[m]; }); }
        var projectId = productionProjectSelect ? productionProjectSelect.value : '';
        var container = document.getElementById('regressionSummary');
        if (!container) return;
        if (!projectId) { container.textContent = 'Select a project to view regression summary'; return; }
        container.textContent = 'Loading...';
        fetch(baseDir + '/api/regression_actions.php?action=get_stats&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(json) {
            if (!json || !json.success) { container.textContent = 'Error loading regression summary'; return; }
            var s = json || {};
            var total = s.issues_total || 0;
            var attemptedTotal = s.attempted_issues_total || 0;
            var attemptedStatus = s.attempted_status_counts || {};
            var statusCounts = s.status_counts || {};
            var html = '<div><strong>Total issues:</strong> ' + total + '</div>';
            html += '<div><strong>Attempted during regression:</strong> ' + attemptedTotal + '</div>';
            html += '<div class="mt-1"><strong>Attempted status breakdown:</strong><br/>';
            if (Object.keys(attemptedStatus).length === 0) html += '<small class="text-muted">No attempted issues logged yet</small>';
            else { for (var k in attemptedStatus) html += '<div>' + k + ': ' + attemptedStatus[k] + '</div>'; }
            html += '</div><div class="mt-2"><strong>Regression tasks:</strong><br/>';
            if (Object.keys(statusCounts).length === 0) html += '<small class="text-muted">No regression tasks</small>';
            else { for (var st in statusCounts) html += '<div>' + st + ': ' + statusCounts[st] + '</div>'; }
            html += '</div>';
            var attemptsByUser = s.attempts_by_user || {};
            var userCounts = s.user_counts || [];
            var userMap = {};
            userCounts.forEach(function(u){ userMap[u.id] = u.full_name || ('User ' + u.id); });
            html += '<div class="mt-2"><strong>Attempts by user:</strong><br/>';
            if (Object.keys(attemptsByUser).length === 0) html += '<small class="text-muted">No attempts recorded</small>';
            else { for (var uid in attemptsByUser) { var issues = attemptsByUser[uid] || []; var uname = userMap[uid] || ('User ' + uid); html += '<div class="mt-1"><strong>' + escHtml(uname) + '</strong> — ' + issues.length + ' issue(s)<div class="ms-3">'; issues.forEach(function(it){ html += '<div><strong>' + escHtml(it.issue_key || ('#' + it.issue_id)) + '</strong> — ' + escHtml(it.last_status || '') + ' <small class="text-muted">' + escHtml(it.last_changed_at || '') + '</small></div>'; }); html += '</div></div>'; } }
            html += '</div>';
            container.innerHTML = html;
        }).catch(function(){ container.textContent = 'Error loading regression summary'; });
    }

    function loadGenericCategories() {
        if (genericCategorySelect) genericCategorySelect.innerHTML = '<option value="">Loading categories...</option>';
        fetch(baseDir + '/api/generic_tasks.php?action=get_categories', { credentials: 'same-origin' })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(categories) {
            if (genericCategorySelect) genericCategorySelect.innerHTML = '<option value="">Select category</option>';
            if (categories && Array.isArray(categories) && categories.length > 0) {
                categories.forEach(function(cat) {
                    var opt = document.createElement('option');
                    opt.value = cat.id;
                    opt.textContent = cat.name + (cat.description ? ' - ' + cat.description : '');
                    if (genericCategorySelect) genericCategorySelect.appendChild(opt);
                });
            } else {
                if (genericCategorySelect) genericCategorySelect.innerHTML = '<option value="">No categories found</option>';
            }
        }).catch(function(error) { if (genericCategorySelect) genericCategorySelect.innerHTML = '<option value="">Error: ' + error.message + '</option>'; });
    }

    function loadProjectIssues(projectId, pageId) {
        if (!projectId || !productionIssueSelect) return;
        productionIssueSelect.innerHTML = '<option value="">Issues subsystem disabled</option>';
        var url = baseDir + '/api/regression_actions.php?action=get_project_issues&project_id=' + encodeURIComponent(projectId);
        if (pageId) url += '&page_id=' + encodeURIComponent(pageId);
        fetch(url, { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            productionIssueSelect.innerHTML = '<option value="">Select issue (optional)</option>';
            if (data && data.issues && Array.isArray(data.issues)) {
                data.issues.forEach(function(it) {
                    var opt = document.createElement('option');
                    opt.value = it.id;
                    opt.textContent = (it.issue_key ? (it.issue_key + ' - ') : '') + (it.title || ('Issue ' + it.id));
                    productionIssueSelect.appendChild(opt);
                });
            }
        }).catch(function(){ productionIssueSelect.innerHTML = '<option value="">Issues subsystem disabled</option>'; });
    }

    if (productionPageSelect) {
        productionPageSelect.addEventListener('change', function() {
            var selectedPages = Array.from(this.selectedOptions).map(function(o){ return o.value; }).filter(function(v){ return v !== ''; });
            clearSelect(productionEnvSelect);
            if (selectedPages.length === 0) return;
            var firstPageId = selectedPages[0];
            fetch(baseDir + '/api/tasks.php?page_id=' + encodeURIComponent(firstPageId), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(page) {
                if (productionEnvSelect) productionEnvSelect.innerHTML = '';
                if (page.environments && page.environments.length > 0) {
                    page.environments.forEach(function(env) {
                        var opt = document.createElement('option');
                        opt.value = env.id;
                        opt.textContent = env.name + (env.status ? ' (' + env.status + ')' : '');
                        if (productionEnvSelect) productionEnvSelect.appendChild(opt);
                    });
                } else {
                    if (productionEnvSelect) productionEnvSelect.innerHTML = '<option value="">No environments found</option>';
                }
                if (productionDescInput && (!productionDescInput.value || productionDescInput.value.trim() === '')) {
                    productionDescInput.value = selectedPages.length === 1 ? (page.page_name || page.title || '') : 'Multiple pages testing';
                }
                var issueCont = document.getElementById('productionIssueContainer');
                if (testingTypeSelect && testingTypeSelect.value === 'regression') {
                    if (issueCont) issueCont.style.display = 'block';
                    loadProjectIssues(productionProjectSelect ? productionProjectSelect.value : '', firstPageId);
                } else {
                    if (issueCont) issueCont.style.display = 'none';
                }
            }).catch(function(){ if (productionEnvSelect) productionEnvSelect.innerHTML = '<option value="">Error loading environments</option>'; });
        });
    }

    if (benchActivitySelect) {
        benchActivitySelect.addEventListener('change', function() {
            var activity = this.value;
            if (benchDescInput && (!benchDescInput.value || benchDescInput.value.trim() === '')) {
                var descriptions = { 'training': 'Training session on ', 'learning': 'Learning/Research on ', 'documentation': 'Documentation work on ', 'meetings': 'Meeting: ', 'admin': 'Administrative task: ', 'waiting': 'Waiting for assignment', 'other': 'Other activity: ' };
                benchDescInput.value = descriptions[activity] || '';
            }
        });
    }

    if (testingTypeSelect) {
        testingTypeSelect.addEventListener('change', function() {
            var v = this.value;
            var issueCont = document.getElementById('productionIssueContainer');
            if (v === 'regression') {
                if (issueCont) issueCont.style.display = 'block';
                var firstPage = productionPageSelect && productionPageSelect.selectedOptions && productionPageSelect.selectedOptions.length ? productionPageSelect.selectedOptions[0].value : null;
                loadProjectIssues(productionProjectSelect ? productionProjectSelect.value : '', firstPage);
            } else {
                if (issueCont) issueCont.style.display = 'none';
            }
        });
    }

    function ensureLogPreviewModal() {
        var existing = document.getElementById('logPreviewModal');
        if (existing) return existing;
        var wrap = document.createElement('div');
        wrap.innerHTML = '<div class="modal fade" id="logPreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirm Log Submission</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div id="logPreviewBody" class="small"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="logPreviewConfirmBtn">Confirm & Submit</button></div></div></div></div>';
        document.body.appendChild(wrap.firstChild);
        return document.getElementById('logPreviewModal');
    }

    function escapeHtml(val) {
        if (val === null || val === undefined) return '';
        return String(val).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function getSelectedText(form, selector, multiple) {
        var el = form.querySelector(selector);
        if (!el) return '';
        if (multiple) { return Array.from(el.selectedOptions || []).map(function(o){ return o.textContent.trim(); }).filter(Boolean).join(', '); }
        var opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
        return opt ? opt.textContent.trim() : '';
    }

    function rowHtml(label, value) { return '<tr><th class="pe-3 text-nowrap">' + escapeHtml(label) + '</th><td>' + escapeHtml(value || '-') + '</td></tr>'; }

    function buildProductionPreview(form) {
        var taskTypeValue = form.querySelector('select[name="task_type"]') ? form.querySelector('select[name="task_type"]').value : '';
        var html = '<table class="table table-sm mb-0"><tbody>';
        html += rowHtml('Section', 'Log Production Hours');
        html += rowHtml('Project', getSelectedText(form, 'select[name="project_id"]', false));
        html += rowHtml('Task Type', getSelectedText(form, 'select[name="task_type"]', false));
        if (taskTypeValue === 'page_testing' || taskTypeValue === 'page_qa' || taskTypeValue === 'regression_testing') {
            var pages = getSelectedText(form, '#productionPageSelect', true);
            if (pages) html += rowHtml('Page/Screen', pages);
            var envs = getSelectedText(form, '#productionEnvSelect', true);
            if (envs) html += rowHtml('Environments', envs);
            var testingType = getSelectedText(form, '#testingTypeSelect', false);
            if (testingType) html += rowHtml('Testing Type', testingType);
            var issueText = getSelectedText(form, '#productionIssueSelect', false);
            if (issueText) html += rowHtml('Issue', issueText);
        } else if (taskTypeValue === 'project_phase') {
            var phaseText = getSelectedText(form, '#projectPhaseSelect', false);
            if (phaseText) html += rowHtml('Project Phase', phaseText);
            var phaseActivity = getSelectedText(form, 'select[name="phase_activity"]', false);
            if (phaseActivity) html += rowHtml('Phase Activity', phaseActivity);
        } else if (taskTypeValue === 'generic_task') {
            var genericCat = getSelectedText(form, '#genericCategorySelect', false);
            if (genericCat) html += rowHtml('Task Category', genericCat);
            var genericDetailEl = form.querySelector('input[name="generic_task_detail"]');
            if (genericDetailEl && genericDetailEl.value.trim()) html += rowHtml('Task Details', genericDetailEl.value.trim());
        }
        var hoursEl = form.querySelector('input[name="hours_spent"]');
        html += rowHtml('Hours', hoursEl ? hoursEl.value : '');
        var descEl = form.querySelector('input[name="description"]');
        html += rowHtml('Description', descEl ? descEl.value : '');
        html += '</tbody></table>';
        return html;
    }

    function buildBenchPreview(form) {
        var html = '<table class="table table-sm mb-0"><tbody>';
        html += rowHtml('Section', 'Log Off-Production/Bench Hours');
        html += rowHtml('Activity Type', getSelectedText(form, 'select[name="bench_activity"]', false));
        var hoursEl = form.querySelector('input[name="hours_spent"]');
        html += rowHtml('Hours', hoursEl ? hoursEl.value : '');
        var descEl = form.querySelector('input[name="description"]');
        html += rowHtml('Description', descEl ? descEl.value : '');
        html += '</tbody></table>';
        return html;
    }

    function setupLogPreview(form, mode) {
        if (!form) return;
        form.addEventListener('submit', function(e) {
            if (form.dataset.previewApproved === '1') { form.dataset.previewApproved = ''; return; }
            if (!form.checkValidity()) return;
            e.preventDefault();
            var modalEl = ensureLogPreviewModal();
            var bodyEl = document.getElementById('logPreviewBody');
            var confirmBtn = document.getElementById('logPreviewConfirmBtn');
            if (!modalEl || !bodyEl || !confirmBtn) { form.dataset.previewApproved = '1'; if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit(); return; }
            bodyEl.innerHTML = mode === 'bench' ? buildBenchPreview(form) : buildProductionPreview(form);
            confirmBtn.disabled = false;
            confirmBtn.onclick = function() {
                confirmBtn.disabled = true;
                form.dataset.previewApproved = '1';
                try { bootstrap.Modal.getOrCreateInstance(modalEl).hide(); } catch(err) {}
                if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
            };
            try { bootstrap.Modal.getOrCreateInstance(modalEl).show(); } catch(err) { form.dataset.previewApproved = '1'; if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit(); }
        });
    }

    setupLogPreview(document.getElementById('logProductionHoursForm'), 'production');
    setupLogPreview(document.getElementById('logBenchHoursForm'), 'bench');
});

/* ---- Delete & Edit Log Request handlers ---- */
var reqEditProjects = (window._dailyStatusConfig && window._dailyStatusConfig.assignedProjects) ? window._dailyStatusConfig.assignedProjects : [];

function handleDeleteLog(logId, dateStr) {
    var cfg = window._dailyStatusConfig || {};
    var isAdmin = !!cfg.isAdmin;
    var todayStr = cfg.today || '';
    var isToday = String(dateStr || '') === todayStr;
    var directDelete = isAdmin || isToday;
    var msg = directDelete ? 'Delete this log?' : 'Log deletion requires admin approval. A request will be sent to admin. Do you still want to delete?';
    if (typeof confirmModal === 'function') {
        confirmModal(msg, function() {
            var key = directDelete ? 'delete_log' : 'delete_log_request';
            window.location.href = '?date=' + encodeURIComponent(dateStr) + '&' + key + '=' + encodeURIComponent(logId);
        }, { title: directDelete ? 'Delete Log' : 'Request Deletion Approval', confirmText: directDelete ? 'Delete' : 'Send Request', confirmClass: directDelete ? 'btn-danger' : 'btn-primary' });
    }
    return false;
}

function ensureEditRequestModal() {
    var existing = document.getElementById('logEditRequestModal');
    if (existing) return existing;
    var wrap = document.createElement('div');
    wrap.innerHTML = '<div class="modal fade" id="logEditRequestModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Request Log Edit Approval</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-2"><div class="col-md-6"><label class="form-label">Project</label><select class="form-select" id="reqEditProject"></select></div><div class="col-md-6"><label class="form-label">Task Type</label><select class="form-select" id="reqEditTaskType"><option value="">Select Task Type</option><option value="page_testing">Page Testing</option><option value="page_qa">Page QA</option><option value="regression_testing">Regression Testing</option><option value="project_phase">Project Phase</option><option value="generic_task">Generic Task</option><option value="other">Other</option></select></div><div class="col-12" id="reqEditPageTestingWrap" style="display:none;"><div class="row g-2"><div class="col-md-4"><label class="form-label">Page/Screen</label><select class="form-select" id="reqEditPage"></select></div><div class="col-md-4"><label class="form-label">Environment</label><select class="form-select" id="reqEditEnvironment"></select></div><div class="col-md-4"><label class="form-label">Testing Type</label><select class="form-select" id="reqEditTestingType"><option value="at_testing">AT Testing</option><option value="ft_testing">FT Testing</option><option value="regression">Regression</option></select></div><div class="col-md-6" id="reqEditIssueWrap" style="display:none;"><label class="form-label">Issue (optional)</label><select class="form-select" id="reqEditIssue"></select></div></div></div><div class="col-12" id="reqEditPhaseWrap" style="display:none;"><div class="row g-2"><div class="col-md-6"><label class="form-label">Project Phase</label><select class="form-select" id="reqEditPhase"></select></div><div class="col-md-6"><label class="form-label">Phase Activity</label><select class="form-select" id="reqEditPhaseActivity"><option value="scoping">Scoping & Analysis</option><option value="setup">Setup & Configuration</option><option value="testing">Testing Activities</option><option value="review">Review & Documentation</option><option value="training">Training & Knowledge Transfer</option><option value="reporting">Reporting & VPAT</option></select></div></div></div><div class="col-12" id="reqEditGenericWrap" style="display:none;"><div class="row g-2"><div class="col-md-6"><label class="form-label">Task Category</label><select class="form-select" id="reqEditGenericCategory"></select></div><div class="col-md-6"><label class="form-label">Task Details</label><input type="text" class="form-control" id="reqEditGenericDetail" placeholder="Specific task details"></div></div></div><div class="col-md-4"><label class="form-label">Hours</label><input type="number" step="0.01" min="0.01" max="24" class="form-control" id="reqEditHours"></div><div class="col-md-8"><label class="form-label">Description</label><input type="text" class="form-control" id="reqEditDesc"></div><div class="col-12"><div class="small text-muted">Admin approval is required. This will send an edit request.</div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="reqEditSubmitBtn">Send Request</button></div></div></div></div>';
    document.body.appendChild(wrap.firstChild);
    return document.getElementById('logEditRequestModal');
}

function reqEditClearSelect(sel, placeholder) { if (!sel) return; sel.innerHTML = '<option value="">' + (placeholder || 'Select') + '</option>'; }

function reqEditSetTaskContainers(taskType) {
    var pageWrap = document.getElementById('reqEditPageTestingWrap');
    var phaseWrap = document.getElementById('reqEditPhaseWrap');
    var genericWrap = document.getElementById('reqEditGenericWrap');
    if (pageWrap) pageWrap.style.display = (taskType === 'page_testing' || taskType === 'page_qa' || taskType === 'regression_testing') ? 'block' : 'none';
    if (phaseWrap) phaseWrap.style.display = taskType === 'project_phase' ? 'block' : 'none';
    if (genericWrap) genericWrap.style.display = taskType === 'generic_task' ? 'block' : 'none';
}

function reqEditFillProjectOptions(selectedProjectId) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var projectSel = document.getElementById('reqEditProject');
    if (!projectSel) return;
    projectSel.innerHTML = '';
    var projects = (Array.isArray(reqEditProjects) && reqEditProjects.length > 0) ? reqEditProjects : [];
    if (projects.length > 0) {
        var ph = document.createElement('option'); ph.value = ''; ph.textContent = 'Select Project'; projectSel.appendChild(ph);
        projects.forEach(function(p) {
            if (!p || !p.id) return;
            var opt = document.createElement('option'); opt.value = String(p.id); opt.textContent = p.title || ('Project #' + p.id); projectSel.appendChild(opt);
        });
    } else {
        var srcSel = document.querySelector('#logProductionHoursForm select[name="project_id"]');
        if (srcSel) { Array.prototype.forEach.call(srcSel.options, function(opt) { var clone = document.createElement('option'); clone.value = opt.value; clone.textContent = opt.textContent; projectSel.appendChild(clone); }); }
    }
    projectSel.value = String(selectedProjectId || '');
}

function reqEditLoadPages(projectId, selectedPageId) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var pageSel = document.getElementById('reqEditPage');
    if (!pageSel || !projectId) return Promise.resolve();
    pageSel.innerHTML = '<option value="">Loading pages...</option>';
    return fetch(baseDir + '/api/tasks.php?project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(pages) {
        reqEditClearSelect(pageSel, 'Select page');
        if (Array.isArray(pages)) { pages.forEach(function(pg) { var opt = document.createElement('option'); opt.value = pg.id; opt.textContent = pg.page_name || pg.title || pg.url || ('Page ' + pg.id); pageSel.appendChild(opt); }); }
        if (selectedPageId !== null && selectedPageId !== undefined && selectedPageId !== '') pageSel.value = String(selectedPageId);
    }).catch(function(){ reqEditClearSelect(pageSel, 'No pages'); });
}

function reqEditLoadEnvironments(pageId, selectedEnvId) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var envSel = document.getElementById('reqEditEnvironment');
    if (!envSel || !pageId) { reqEditClearSelect(envSel, 'Select environment'); return Promise.resolve(); }
    envSel.innerHTML = '<option value="">Loading environments...</option>';
    return fetch(baseDir + '/api/tasks.php?page_id=' + encodeURIComponent(pageId), { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(page) {
        reqEditClearSelect(envSel, 'Select environment');
        var envs = (page && page.environments) ? page.environments : [];
        envs.forEach(function(env) { var opt = document.createElement('option'); opt.value = env.id; opt.textContent = env.name || ('Env ' + env.id); envSel.appendChild(opt); });
        if (selectedEnvId !== null && selectedEnvId !== undefined && selectedEnvId !== '') envSel.value = String(selectedEnvId);
    }).catch(function(){ reqEditClearSelect(envSel, 'No environments'); });
}

function reqEditLoadIssues(projectId, pageId, selectedIssueId) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var issueWrap = document.getElementById('reqEditIssueWrap');
    var issueSel = document.getElementById('reqEditIssue');
    var testingTypeSel = document.getElementById('reqEditTestingType');
    if (!issueWrap || !issueSel || !testingTypeSel) return Promise.resolve();
    if (testingTypeSel.value !== 'regression') { issueWrap.style.display = 'none'; reqEditClearSelect(issueSel, 'Select issue (optional)'); return Promise.resolve(); }
    issueWrap.style.display = 'block';
    issueSel.innerHTML = '<option value="">Loading issues...</option>';
    var url = baseDir + '/api/regression_actions.php?action=get_project_issues&project_id=' + encodeURIComponent(projectId || '');
    if (pageId) url += '&page_id=' + encodeURIComponent(pageId);
    return fetch(url, { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        reqEditClearSelect(issueSel, 'Select issue (optional)');
        if (data && Array.isArray(data.issues)) { data.issues.forEach(function(it) { var opt = document.createElement('option'); opt.value = it.id; opt.textContent = (it.issue_key ? (it.issue_key + ' - ') : '') + (it.title || ('Issue ' + it.id)); issueSel.appendChild(opt); }); }
        if (selectedIssueId !== null && selectedIssueId !== undefined && selectedIssueId !== '') issueSel.value = String(selectedIssueId);
    }).catch(function(){ reqEditClearSelect(issueSel, 'Select issue (optional)'); });
}

function reqEditLoadPhases(projectId, selectedPhaseId) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var phaseSel = document.getElementById('reqEditPhase');
    if (!phaseSel || !projectId) { reqEditClearSelect(phaseSel, 'Select project phase'); return Promise.resolve(); }
    phaseSel.innerHTML = '<option value="">Loading phases...</option>';
    return fetch(baseDir + '/api/projects.php?action=get_phases&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
    .then(function(r){ return r.text(); })
    .then(function(txt) {
        var phases = []; try { phases = JSON.parse(txt); } catch(e) { phases = []; }
        reqEditClearSelect(phaseSel, 'Select project phase');
        if (Array.isArray(phases)) { phases.forEach(function(phase) { var opt = document.createElement('option'); opt.value = phase.id; opt.textContent = phase.phase_name || phase.name || ('Phase ' + phase.id); phaseSel.appendChild(opt); }); }
        if (selectedPhaseId !== null && selectedPhaseId !== undefined && selectedPhaseId !== '') phaseSel.value = String(selectedPhaseId);
    }).catch(function(){ reqEditClearSelect(phaseSel, 'Select project phase'); });
}

function reqEditLoadGenericCategories(selectedCategoryId) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var catSel = document.getElementById('reqEditGenericCategory');
    if (!catSel) return Promise.resolve();
    catSel.innerHTML = '<option value="">Loading categories...</option>';
    return fetch(baseDir + '/api/generic_tasks.php?action=get_categories', { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(categories) {
        reqEditClearSelect(catSel, 'Select category');
        if (Array.isArray(categories)) { categories.forEach(function(cat) { var opt = document.createElement('option'); opt.value = cat.id; opt.textContent = cat.name + (cat.description ? (' - ' + cat.description) : ''); catSel.appendChild(opt); }); }
        if (selectedCategoryId !== null && selectedCategoryId !== undefined && selectedCategoryId !== '') catSel.value = String(selectedCategoryId);
    }).catch(function(){ reqEditClearSelect(catSel, 'Select category'); });
}

function reqEditWireEvents() {
    var modalEl = document.getElementById('logEditRequestModal');
    if (!modalEl || modalEl.dataset.eventsWired === '1') return;
    modalEl.dataset.eventsWired = '1';
    var projectSel = document.getElementById('reqEditProject');
    var taskTypeSel = document.getElementById('reqEditTaskType');
    var pageSel = document.getElementById('reqEditPage');
    var testingTypeSel = document.getElementById('reqEditTestingType');
    if (projectSel) {
        projectSel.addEventListener('change', function() {
            var projectId = this.value || '';
            var taskType = taskTypeSel ? taskTypeSel.value : '';
            if (!projectId) return;
            if (taskType === 'page_testing' || taskType === 'page_qa' || taskType === 'regression_testing') { reqEditLoadPages(projectId, null); reqEditClearSelect(document.getElementById('reqEditEnvironment'), 'Select environment'); reqEditLoadIssues(projectId, null, null); }
            else if (taskType === 'project_phase') { reqEditLoadPhases(projectId, null); }
            else if (taskType === 'generic_task') { reqEditLoadGenericCategories(null); }
        });
    }
    if (taskTypeSel) {
        taskTypeSel.addEventListener('change', function() {
            var taskType = this.value;
            var projectId = projectSel ? projectSel.value : '';
            reqEditSetTaskContainers(taskType);
            if (!projectId) return;
            if (taskType === 'page_testing' || taskType === 'page_qa' || taskType === 'regression_testing') { reqEditLoadPages(projectId, null); reqEditClearSelect(document.getElementById('reqEditEnvironment'), 'Select environment'); reqEditLoadIssues(projectId, null, null); }
            else if (taskType === 'project_phase') { reqEditLoadPhases(projectId, null); }
            else if (taskType === 'generic_task') { reqEditLoadGenericCategories(null); }
        });
    }
    if (pageSel) {
        pageSel.addEventListener('change', function() {
            var pageId = this.value || '';
            var projectId = projectSel ? projectSel.value : '';
            reqEditLoadEnvironments(pageId, null);
            reqEditLoadIssues(projectId, pageId, null);
        });
    }
    if (testingTypeSel) {
        testingTypeSel.addEventListener('change', function() {
            var projectId = projectSel ? projectSel.value : '';
            var pageId = pageSel ? pageSel.value : '';
            reqEditLoadIssues(projectId, pageId, null);
        });
    }
}

function handleEditLogRequest(logId, dateStr, logData) {
    var cfg = window._dailyStatusConfig || {};
    var baseDir = cfg.baseDir || '';
    var isAdmin = !!cfg.isAdmin;
    if (isAdmin) { if (typeof showToast === 'function') showToast('Admins can edit logs from admin tools.', 'info'); return false; }
    
    // Check if it's today for direct edit bypass
    var todayStr = cfg.today || '';
    var isToday = String(dateStr || '') === todayStr;

    var modalEl = ensureEditRequestModal();
    reqEditWireEvents();
    var data = logData || {};
    reqEditFillProjectOptions(data.project_id || '');
    var projectSel = document.getElementById('reqEditProject');
    var taskTypeSel = document.getElementById('reqEditTaskType');
    var pageSel = document.getElementById('reqEditPage');
    var envSel = document.getElementById('reqEditEnvironment');
    var issueSel = document.getElementById('reqEditIssue');
    var testingTypeSel = document.getElementById('reqEditTestingType');
    var phaseSel = document.getElementById('reqEditPhase');
    var phaseActivitySel = document.getElementById('reqEditPhaseActivity');
    var genericCategorySel = document.getElementById('reqEditGenericCategory');
    var genericDetailEl = document.getElementById('reqEditGenericDetail');
    var hoursEl = document.getElementById('reqEditHours');
    var descEl = document.getElementById('reqEditDesc');
    var submitBtn = document.getElementById('reqEditSubmitBtn');
    
    // Update Modal UI for direct edit if it's today
    var modalTitle = modalEl.querySelector('.modal-title');
    var modalHint = modalEl.querySelector('.text-muted');
    if (isToday) {
        if (modalTitle) modalTitle.textContent = 'Edit Log';
        if (modalHint) modalHint.textContent = 'No admin approval required for same-day edits.';
        if (submitBtn) submitBtn.textContent = 'Update';
    } else {
        if (modalTitle) modalTitle.textContent = 'Save Pending Log Edit';
        if (modalHint) modalHint.textContent = 'This change will be saved in pending edits. Submit all pending changes together when done.';
        if (submitBtn) submitBtn.textContent = 'Save Pending Edit';
    }

    if (!modalEl || !projectSel || !taskTypeSel || !hoursEl || !descEl || !submitBtn) return false;
    var rawTaskType = String(data.task_type || 'other');
    if (rawTaskType === 'regression') rawTaskType = 'regression_testing';
    taskTypeSel.value = rawTaskType;
    reqEditSetTaskContainers(rawTaskType);
    if (testingTypeSel) testingTypeSel.value = data.testing_type ? String(data.testing_type) : 'at_testing';
    if (phaseActivitySel) phaseActivitySel.value = 'testing';
    hoursEl.value = String(data.hours_spent || '');
    descEl.value = String(data.description || '');
    if (genericDetailEl) genericDetailEl.value = '';
    var selectedProjectId = projectSel.value;
    var selectedPageId = (data.page_id !== null && data.page_id !== undefined) ? data.page_id : null;
    var selectedEnvId = (data.environment_id !== null && data.environment_id !== undefined) ? data.environment_id : null;
    var selectedIssueId = (data.issue_id !== null && data.issue_id !== undefined) ? data.issue_id : null;
    var selectedPhaseId = (data.phase_id !== null && data.phase_id !== undefined) ? data.phase_id : null;
    var selectedCategoryId = (data.generic_category_id !== null && data.generic_category_id !== undefined) ? data.generic_category_id : null;
    if (selectedProjectId && (rawTaskType === 'page_testing' || rawTaskType === 'page_qa' || rawTaskType === 'regression_testing')) {
        reqEditLoadPages(selectedProjectId, selectedPageId).then(function(){ return reqEditLoadEnvironments(selectedPageId, selectedEnvId); }).then(function(){ return reqEditLoadIssues(selectedProjectId, selectedPageId, selectedIssueId); });
    } else if (selectedProjectId && rawTaskType === 'project_phase') {
        reqEditLoadPhases(selectedProjectId, selectedPhaseId);
    } else if (rawTaskType === 'generic_task') {
        reqEditLoadGenericCategories(selectedCategoryId);
    } else {
        reqEditClearSelect(pageSel, 'Select page'); reqEditClearSelect(envSel, 'Select environment'); reqEditClearSelect(issueSel, 'Select issue (optional)'); reqEditClearSelect(phaseSel, 'Select project phase'); reqEditClearSelect(genericCategorySel, 'Select category');
    }
    submitBtn.onclick = function() {
        var projectId = projectSel.value || '';
        var taskType = taskTypeSel.value || '';
        var pageId = pageSel ? (pageSel.value || '') : '';
        var environmentId = envSel ? (envSel.value || '') : '';
        var issueId = issueSel ? (issueSel.value || '') : '';
        var phaseId = phaseSel ? (phaseSel.value || '') : '';
        var genericCategoryId = genericCategorySel ? (genericCategorySel.value || '') : '';
        var testingType = testingTypeSel ? (testingTypeSel.value || '') : '';
        var phaseActivity = phaseActivitySel ? (phaseActivitySel.value || '') : '';
        var genericDetail = genericDetailEl ? (genericDetailEl.value || '').trim() : '';
        var h = parseFloat(hoursEl.value || '0');
        var d = (descEl.value || '').trim();
        if (!projectId || !taskType || !(h > 0) || !d) { if (typeof showToast === 'function') showToast('Project, task type, hours and description are required.', 'warning'); return; }
        
        // Same-day edits update immediately. Older dates save into pending edits.
        var actionKey = isToday ? 'edit_log' : 'edit_log_request';
        var url = '?date=' + encodeURIComponent(dateStr) + '&' + actionKey + '=' + encodeURIComponent(logId) + '&new_project_id=' + encodeURIComponent(projectId) + '&new_task_type=' + encodeURIComponent(taskType) + '&new_page_id=' + encodeURIComponent(pageId) + '&new_environment_id=' + encodeURIComponent(environmentId) + '&new_issue_id=' + encodeURIComponent(issueId) + '&new_phase_id=' + encodeURIComponent(phaseId) + '&new_generic_category_id=' + encodeURIComponent(genericCategoryId) + '&new_testing_type=' + encodeURIComponent(testingType) + '&new_phase_activity=' + encodeURIComponent(phaseActivity) + '&new_generic_task_detail=' + encodeURIComponent(genericDetail) + '&new_hours=' + encodeURIComponent(h) + '&new_description=' + encodeURIComponent(d);
        
        submitBtn.disabled = true; submitBtn.textContent = 'Processing...';
        try { bootstrap.Modal.getOrCreateInstance(modalEl).hide(); } catch(e) {}
        setTimeout(function(){ window.location.href = url; }, 60);
    };
    try { bootstrap.Modal.getOrCreateInstance(modalEl).show(); } catch(e) {}
    return false;
}
