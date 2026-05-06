/**
 * regression-panel.js
 * Client-side logic for the Regression Testing Panel partial.
 * Loaded on issues_pages, issues_page_detail, issues_common, issues_all.
 */
(function () {
    'use strict';

    var cfg = window.ProjectConfig || {};
    var projectId = cfg.projectId || 0;
    var baseDir = cfg.baseDir || '';
    var userRole = cfg.userRole || 'client';
    var regressionApi = baseDir + '/api/regression_actions.php';

    var canManageRounds = (userRole === 'admin' || userRole === 'project_lead' || userRole === 'qa');
    var canRestoreOriginalVersion = canManageRounds;
    var csrfToken = resolveCsrfToken();
    var newRoundDefaultHtml = '<i class="fas fa-plus me-1"></i>New Round';
    var pendingConfirmAction = null;

    // -------------------------------------------------------
    // Stats
    // -------------------------------------------------------
    function loadRegressionStats() {
        var container = document.getElementById('regressionStatsContainer');
        if (!container || !projectId) return;

        fetch(regressionApi + '?action=get_stats&project_id=' + encodeURIComponent(projectId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var badgeEl = document.getElementById('regressionActiveRoundBadge');
                if (!data || !data.success) {
                    container.innerHTML = '<span class="text-muted small">Could not load regression stats.</span>';
                    if (badgeEl) badgeEl.innerHTML = '';
                    return;
                }
                setNewRoundButtonState(data.active_round || null);
                if (badgeEl) {
                    var activeRound = data.active_round || null;
                    var activeNumber = activeRound ? parseInt(activeRound.round_number || 0, 10) : 0;
                    badgeEl.innerHTML = activeNumber > 0
                        ? '<span class="badge bg-warning text-dark"><i class="fas fa-circle-notch fa-spin me-1"></i>Round ' + escHtml(String(activeNumber)) + ' in progress</span>'
                        : '<span class="badge bg-secondary"><i class="fas fa-check me-1"></i>No active round</span>';
                }
                var total = parseInt(data.issues_total || 0, 10);
                var attempted = parseInt(data.regression_issues_total || data.attempted_issues_total || 0, 10);
                var newInRound = parseInt(data.new_issues_in_round_total || 0, 10);
                var pct = total > 0 ? Math.round((attempted / total) * 100) : 0;
                var statusCounts = data.attempted_status_counts || {};

                var statusKeys = Object.keys(statusCounts);
                var statusHtml = '';
                if (statusKeys.length) {
                    statusHtml = statusKeys.map(function (s) {
                        return '<span class="badge bg-secondary me-1 mb-1">' +
                            escHtml(s) + ': ' + parseInt(statusCounts[s] || 0, 10) +
                            '</span>';
                    }).join('');
                } else {
                    statusHtml = '<span class="text-muted small">No regression activity yet.</span>';
                }

                var progressBarColor = pct >= 100 ? 'bg-success' :
                                       pct >= 60  ? 'bg-info'    :
                                       pct >= 30  ? 'bg-warning'  : 'bg-danger';

                container.innerHTML =
                    '<div class="row g-3 align-items-center">' +
                    '<div class="col-6 col-md-2">' +
                    '<div class="text-muted small">Total Issues</div>' +
                    '<div class="fw-semibold fs-5">' + total + '</div>' +
                    '</div>' +
                    '<div class="col-6 col-md-2">' +
                    '<div class="text-muted small">Regression Issues</div>' +
                    '<div class="fw-semibold fs-5 text-success">' + attempted + '</div>' +
                    (newInRound > 0 ? '<div class="small text-muted">New in round: ' + newInRound + '</div>' : '') +
                    '</div>' +
                    '<div class="col-6 col-md-3">' +
                    '<div class="text-muted small mb-1">Coverage</div>' +
                    '<div class="d-flex align-items-center gap-2">' +
                    '<span class="fw-semibold">' + pct + '%</span>' +
                    '<div class="progress flex-grow-1" style="height:8px;" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100">' +
                    '<div class="progress-bar ' + progressBarColor + '" style="width:' + pct + '%"></div>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '<div class="col-12 col-md-5">' +
                    '<div class="text-muted small mb-1">Status Breakdown</div>' +
                    '<div>' + statusHtml + '</div>' +
                    '</div>' +
                    '</div>';
            })
            .catch(function () {
                var c = document.getElementById('regressionStatsContainer');
                if (c) c.innerHTML = '<span class="text-muted small">Could not load regression stats.</span>';
            });
    }

    // -------------------------------------------------------
    // Rounds
    // -------------------------------------------------------
    function loadRegressionRounds() {
        var container = document.getElementById('regressionRoundsList');
        if (!container || !projectId) return;

        container.innerHTML = '<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading…</div>';

        fetch(regressionApi + '?action=list_rounds&project_id=' + encodeURIComponent(projectId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var badgeEl = document.getElementById('regressionActiveRoundBadge');

                if (!data || !data.success || !data.rounds || !data.rounds.length) {
                    container.innerHTML = '<div class="text-muted small">No regression rounds created yet.</div>';
                    if (badgeEl) badgeEl.innerHTML = '';
                    return;
                }

                // Active round badge
                var activeRound = data.rounds.find(function (r) {
                    return r.status === 'in_progress' && r.is_active == 1;
                });
                setNewRoundButtonState(activeRound || null);
                if (badgeEl) {
                    badgeEl.innerHTML = activeRound
                        ? '<span class="badge bg-warning text-dark"><i class="fas fa-circle-notch fa-spin me-1"></i>Round ' + escHtml(String(activeRound.round_number)) + ' in progress</span>'
                        : '<span class="badge bg-secondary"><i class="fas fa-check me-1"></i>No active round</span>';
                }

                var html = '<div class="table-responsive">' +
                    '<table class="table table-sm table-hover mb-0 align-middle">' +
                    '<thead class="table-light">' +
                    '<tr>' +
                    '<th>Round</th>' +
                    '<th>Started By</th>' +
                    '<th>Start Date</th>' +
                    '<th>End Date</th>' +
                    '<th>Status</th>' +
                    '<th></th>' +
                    '</tr>' +
                    '</thead>' +
                    '<tbody>';

                data.rounds.forEach(function (r) {
                    var statusBadge = r.status === 'completed'
                        ? '<span class="badge bg-success">Completed</span>'
                        : '<span class="badge bg-warning text-dark"><i class="fas fa-circle-notch fa-spin me-1"></i>In Progress</span>';

                    var actionCell = '<button type="button" class="btn btn-xs btn-outline-primary py-0 me-1 regression-view-round" data-round-id="' +
                        escAttr(String(r.id)) + '" data-round-number="' + escAttr(String(r.round_number)) + '">View</button>';
                    if (canManageRounds && r.status === 'in_progress') {
                        actionCell += '<button type="button" class="btn btn-xs btn-outline-danger py-0 regression-complete-round" data-round-id="' +
                            escAttr(String(r.id)) + '" data-round-number="' + escAttr(String(r.round_number)) + '">Complete</button>';
                    }

                    html += '<tr>' +
                        '<td><span class="badge bg-primary">Round ' + escHtml(String(r.round_number)) + '</span></td>' +
                        '<td class="small">' + escHtml(r.started_by_name || '—') + '</td>' +
                        '<td class="small">' + escHtml(r.start_date || '—') + '</td>' +
                        '<td class="small">' + escHtml(r.end_date || '—') + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        (canManageRounds ? '<td>' + actionCell + '</td>' : '') +
                        '</tr>';
                });

                html += '</tbody></table></div>';
                container.innerHTML = html;

                container.querySelectorAll('.regression-view-round').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var roundId = this.getAttribute('data-round-id');
                        var roundNumber = this.getAttribute('data-round-number');
                        openRoundDetails(roundId, roundNumber);
                    });
                });

                container.querySelectorAll('.regression-complete-round').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var roundId     = this.getAttribute('data-round-id');
                        var roundNumber = this.getAttribute('data-round-number');
                        openConfirmModal({
                            title: 'Complete Regression Round',
                            body: 'Mark RR ' + roundNumber + ' as completed? This cannot be undone.',
                            confirmLabel: 'Complete',
                            confirmClass: 'btn-danger',
                            onConfirm: function () {
                                completeRound(roundId);
                            }
                        });
                    });
                });
            })
            .catch(function () {
                var c = document.getElementById('regressionRoundsList');
                if (c) c.innerHTML = '<div class="text-muted small">Could not load rounds.</div>';
            });
    }

    function createNewRound() {
        if (!projectId) return;
        var btn = document.getElementById('btnNewRegressionRound');
        if (btn) { btn.disabled = true; }
        var createdSuccessfully = false;

        var fd = new FormData();
        fd.append('action', 'create_round');
        fd.append('project_id', String(projectId));
        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        fetch(regressionApi, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (data && data.success) {
                    createdSuccessfully = true;
                    loadRegressionStats();
                    loadRegressionRounds();
                    if (typeof window.showToast === 'function') {
                        showToast('Regression Round ' + data.round_number + ' created', 'success');
                    }
                    // Auto-open rounds collapse
                    var collapseEl = document.getElementById('regressionRoundsCollapse');
                    if (collapseEl && window.bootstrap) {
                        var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl);
                        bsCollapse.show();
                    }
                } else {
                    var msg = (data && data.error) ? data.error : 'Failed to create round';
                    if (typeof window.showToast === 'function') showToast(msg, 'danger');
                    else alert(msg);
                }
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : 'Request failed';
                if (typeof window.showToast === 'function') showToast(message, 'danger');
                else alert(message);
            })
            .finally(function () {
                if (btn && !createdSuccessfully) {
                    btn.disabled = false;
                }
                loadRegressionStats();
            });
    }

    function completeRound(roundId) {
        var fd = new FormData();
        fd.append('action', 'complete_round');
        fd.append('project_id', String(projectId));
        fd.append('round_id', String(roundId));
        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        fetch(regressionApi, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (data && data.success) {
                    loadRegressionStats();
                    loadRegressionRounds();
                    if (typeof window.showToast === 'function') showToast('Round completed', 'success');
                } else {
                    var msg = (data && data.error) ? data.error : 'Failed to complete round';
                    if (typeof window.showToast === 'function') showToast(msg, 'danger');
                    else alert(msg);
                }
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : 'Request failed';
                if (typeof window.showToast === 'function') showToast(message, 'danger');
                else alert(message);
            });
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------
    function escHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function escAttr(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function setNewRoundButtonState(activeRound) {
        var btn = document.getElementById('btnNewRegressionRound');
        if (!btn) return;

        var roundNumber = activeRound ? parseInt(activeRound.round_number || 0, 10) : 0;
        if (roundNumber > 0) {
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-warning');
            btn.innerHTML = '<i class="fas fa-circle-notch me-1"></i>RR ' + roundNumber + ' in progress';
            btn.title = 'Complete active round to create a new round';
            return;
        }

        btn.disabled = false;
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-primary');
        btn.innerHTML = newRoundDefaultHtml;
        btn.removeAttribute('title');
    }

    function resolveCsrfToken() {
        if (window._csrfToken) {
            return String(window._csrfToken);
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return String(meta.getAttribute('content'));
        }
        return '';
    }

    function readJsonResponse(response) {
        return response.text().then(function (text) {
            var data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                throw new Error('Invalid JSON response');
            }

            if (!response.ok && data && data.error) {
                throw new Error(String(data.error));
            }
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return data;
        });
    }

    // -------------------------------------------------------
    // Init
    // -------------------------------------------------------
    function init() {
        loadRegressionStats();

        // Expose globally for issues module refresh
        window.loadRegressionStats = loadRegressionStats;
        window.loadRegressionRounds = loadRegressionRounds;

        wireConfirmModal();

        var newRoundBtn = document.getElementById('btnNewRegressionRound');
        if (newRoundBtn) {
            newRoundBtn.addEventListener('click', function () {
                openConfirmModal({
                    title: 'Start New Regression Round',
                    body: 'Start a new regression round for this project?',
                    confirmLabel: 'Start Round',
                    confirmClass: 'btn-primary',
                    onConfirm: function () {
                        createNewRound();
                    }
                });
            });
        }

        // Load rounds when the collapse panel is first opened
        var roundsCollapse = document.getElementById('regressionRoundsCollapse');
        if (roundsCollapse) {
            roundsCollapse.addEventListener('show.bs.collapse', function onFirstShow() {
                roundsCollapse.removeEventListener('show.bs.collapse', onFirstShow);
                loadRegressionRounds();
                // Re-attach for subsequent opens so it refreshes
                roundsCollapse.addEventListener('show.bs.collapse', loadRegressionRounds);
            });
        }
    }

    function wireConfirmModal() {
        var confirmBtn = document.getElementById('regressionConfirmModalYes');
        if (!confirmBtn) return;

        confirmBtn.addEventListener('click', function () {
            if (typeof pendingConfirmAction === 'function') {
                var action = pendingConfirmAction;
                pendingConfirmAction = null;
                action();
            }

            var modalEl = document.getElementById('regressionConfirmModal');
            if (modalEl && window.bootstrap) {
                var modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();
            }
        });
    }

    function openConfirmModal(options) {
        if (!options || typeof options.onConfirm !== 'function') {
            return;
        }

        if (!window.bootstrap) {
            if (confirm(options.body || 'Are you sure?')) {
                options.onConfirm();
            }
            return;
        }

        var modalEl = document.getElementById('regressionConfirmModal');
        var titleEl = document.getElementById('regressionConfirmModalLabel');
        var bodyEl = document.getElementById('regressionConfirmModalBody');
        var confirmBtn = document.getElementById('regressionConfirmModalYes');

        if (!modalEl || !titleEl || !bodyEl || !confirmBtn) {
            if (confirm(options.body || 'Are you sure?')) {
                options.onConfirm();
            }
            return;
        }

        titleEl.textContent = options.title || 'Confirm Action';
        bodyEl.textContent = options.body || 'Are you sure?';
        confirmBtn.textContent = options.confirmLabel || 'Confirm';
        confirmBtn.className = 'btn ' + (options.confirmClass || 'btn-primary');

        pendingConfirmAction = options.onConfirm;

        var modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInstance.show();
    }

    function openRoundDetails(roundId, roundNumber) {
        var modalEl = document.getElementById('regressionRoundDetailModal');
        var titleEl = document.getElementById('regressionRoundDetailModalLabel');
        var bodyEl = document.getElementById('regressionRoundDetailModalBody');
        if (!modalEl || !titleEl || !bodyEl) return;

        titleEl.textContent = 'Regression Round ' + String(roundNumber || 'Details');
        modalEl.setAttribute('data-round-id', String(roundId || ''));
        modalEl.setAttribute('data-round-number', String(roundNumber || ''));
        bodyEl.innerHTML = '<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading round details…</div>';

        if (window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        fetch(regressionApi + '?action=get_round_details&project_id=' + encodeURIComponent(projectId) + '&round_id=' + encodeURIComponent(roundId), {
            credentials: 'same-origin'
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (!data || !data.success) {
                    bodyEl.innerHTML = '<div class="alert alert-warning mb-0">Could not load round details.</div>';
                    return;
                }
                renderRoundDetails(bodyEl, data);
            })
            .catch(function (err) {
                bodyEl.innerHTML = '<div class="alert alert-danger mb-0">' + escHtml((err && err.message) ? err.message : 'Failed to load round details') + '</div>';
            });
    }

    function renderRoundDetails(container, data) {
        var round = data.round || {};
        var issues = Array.isArray(data.issues) ? data.issues : [];

        var summaryHtml =
            '<div class="mb-3 p-3 border rounded bg-light">' +
            '<div class="d-flex flex-wrap gap-3 small">' +
            '<div><strong>Round:</strong> RR ' + escHtml(String(round.round_number || '')) + '</div>' +
            '<div><strong>Status:</strong> ' + escHtml(String(round.status || '')) + '</div>' +
            '<div><strong>Started:</strong> ' + escHtml(String(round.started_at || round.start_date || '—')) + '</div>' +
            '<div><strong>Ended:</strong> ' + escHtml(String(round.ended_at || round.end_date || '—')) + '</div>' +
            '<div><strong>Started by:</strong> ' + escHtml(String(round.started_by_name || '—')) + '</div>' +
            '</div>' +
            '</div>';

        if (!issues.length) {
            container.innerHTML = summaryHtml + '<div class="text-muted small">No tracked issue changes in this round yet.</div>';
            return;
        }

        var cards = issues.map(function (item, idx) {
            return buildRoundIssueCard(item, idx + 1, round.id || 0);
        }).join('');

        container.innerHTML = summaryHtml + cards;

        container.querySelectorAll('.regression-version-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cardId = this.getAttribute('data-card-id');
                var version = this.getAttribute('data-version');
                var originalEl = document.getElementById('regressionVersionOriginal_' + cardId);
                var currentEl = document.getElementById('regressionVersionCurrent_' + cardId);
                var controls = container.querySelectorAll('.regression-version-toggle[data-card-id="' + cardId + '"]');
                controls.forEach(function (c) {
                    c.classList.remove('btn-primary');
                    c.classList.add('btn-outline-primary');
                });
                this.classList.remove('btn-outline-primary');
                this.classList.add('btn-primary');

                if (!originalEl || !currentEl) return;
                if (version === 'original') {
                    originalEl.classList.remove('d-none');
                    currentEl.classList.add('d-none');
                } else {
                    originalEl.classList.add('d-none');
                    currentEl.classList.remove('d-none');
                }
            });
        });

        container.querySelectorAll('.regression-restore-original').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var restoreRoundId = this.getAttribute('data-round-id');
                var restoreIssueId = this.getAttribute('data-issue-id');
                openConfirmModal({
                    title: 'Restore Original Version',
                    body: 'Restore this issue to its original snapshot for this round?',
                    confirmLabel: 'Restore',
                    confirmClass: 'btn-warning',
                    onConfirm: function () {
                        restoreRoundIssueOriginal(restoreRoundId, restoreIssueId);
                    }
                });
            });
        });
    }

    function buildRoundIssueCard(item, cardId, roundId) {
        var original = item && item.original ? item.original : null;
        var current = item && item.current ? item.current : null;
        var issueInfo = (current && current.issue) ? current.issue : ((original && original.issue) ? original.issue : {});
        var title = issueInfo && issueInfo.title ? String(issueInfo.title) : 'Issue #' + String(item.issue_id || '');
        var issueKey = issueInfo && issueInfo.issue_key ? String(issueInfo.issue_key) : ('#' + String(item.issue_id || ''));
        var comments = Array.isArray(item.comments) ? item.comments : [];

        var commentsHtml = comments.length
            ? comments.map(function (c) {
                return '<div class="border rounded p-2 mb-2 bg-white">' +
                    '<div class="small text-muted mb-1">' +
                    escHtml(String(c.user_name || 'User')) + ' • ' +
                    escHtml(String(c.created_at || '')) + ' • ' +
                    escHtml(String(c.comment_type || 'normal')) +
                    '</div>' +
                    '<div class="small">' + escHtml(stripTags(String(c.comment_html || ''))) + '</div>' +
                    '</div>';
            }).join('')
            : '<div class="text-muted small">No comments during this round window.</div>';

        var restoreControlHtml = '';
        if (canRestoreOriginalVersion && original) {
            restoreControlHtml = '<button type="button" class="btn btn-sm btn-outline-warning regression-restore-original" data-round-id="' + escAttr(String(roundId || 0)) + '" data-issue-id="' + escAttr(String(item.issue_id || 0)) + '"><i class="fas fa-undo me-1"></i>Restore Original</button>';
        }

        return '<div class="card mb-3">' +
            '<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">' +
            '<div><strong>' + escHtml(issueKey) + '</strong> <span class="text-muted">' + escHtml(title) + '</span></div>' +
            '<div class="small text-muted">Last modified: ' + escHtml(String(item.last_modified_at || '—')) + '</div>' +
            '</div>' +
            '<div class="card-body">' +
            '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">' +
            '<div class="btn-group btn-group-sm" role="group" aria-label="Version switch">' +
            '<button type="button" class="btn btn-outline-primary regression-version-toggle" data-card-id="' + escAttr(String(cardId)) + '" data-version="original">Original</button>' +
            '<button type="button" class="btn btn-primary regression-version-toggle" data-card-id="' + escAttr(String(cardId)) + '" data-version="current">Round Version</button>' +
            '</div>' +
            '<div class="d-flex align-items-center gap-2">' + restoreControlHtml + '<div class="small text-muted">Control: switch between original and round version</div></div>' +
            '</div>' +
            '<div id="regressionVersionOriginal_' + escAttr(String(cardId)) + '" class="d-none">' + renderVersionPayload(original) + '</div>' +
            '<div id="regressionVersionCurrent_' + escAttr(String(cardId)) + '">' + renderVersionPayload(current) + '</div>' +
            '<hr>' +
            '<div><h6 class="mb-2">Round Comments</h6>' + commentsHtml + '</div>' +
            '</div>' +
            '</div>';
    }

    function getStatusName(id) {
        var statuses = window.ProjectConfig && window.ProjectConfig.issueStatuses ? window.ProjectConfig.issueStatuses : [];
        var status = statuses.find(function(s) { return String(s.id) === String(id); });
        return status ? (status.name || status.status_label || id) : id;
    }

    function getPriorityName(id) {
        var map = {'1': 'High', '2': 'Medium', '3': 'Low'};
        return map[String(id)] || id;
    }

    function getPageNames(ids) {
        var pages = window.ProjectConfig && window.ProjectConfig.projectPages ? window.ProjectConfig.projectPages : [];
        return (ids || []).map(function(id) {
            var page = pages.find(function(p) { return String(p.id) === String(id); });
            return page ? (page.title || page.url || page.page_name || id) : id;
        });
    }

    function getUserNames(ids) {
        var users = window.ProjectConfig && window.ProjectConfig.projectUsers ? window.ProjectConfig.projectUsers : [];
        return (ids || []).map(function(id) {
            var user = users.find(function(u) { return String(u.id) === String(id); });
            return user ? (user.full_name || user.name || user.username || id) : id;
        });
    }

    function renderVersionPayload(payload) {
        if (!payload || !payload.issue) {
            return '<div class="text-muted small">Version snapshot unavailable.</div>';
        }
        var issue = payload.issue || {};
        var metadata = payload.metadata || {};
        var pageIds = Array.isArray(payload.page_ids) ? payload.page_ids : [];
        var groupedUrls = metadata.grouped_urls || [];
        var environments = metadata.environments || [];

        var statusName = getStatusName(issue.status_id);
        var priorityName = getPriorityName(issue.priority_id);
        var isClientReady = String(issue.client_ready) === '1' ? 'Yes' : 'No';
        var pageNames = getPageNames(pageIds);
        var reporterNames = getUserNames(metadata.reporter_ids || []);
        var assigneeNames = getUserNames(metadata.assignee_ids || []);

        var descriptionHtml = issue.description || '';
        if (window.DOMPurify) {
            descriptionHtml = window.DOMPurify.sanitize(descriptionHtml);
        }

        return '<div class="row g-3">' +
            '<div class="col-md-6">' +
            '<div class="small"><strong>Title:</strong> ' + escHtml(String(issue.title || '')) + '</div>' +
            '<div class="small"><strong>Status:</strong> ' + escHtml(String(statusName)) + '</div>' +
            '<div class="small"><strong>Priority:</strong> ' + escHtml(String(priorityName)) + '</div>' +
            '<div class="small"><strong>Severity:</strong> ' + escHtml(String(issue.severity || '')) + '</div>' +
            '<div class="small"><strong>Client Ready:</strong> ' + escHtml(isClientReady) + '</div>' +
            '</div>' +
            '<div class="col-md-6">' +
            '<div class="small"><strong>Pages:</strong> ' + formatArrayValue(pageNames) + '</div>' +
            '<div class="small"><strong>Grouped URLs:</strong> ' + formatArrayValue(groupedUrls) + '</div>' +
            '<div class="small"><strong>Environments:</strong> ' + formatArrayValue(environments) + '</div>' +
            '<div class="small"><strong>Reporters:</strong> ' + formatArrayValue(reporterNames) + '</div>' +
            '<div class="small"><strong>Assignees:</strong> ' + formatArrayValue(assigneeNames) + '</div>' +
            '</div>' +
            '<div class="col-12">' +
            '<div class="small"><strong>Description:</strong></div>' +
            '<div class="small text-muted border rounded p-2 bg-light summernote-content" style="max-height:400px; overflow-y:auto;">' + descriptionHtml + '</div>' +
            '</div>' +
            '</div>';
    }

    function stripTags(html) {
        return String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function formatArrayValue(value) {
        if (value == null) return '—';
        var list = Array.isArray(value) ? value : [value];
        list = list.map(function (v) {
            if (Array.isArray(v)) return v.join(', ');
            if (typeof v === 'object' && v !== null) return JSON.stringify(v);
            return String(v || '');
        }).filter(function (v) { return String(v).trim() !== ''; });
        return list.length ? escHtml(list.join(', ')) : '—';
    }

    function restoreRoundIssueOriginal(roundId, issueId) {
        if (!projectId || !roundId || !issueId) return;

        var fd = new FormData();
        fd.append('action', 'restore_round_issue_original');
        fd.append('project_id', String(projectId));
        fd.append('round_id', String(roundId));
        fd.append('issue_id', String(issueId));
        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        fetch(regressionApi, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
        })
            .then(readJsonResponse)
            .then(function (data) {
                if (!data || !data.success) {
                    var msg = (data && data.error) ? data.error : 'Failed to restore original version';
                    if (typeof window.showToast === 'function') showToast(msg, 'danger');
                    else alert(msg);
                    return;
                }

                if (typeof window.showToast === 'function') {
                    showToast(data.message || 'Original version restored', 'success');
                }

                var modalEl = document.getElementById('regressionRoundDetailModal');
                var roundNumber = modalEl ? (modalEl.getAttribute('data-round-number') || '') : '';
                openRoundDetails(roundId, roundNumber);
                loadRegressionStats();
                loadRegressionRounds();
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : 'Failed to restore original version';
                if (typeof window.showToast === 'function') showToast(message, 'danger');
                else alert(message);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
