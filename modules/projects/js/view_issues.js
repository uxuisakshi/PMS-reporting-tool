/**
 * view_issues.js
 * Logic for the Issues tab: issue management, drafting, rendering, and interaction.
 */

(function () {
    try {
        var list = document.getElementById('issuesPageList');
        if (!list) {
            // Element not found - likely on detail page
        } else {
            var rows = list.querySelectorAll('.issues-page-row');
        }
    } catch (e) { if (typeof window.showToast === 'function') { showToast('Issue script error: ' + e, 'danger'); } }

    // Check if we're on a page that needs issues functionality
    // Allow execution on detail pages even without #issues or #issuesSubTabs
    var hasIssuesTab = document.getElementById('issues') || document.getElementById('issuesSubTabs');
    var hasIssueModal = document.getElementById('finalIssueModal');
    var hasAddIssueBtn = document.getElementById('issueAddFinalBtn');
    var hasCommonIssues = document.getElementById('commonIssuesBody') || document.getElementById('commonAddBtn');

    if (!hasIssuesTab && !hasIssueModal && !hasAddIssueBtn && !hasCommonIssues) {
        return; // Exit early if no issues-related elements found
    }

    // Config from global object
    var pages = ProjectConfig.projectPages || [];
    var groupedUrls = ProjectConfig.groupedUrls || [];
    var projectId = ProjectConfig.projectId;
    var projectType = ProjectConfig.projectType || 'web';
    var issuesApiBase = ProjectConfig.baseDir + '/api/issues.php';
    var regressionApiBase = ProjectConfig.baseDir + '/api/regression_actions.php';
    var issueImageUploadUrl = ProjectConfig.baseDir + '/api/issue_upload_image.php';
    var issueTemplatesApi = ProjectConfig.baseDir + '/api/issue_templates.php';

    // Load full grouped URLs from API (inline ProjectConfig may be truncated for large datasets)
    (function loadGroupedUrlsFromApi() {
        fetch(ProjectConfig.baseDir + '/api/project_pages.php?action=list_grouped&project_id=' + projectId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && Array.isArray(data.grouped_urls) && data.grouped_urls.length > 0) {
                    // Normalize to match expected format
                    groupedUrls = data.grouped_urls.map(function(row) {
                        return {
                            id: row.id,
                            url: row.url,
                            normalized_url: row.normalized_url,
                            unique_page_id: row.unique_page_id,
                            mapped_page_id: row.unique_page_id  // same field
                        };
                    });
                }
            })
            .catch(function() {}); // silent fail, fallback to ProjectConfig data
    })();
    var issueCommentsApi = ProjectConfig.baseDir + '/api/issue_comments.php';
    var issueDraftsApi = ProjectConfig.baseDir + '/api/issue_drafts.php';
    var uniqueIssuePages = ProjectConfig.uniqueIssuePages || [];
    var userRole = ProjectConfig.userRole || '';
    var isAdminUser = userRole === 'admin' || userRole === 'superadmin';
    var isTesterRole = userRole === 'at_tester' || userRole === 'ft_tester';
    

    var canUpdateIssueQaStatus = !!ProjectConfig.canUpdateIssueQaStatus;
    var clientEditableIssueStatuses = (ProjectConfig.issueStatuses || []).map(function (status) {
        return normalizeIssueStatusSlug(status.status_label || status.name || status.label || '');
    }).filter(function (status, index, list) {
        return !!status && list.indexOf(status) === index;
    });
    var resolutionAuthorityRoles = ['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester'];
    // Note: canUpdateIssueQaStatus is already calculated server-side based on permissions
    // Don't override it here for tester roles as they may have explicit QA permissions

    var issueData = {
        selectedPageId: null,
        pages: {},
        common: [],
        comments: {},
        counters: { final: 1, review: 1, common: 1 },
        draftTimer: null,
        initialFormState: null,
        isDraftRestored: false,
        imageUpload: {
            pendingFile: null,
            pendingEditor: null,
            lastPasteTime: 0,
            suppressUntil: 0,
            isEditing: false,
            editingImg: null,
            savedRange: null
        }
    };

    // Detail page fallback: pick page_id from URL if pre-selection bootstrap misses.
    try {
        var qp = new URLSearchParams(window.location.search || '');
        var pageIdFromQuery = qp.get('page_id');
        if (pageIdFromQuery && !issueData.selectedPageId) {
            issueData.selectedPageId = String(pageIdFromQuery);
        }
        
        // Handle expand parameter to auto-expand specific issue
        var expandIssueId = qp.get('expand');
        if (expandIssueId) {
            window.expandIssueId = expandIssueId;
        }
    } catch (e) { }

    // Expose issueData globally for external access
    window.issueData = issueData;
    var issueTemplates = [];
    var defaultSections = [];
    var issuePresets = [];
    var issueMetadataFields = [];
    var isSyncingUrlModal = false;
    var issuePresenceTimer = null;
    var issuePresenceIssueId = null;
    var issuePresenceSessionToken = null;
    var issuePresenceRenderSignature = '';
    var ISSUE_PRESENCE_PING_MS = 2000;
    var reviewPageSize = 25;
    var reviewCurrentPage = 1;
    var reviewFeaturesEnabled = false;
    var reviewStorageKey = 'pms_review_findings_v1_' + String(projectId || '0');
    var reviewIssueInitialFormState = null;
    var reviewIssueBypassCloseConfirm = false;
    var finalIssueBypassCloseConfirm = false;
    var testerRegressionLock = {
        active: false,
        roundNumber: 0,
        loaded: false
    };

    // Utility functions hoisted to top so they're available everywhere in this scope
    function escapeHtml(str) { if (str === null || str === undefined) return ''; return String(str).replace(/[&<>"']/g, function (m) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[m]); }); }

    function normalizeIssueStatusSlug(value) {
        var raw = String(value || '').trim().toLowerCase();
        if (!raw) return '';
        return raw.replace(/-/g, '_').replace(/\s+/g, '_');
    }

    function isClientIssueStatusAllowed(value) {
        var normalizedValue = normalizeIssueStatusSlug(value);
        if (!normalizedValue) return false;
        if (clientEditableIssueStatuses.length) {
            return clientEditableIssueStatuses.indexOf(normalizedValue) !== -1;
        }
        return ['open', 'in_progress', 'reopened'].indexOf(normalizedValue) !== -1;
    }

    function isResolutionStatus(value) {
        return ['resolved', 'fixed', 'closed'].indexOf(normalizeIssueStatusSlug(value)) !== -1;
    }

    function canCurrentUserMarkResolved() {
        return resolutionAuthorityRoles.indexOf(userRole) !== -1;
    }

    function getValidProjectPageIds() {
        return pages.map(function (page) {
            return String(page.id);
        });
    }

    function isValidProjectPageId(pageId) {
        var candidate = String(pageId || '').trim();
        if (!candidate) return false;
        return getValidProjectPageIds().indexOf(candidate) !== -1;
    }

    function normalizeProjectPageIds(pageIds) {
        return (Array.isArray(pageIds) ? pageIds : []).map(function (pageId) {
            return String(pageId || '').trim();
        }).filter(function (pageId, index, list) {
            return pageId && isValidProjectPageId(pageId) && list.indexOf(pageId) === index;
        });
    }

    function resolveValidSelectedPageId(preferredPageId, fallbackPageIds) {
        var preferred = String(preferredPageId || '').trim();
        if (preferred && isValidProjectPageId(preferred)) {
            return preferred;
        }
        var normalizedFallbacks = normalizeProjectPageIds(fallbackPageIds || []);
        if (normalizedFallbacks.length) {
            return normalizedFallbacks[0];
        }
        var allProjectPageIds = getValidProjectPageIds();
        return allProjectPageIds.length ? allProjectPageIds[0] : '';
    }

    function getClientQuickStatusOptions() {
        return (ProjectConfig.issueStatuses || []).filter(function (status) {
            var label = status.status_label || status.name || status.label || '';
            return isClientIssueStatusAllowed(label);
        });
    }

    function buildClientQuickStatusActions(issue) {
        if (userRole !== 'client') return '';

        var options = getClientQuickStatusOptions();
        if (!options.length) return '';

        var currentStatusId = String(issue.status_id || '');
        return '<div class="mt-3 pt-3 border-top">' +
            '<div class="small text-muted fw-semibold mb-2">Quick Status</div>' +
            '<div class="d-flex flex-wrap gap-2">' +
            options.map(function (status) {
                var statusId = String(status.id || '');
                var label = status.status_label || status.name || 'Status';
                var isActive = statusId === currentStatusId;
                return '<button type="button" class="btn btn-sm ' + (isActive ? 'btn-primary' : 'btn-outline-primary') + ' client-quick-status" data-issue-id="' + issue.id + '" data-status-id="' + escapeAttr(statusId) + '"' + (isActive ? ' disabled' : '') + '>' +
                    escapeHtml(label) +
                    '</button>';
            }).join('') +
            '</div>' +
            '</div>';
    }

    function applyClientFinalIssueFilters(issues) {
        if (userRole !== 'client') return issues;

        var searchEl = document.getElementById('clientIssueSearch');
        var statusEl = document.getElementById('clientIssueStatusFilter');
        var searchTerm = String(searchEl ? searchEl.value : '').trim().toLowerCase();
        var statusValue = normalizeIssueStatusSlug(statusEl ? statusEl.value : '');

        return issues.filter(function (issue) {
            var searchable = [
                issue.issue_key || '',
                issue.title || '',
                stripHtml(issue.details || ''),
                issue.common_title || ''
            ].join(' ').toLowerCase();
            var issueStatus = normalizeIssueStatusSlug(issue.status || issue.status_name || '');

            if (searchTerm && searchable.indexOf(searchTerm) === -1) {
                return false;
            }
            if (statusValue && issueStatus !== statusValue) {
                return false;
            }
            return true;
        });
    }

    function getSelectedPageFinalIssues() {
        if (!issueData.selectedPageId || !issueData.pages[issueData.selectedPageId]) return [];
        return issueData.pages[issueData.selectedPageId].final || [];
    }

    async function quickUpdateClientIssueStatus(issueId, statusId, triggerBtn) {
        if (userRole !== 'client') return;

        var issues = getSelectedPageFinalIssues();
        var issue = issues.find(function (item) { return String(item.id) === String(issueId); });
        if (!issue) return;

        var fd = new FormData();
        fd.append('action', 'update');
        fd.append('project_id', projectId);
        fd.append('id', String(issue.id));
        fd.append('title', String(issue.title || 'Issue'));
        fd.append('issue_status', String(statusId));
        if (issue.updated_at) fd.append('expected_updated_at', String(issue.updated_at));
        if (issue.latest_history_id != null) fd.append('expected_history_id', String(issue.latest_history_id));

        if (triggerBtn) {
            triggerBtn.disabled = true;
            triggerBtn.dataset.originalText = triggerBtn.textContent;
            triggerBtn.textContent = 'Saving...';
        }

        try {
            var response = await fetch(issuesApiBase, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Session-Refresh': '1' }
            });
            var result = await response.json();
            if (!response.ok || !result || result.error) {
                throw new Error((result && result.error) ? result.error : 'Failed to update issue status');
            }

            var updatedIssue = result.issue || result.data || result;
            var issueIndex = issues.findIndex(function (item) { return String(item.id) === String(issueId); });
            if (issueIndex >= 0) {
                issues[issueIndex] = Object.assign({}, issues[issueIndex], updatedIssue);
            }

            renderFinalIssues();
            if (typeof showToast === 'function') {
                var statusLabel = (ProjectConfig.issueStatuses || []).find(function (status) { return String(status.id) === String(statusId); });
                var label = statusLabel ? (statusLabel.status_label || statusLabel.name || 'Status updated') : 'Status updated';
                showToast('Issue moved to ' + label, 'success');
            }

            // Refresh regression stats if the panel exists
            if (typeof window.loadRegressionStats === 'function') {
                window.loadRegressionStats();
            }
        } catch (error) {
            if (typeof showToast === 'function') {
                showToast(error && error.message ? error.message : 'Failed to update issue status', 'danger');
            }
        } finally {
            if (triggerBtn) {
                triggerBtn.disabled = false;
                triggerBtn.textContent = triggerBtn.dataset.originalText || triggerBtn.textContent;
                delete triggerBtn.dataset.originalText;
            }
        }
    }

    function syncClientIssueStatusOptions() {
        if (userRole !== 'client') return;

        var statusEl = document.getElementById('finalIssueStatus');
        if (!statusEl) return;

        var selectedValue = String(statusEl.value || '');
        Array.prototype.forEach.call(statusEl.options || [], function (option) {
            var normalized = normalizeIssueStatusSlug(option.text || option.label || option.value);
            var isCurrent = String(option.value) === selectedValue;
            var isAllowed = isClientIssueStatusAllowed(normalized);
            option.hidden = !(isAllowed || isCurrent);
            option.disabled = !(isAllowed || isCurrent);
            option.dataset.clientAllowed = isAllowed ? '1' : '0';
        });
    }

    function syncResolutionStatusOptions() {
        var statusEl = document.getElementById('finalIssueStatus');
        if (!statusEl || canCurrentUserMarkResolved()) return;

        var selectedValue = String(statusEl.value || '');
        Array.prototype.forEach.call(statusEl.options || [], function (option) {
            var normalized = normalizeIssueStatusSlug(option.text || option.label || option.value);
            var isCurrent = String(option.value) === selectedValue;
            var restricted = isResolutionStatus(normalized);
            if (!restricted) {
                return;
            }
            option.hidden = !isCurrent;
            option.disabled = !isCurrent;
        });
    }

    function updateClientIssueSidebarHeader(issue) {
        if (userRole !== 'client') return;

        var keyEl = document.getElementById('clientIssueSidebarKey');
        var titleEl = document.getElementById('finalEditorTitle');
        if (!keyEl || !titleEl) return;

        var issueKey = issue && issue.issue_key ? String(issue.issue_key) : 'New Issue';
        var issueTitle = issue && issue.title ? String(issue.title) : 'Issue conversation';

        keyEl.textContent = issueKey;
        titleEl.textContent = issueTitle;
    }

    function applyClientIssueEditingState(enable) {
        if (userRole !== 'client') return;

        var modal = document.getElementById('finalIssueModal');
        if (!modal) return;

        var allowedIds = {
            finalIssueStatus: true,
            finalIssueCommentType: true,
            finalIssueCommentEditor: true
        };

        modal.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el.type === 'hidden') return;
            if (allowedIds[el.id]) {
                el.disabled = !enable;
                return;
            }
            el.disabled = true;
        });

        ['btnResetToTemplate', 'btnOpenUrlSelectionModal', 'btnApplyUrlSelection', 'btnCopyGroupedUrls'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.disabled = true;
        });

        var commentTypeEl = document.getElementById('finalIssueCommentType');
        if (commentTypeEl) {
            commentTypeEl.value = 'regression';
            commentTypeEl.disabled = true;
        }

        var addCommentBtn = document.getElementById('finalIssueAddCommentBtn');
        if (addCommentBtn) addCommentBtn.disabled = !enable;

        var saveBtn = document.getElementById('finalIssueSaveBtn');
        if (saveBtn) saveBtn.disabled = !enable;

        if (window.jQuery && jQuery.fn.summernote) {
            jQuery('#finalIssueDetails').summernote('disable');
            if (document.getElementById('finalIssueCommentEditor')) {
                jQuery('#finalIssueCommentEditor').summernote(enable ? 'enable' : 'disable');
            }
        }

        if (window.jQuery && jQuery.fn.select2) {
            jQuery('#finalIssuePages, #finalIssueGroupedUrls, #finalIssueReporters, #finalIssueAssignee').prop('disabled', true).trigger('change.select2');
        }

        syncClientIssueStatusOptions();
        syncResolutionStatusOptions();
    }

    function normalizeClientFinalIssueOverlayState() {
        if (userRole !== 'client') return;

        var modalEl = document.getElementById('finalIssueModal');
        if (modalEl) {
            modalEl.classList.add('show', 'is-open');
            modalEl.setAttribute('aria-hidden', 'false');
            modalEl.removeAttribute('aria-modal');
        }

        document.body.classList.add('client-issue-sidebar-open');
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.body.removeAttribute('aria-hidden');

        document.querySelectorAll('.modal-backdrop').forEach(function (el) {
            el.remove();
        });

    }

    function syncClientSidebarExpandButton() {
        if (userRole !== 'client') return;
        var modalEl = document.getElementById('finalIssueModal');
        var btn = modalEl ? modalEl.querySelector('.client-sidebar-expand') : null;
        if (!btn) return;
        var active = modalEl.classList.contains('is-dialog-expanded');
        btn.setAttribute('aria-label', active ? 'Collapse dialog' : 'Expand dialog');
        btn.setAttribute('title', active ? 'Collapse dialog' : 'Expand dialog');
        var icon = btn.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-expand', !active);
            icon.classList.toggle('fa-compress', active);
        }
    }

    function setClientSidebarDialogExpanded(enabled) {
        if (userRole !== 'client') return;
        var modalEl = document.getElementById('finalIssueModal');
        if (!modalEl) return;
        modalEl.classList.toggle('is-dialog-expanded', !!enabled);
        document.body.classList.toggle('client-issue-sidebar-dialog-expanded', !!enabled);
        syncClientSidebarExpandButton();
    }

    function toggleClientSidebarDialogExpanded() {
        if (userRole !== 'client') return;
        var modalEl = document.getElementById('finalIssueModal');
        if (!modalEl) return;
        setClientSidebarDialogExpanded(!modalEl.classList.contains('is-dialog-expanded'));
    }

    function getFinalIssueModalInstance(modalEl) {
        if (userRole === 'client') {
            return null;
        }

        if (!modalEl || !(window.bootstrap && bootstrap.Modal)) {
            return null;
        }

        var instance = bootstrap.Modal.getInstance(modalEl);
        if (instance) {
            return instance;
        }

        return new bootstrap.Modal(modalEl, userRole === 'client'
            ? { backdrop: false, focus: false, keyboard: true }
            : {});
    }

    function showFinalIssueOverlay(modalEl) {
        if (userRole === 'client') {
            if (!modalEl) return;
            normalizeClientFinalIssueOverlayState();
            setTimeout(function () {
                modalEl.dispatchEvent(new CustomEvent('shown.bs.modal'));
            }, 0);
            return;
        }

        var instance = getFinalIssueModalInstance(modalEl);
        if (!instance) return;

        instance.show();
        if (userRole === 'client') {
            setTimeout(normalizeClientFinalIssueOverlayState, 0);
        }
    }

    function closeClientFinalIssueOverlay() {
        if (userRole !== 'client') return;

        var modalEl = document.getElementById('finalIssueModal');
        if (!modalEl) return;

        modalEl.classList.remove('show', 'is-open');
        modalEl.classList.remove('is-dialog-expanded');
        modalEl.setAttribute('aria-hidden', 'true');
        stopIssuePresenceTracking();
        clearIssueConflictNotice();
        document.body.classList.remove('client-issue-sidebar-open');
        document.body.classList.remove('client-issue-sidebar-dialog-expanded');
        cleanupModalOverlayState();
        modalEl.dispatchEvent(new CustomEvent('hidden.bs.modal'));
    }

    function requestClientFinalIssueOverlayClose() {
        if (userRole !== 'client') return;

        var finalIssueModalEl = document.getElementById('finalIssueModal');
        if (!finalIssueModalEl) return;

        if (finalIssueBypassCloseConfirm) {
            finalIssueBypassCloseConfirm = false;
            stopDraftAutosave();
            issueData.initialFormState = null;
            closeClientFinalIssueOverlay();
            return;
        }

        var editId = document.getElementById('finalIssueEditId').value;
        if (hasFormChanges()) {
            showDraftConfirmation(function (action) {
                if (action === 'save') {
                    if (!editId) {
                        saveDraft().then(function () {
                            stopDraftAutosave();
                            issueData.initialFormState = null;
                            finalIssueBypassCloseConfirm = true;
                            closeClientFinalIssueOverlay();
                        });
                    } else {
                        document.getElementById('finalIssueSaveBtn').click();
                    }
                } else if (action === 'discard') {
                    if (!editId) {
                        deleteDraft().then(function () {
                            stopDraftAutosave();
                            issueData.initialFormState = null;
                            finalIssueBypassCloseConfirm = true;
                            closeClientFinalIssueOverlay();
                        });
                    } else {
                        stopDraftAutosave();
                        issueData.initialFormState = null;
                        finalIssueBypassCloseConfirm = true;
                        closeClientFinalIssueOverlay();
                    }
                }
            }, editId);
            return;
        }

        stopDraftAutosave();
        issueData.initialFormState = null;
        closeClientFinalIssueOverlay();
    }

    function cleanupModalOverlayState() {
        if (document.querySelectorAll('.modal.show').length > 0) return;
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.body.removeAttribute('aria-hidden'); // Ensure body is not hidden
        document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
        
        // Remove any stale ARIA hidden attributes from common layouts
        document.querySelectorAll('.main-wrapper, .page-wrapper').forEach(function(el) {
            el.removeAttribute('aria-hidden');
        });
    }

    function issueNotify(message, type) {
        if (typeof window.showToast === 'function') {
            showToast(String(message || ''), type || 'info');
        }
    }

    function clearIssueConflictNotice() {
        var existing = document.getElementById('finalIssueConflictNotice');
        if (existing) existing.remove();
    }

    function showIssueConflictNotice(message) {
        var modalBody = document.querySelector('#finalIssueModal .modal-body');
        if (!modalBody) return;
        clearIssueConflictNotice();
        var box = document.createElement('div');
        box.id = 'finalIssueConflictNotice';
        box.className = 'alert alert-warning d-flex align-items-start gap-2 mb-3';
        box.setAttribute('role', 'alert');
        box.innerHTML =
            '<i class="fas fa-exclamation-triangle mt-1" aria-hidden="true"></i>' +
            '<div><strong>Issue Updated By Another User.</strong><br>' +
            escapeHtml(message || 'Latest data has been loaded. Please review and save again.') +
            '</div>';
        modalBody.insertBefore(box, modalBody.firstChild);
    }

    function showIssueConflictDialog(message, onOk) {
        var existing = document.getElementById('issueConflictModal');
        if (existing) {
            try {
                var existingInst = bootstrap.Modal.getInstance(existing);
                if (existingInst) existingInst.dispose();
            } catch (e) { }
            existing.remove();
        }

        var html = '' +
            '<div class="modal fade" id="issueConflictModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">' +
            '  <div class="modal-dialog modal-dialog-centered">' +
            '    <div class="modal-content">' +
            '      <div class="modal-header bg-warning-subtle">' +
            '        <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Conflict Detected</h5>' +
            '      </div>' +
            '      <div class="modal-body">' +
            '        <p class="mb-0">' + escapeHtml(message || 'This issue was modified by another user. Latest data has been loaded. Please review and save again.') + '</p>' +
            '      </div>' +
            '      <div class="modal-footer">' +
            '        <button type="button" class="btn btn-primary" id="issueConflictOkBtn">OK</button>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var modalEl = document.getElementById('issueConflictModal');
        var okBtn = document.getElementById('issueConflictOkBtn');
        var modal = new bootstrap.Modal(modalEl);

        okBtn.addEventListener('click', function () {
            modal.hide();
            if (typeof onOk === 'function') onOk();
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.remove();
            cleanupModalOverlayState();
        });
        modal.show();
    }

    function applyIssueQaPermissionState() {
        var $qa = jQuery('#finalIssueQaStatus');
        if ($qa.length) {
            $qa.prop('disabled', !canUpdateIssueQaStatus).trigger('change.select2');
        }
        var reporterSelects = document.querySelectorAll('#reporterQaStatusRows .reporter-qa-status-select');
        reporterSelects.forEach(function (sel) {
            sel.disabled = !canUpdateIssueQaStatus;
        });
        if (!canUpdateIssueQaStatus) {
            if ($qa.length) $qa.attr('title', 'Only authorized users can update QA status.');
        } else {
            if ($qa.length) $qa.removeAttr('title');
        }
    }

    function dispatchIssuesChanged(detail) {
        try {
            var payload = Object.assign({ source: 'internal' }, detail || {});
            document.dispatchEvent(new CustomEvent('pms:issues-changed', { detail: payload }));
        } catch (e) { }
    }

    // Expose issueData for external access if needed

    function ensurePageStore(store, pageId) {
        if (!store[pageId]) store[pageId] = { final: [], review: [] };
        if (!store[pageId].final) store[pageId].final = [];
        if (!store[pageId].review) store[pageId].review = [];
    }

    function readReviewStore() {
        try {
            var raw = localStorage.getItem(reviewStorageKey);
            var parsed = raw ? JSON.parse(raw) : {};
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeReviewStore(store) {
        try {
            localStorage.setItem(reviewStorageKey, JSON.stringify(store || {}));
        } catch (e) { }
    }

    function getLocalReviewItems(pageId) {
        var store = readReviewStore();
        var key = String(pageId || '');
        var arr = store[key];
        return Array.isArray(arr) ? arr : [];
    }

    function setLocalReviewItems(pageId, items) {
        var store = readReviewStore();
        var key = String(pageId || '');
        store[key] = Array.isArray(items) ? items : [];
        writeReviewStore(store);
    }

    function canEdit() {
        return true;
    }

    function getTesterRegressionLockMessage() {
        var roundNumber = parseInt(testerRegressionLock.roundNumber || 0, 10);
        if (roundNumber > 0) {
            return 'RR ' + roundNumber + ' in progress. Tester issue details are read-only until this round is completed.';
        }
        return 'Regression round is in progress. Tester issue details are read-only until round completion.';
    }

    function isTesterEditLockedByRegression() {
        return false;
    }

    async function refreshTesterRegressionLock() {
        // Feature disabled - always returns false immediately
        return false;
    }

    function applyTesterRegressionReadonlyState() {
        // Regression lock concept removed. 
        // Ensure Comment Type and Metadata fields are always enabled for everyone.
        var commentTypeEl = document.getElementById('finalIssueCommentType');
        if (commentTypeEl) commentTypeEl.disabled = false;
        
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.issue-dynamic-field').prop('disabled', false).trigger('change.select2');
        }

        var detailsEl = document.getElementById('finalIssueDetails');
        if (window.jQuery && jQuery.fn.summernote) {
            var $details = jQuery('#finalIssueDetails');
            if ($details.length) {
                $details.summernote('enable');
            }
        } else if (detailsEl) {
            detailsEl.disabled = false;
        }

        if (detailsEl) {
            detailsEl.removeAttribute('data-regression-readonly');
            detailsEl.removeAttribute('title');
        }

        if (window.jQuery && jQuery.fn.select2) {
            jQuery('#finalIssuePages, #finalIssueGroupedUrls, #finalIssueReporters, #finalIssueAssignee').prop('disabled', false).trigger('change.select2');
        }
    }

    function updateEditingState() {
        var editable = canEdit() && !!issueData.selectedPageId;
        var addBtn = document.getElementById('issueAddFinalBtn');
        if (addBtn) addBtn.disabled = !editable;

        if (!canEdit()) {
            hideEditors();
        }
    }

    function escapeAttr(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    var finalIssueComposeExpanded = false;

    function getFinalIssueCommentEditorTextLength() {
        var html = '';
        if (window.jQuery && jQuery.fn.summernote && jQuery('#finalIssueCommentEditor').length && jQuery('#finalIssueCommentEditor').data('summernote')) {
            html = jQuery('#finalIssueCommentEditor').summernote('code') || '';
            return jQuery('<div>').html(html).text().length;
        }
        var editorEl = document.getElementById('finalIssueCommentEditor');
        if (!editorEl) return 0;
        html = editorEl.value || '';
        var container = document.createElement('div');
        container.innerHTML = html;
        return (container.textContent || container.innerText || '').length;
    }

    function updateFinalIssueCommentCharCount() {
        var countEl = document.getElementById('finalIssueCommentCharCount');
        var count = getFinalIssueCommentEditorTextLength();
        if (countEl) countEl.textContent = count + '/1000';
        var editorEl = document.getElementById('finalIssueCommentEditor');
        if (editorEl) editorEl.classList.toggle('is-invalid', count > 1000);
    }

    function focusFinalIssueCommentEditor() {
        if (window.jQuery && jQuery.fn.summernote && jQuery('#finalIssueCommentEditor').length && jQuery('#finalIssueCommentEditor').data('summernote')) {
            try {
                jQuery('#finalIssueCommentEditor').summernote('focus');
                return;
            } catch (e) { }
        }
        var editorEl = document.getElementById('finalIssueCommentEditor');
        if (editorEl && typeof editorEl.focus === 'function') {
            try { editorEl.focus(); } catch (e) { }
        }
    }

    function syncFinalIssueComposeUi() {
        var composerEl = document.getElementById('finalIssueCommentComposer');
        var composeBodyEl = document.getElementById('finalIssueComposeBody');
        var composeToggleEl = document.getElementById('finalIssueComposeToggle');
        if (composerEl) composerEl.classList.toggle('collapsed', !finalIssueComposeExpanded);
        if (composeBodyEl) composeBodyEl.classList.toggle('open', !!finalIssueComposeExpanded);
        if (composeToggleEl) {
            composeToggleEl.classList.toggle('expanded', !!finalIssueComposeExpanded);
            composeToggleEl.innerHTML = finalIssueComposeExpanded
                ? '<i class="fas fa-chevron-down"></i> Hide Compose'
                : '<i class="fas fa-comment-dots"></i> Compose';
            composeToggleEl.setAttribute('aria-expanded', finalIssueComposeExpanded ? 'true' : 'false');
        }
        updateFinalIssueCommentCharCount();
    }

    function setFinalIssueComposeExpanded(expanded, options) {
        finalIssueComposeExpanded = !!expanded;
        syncFinalIssueComposeUi();
        if (finalIssueComposeExpanded && (!options || options.focus !== false)) {
            setTimeout(focusFinalIssueCommentEditor, 0);
        }
    }

    function normalizeIssueImageSrc(src) {
        var rawSrc = String(src || '').trim();
        if (!rawSrc) return rawSrc;

        try {
            var parsed = new URL(rawSrc, window.location.origin);
            var pathname = parsed.pathname || '';
            var prefixMatch = pathname.match(/^\/(?:(PMS(?:-UAT)?)\/)?((?:uploads\/|assets\/uploads\/|api\/public_image\.php|api\/secure_file\.php).*)$/i);
            if (!prefixMatch) {
                return rawSrc;
            }

            var normalizedBaseDir = String(baseDir || '').replace(/\/+$/, '');
            var normalizedTarget = '/' + String(prefixMatch[2] || '').replace(/^\/+/, '');
            if (/^\/(?:assets\/uploads|uploads)\/(issues|chat)\//i.test(normalizedTarget)) {
                var relativePath = normalizedTarget.replace(/^\//, '');
                return (normalizedBaseDir ? normalizedBaseDir : '')
                    + '/api/secure_file.php?path='
                    + encodeURIComponent(relativePath)
                    + (parsed.hash || '');
            }

            var normalizedPath = (normalizedBaseDir ? normalizedBaseDir : '') + normalizedTarget;
            if (!normalizedPath) normalizedPath = normalizedTarget;
            if (normalizedPath.charAt(0) !== '/') normalizedPath = '/' + normalizedPath;

            if (/^(?:https?:)?\/\//i.test(rawSrc)) {
                parsed.pathname = normalizedPath;
                return parsed.toString();
            }

            return normalizedPath + (parsed.search || '') + (parsed.hash || '');
        } catch (e) {
            return rawSrc;
        }
    }

    function tryRecoverIssueImageElement(img) {
        if (!img || img.dataset.issueImageRecoveryAttempted === '1') return false;
        var currentSrc = img.getAttribute('src') || img.src || '';
        var normalizedSrc = normalizeIssueImageSrc(currentSrc);
        if (!normalizedSrc || normalizedSrc === currentSrc) return false;
        img.dataset.issueImageRecoveryAttempted = '1';
        img.setAttribute('src', normalizedSrc);
        return true;
    }

    function cleanInstanceValue(raw) {
        var txt = String(raw || '').trim();
        if (!txt) return '';
        var lower = txt.toLowerCase();
        var pollutedKeys = ['issue:', 'rule id:', 'impact:', 'source url:', 'description:', 'failure:', 'recommendation:', 'incorrect code:'];
        var isPolluted = pollutedKeys.some(function (k) { return lower.indexOf(k) !== -1; });
        if (!isPolluted) return txt;

        var extracted = [];
        var match;
        var re = /instance\s+\d+\s*:\s*([^-\n\r][^-\n\r]*)/ig;
        while ((match = re.exec(txt)) !== null) {
            var val = String(match[1] || '').trim();
            if (val && extracted.indexOf(val) === -1) extracted.push(val);
        }
        return extracted.join(' | ');
    }

    function parseInstanceParts(instanceRaw) {
        var v = String(instanceRaw || '').trim();
        if (!v) return { name: '', path: '' };
        var parts = v.split('|');
        if (parts.length >= 2) {
            return { name: String(parts[0] || '').trim(), path: String(parts.slice(1).join('|') || '').trim() };
        }
        return { name: '', path: v };
    }

    function extractLabelFromIncorrectCode(codeHtml) {
        var raw = String(codeHtml || '').trim();
        if (!raw) return '';
        var tmp = document.createElement('div');
        tmp.innerHTML = raw;
        var txt = (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
        if (!txt) return '';
        if (txt.length > 60) txt = txt.slice(0, 57) + '...';
        return txt;
    }

    function enrichInstanceWithName(instanceRaw, incorrectCode) {
        var cleaned = cleanInstanceValue(instanceRaw || '');
        var parsed = parseInstanceParts(cleaned);
        if (parsed.name) return parsed.name + ' | ' + parsed.path;
        var inferred = extractLabelFromIncorrectCode(incorrectCode || '');
        if (!inferred) return parsed.path || '';
        return inferred + ' | ' + (parsed.path || '');
    }

    function formatInstanceReadable(instanceRaw) {
        var p = parseInstanceParts(instanceRaw);
        var pathText = String(p.path || '').trim();
        if (/^path\s*:/i.test(pathText)) pathText = pathText.replace(/^path\s*:/i, '').trim();
        if (p.name && pathText) return p.name + ' | Path: ' + pathText;
        if (pathText) return 'Path: ' + pathText;
        return p.name || '';
    }

    function formatFailureSummaryText(raw) {
        var s = String(raw || '').trim();
        if (!s) return '';
        s = s.replace(/Fix any of the following:\s*/ig, '');
        s = s.replace(/\s*\n\s*/g, ' | ');
        s = s.replace(/\|\s*\|/g, '|');
        s = s.replace(/^\s*\|\s*|\s*\|\s*$/g, '');
        s = s.replace(/\s{2,}/g, ' ').trim();
        return s;
    }

    function normalizeIncorrectCodeList(incorrectCodes, fallbackBad) {
        var codeList = Array.isArray(incorrectCodes) ? incorrectCodes.filter(function (x) { return String(x || '').trim() !== ''; }) : [];
        if (!codeList.length && fallbackBad) codeList = [fallbackBad];
        var uniq = [];
        codeList.forEach(function (c) {
            var val = cleanIncorrectCodeSnippet(c);
            if (val && uniq.indexOf(val) === -1) uniq.push(val);
        });
        return uniq;
    }

    function decodeHtmlEntities(text) {
        var s = String(text || '');
        if (!s) return '';
        var el = document.createElement('textarea');
        el.innerHTML = s;
        return el.value;
    }

    function cleanIncorrectCodeSnippet(raw) {
        var s = String(raw || '').trim();
        if (!s) return '';
        s = s.replace(/^\s*(<\/strong><\/p>\s*)+/ig, '');
        s = s.replace(/^\s*(&lt;\/strong&gt;&lt;\/p&gt;\s*)+/ig, '');
        s = s.replace(/<p>\s*<strong>\s*$/ig, '');
        s = s.replace(/&lt;p&gt;\s*&lt;strong&gt;\s*$/ig, '');
        s = s.replace(/&lt;\/pre&gt;/ig, '');
        s = s.replace(/&lt;\/code&gt;\s*&lt;\/pre&gt;/ig, '');
        s = decodeHtmlEntities(s);
        s = s.replace(/<pre>\s*<code>/ig, '');
        s = s.replace(/<\/code>\s*<\/pre>/ig, '');
        return String(s || '').trim();
    }

    function extractIncorrectCodeSnippets(raw) {
        var src = String(raw || '').trim();
        if (!src) return [];
        var out = [];

        // Try direct <pre><code> blocks first.
        var preRe = /<pre[^>]*>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/ig;
        var m;
        while ((m = preRe.exec(src)) !== null) {
            var snippet = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet && out.indexOf(snippet) === -1) out.push(snippet);
        }
        if (out.length) return out;

        // Try decoded content if it was entity-encoded.
        var decoded = decodeHtmlEntities(src);
        preRe.lastIndex = 0;
        while ((m = preRe.exec(decoded)) !== null) {
            var snippet2 = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet2 && out.indexOf(snippet2) === -1) out.push(snippet2);
        }
        if (out.length) return out;

        // Support plain <code> blocks too.
        var codeRe = /<code[^>]*>([\s\S]*?)<\/code>/ig;
        while ((m = codeRe.exec(src)) !== null) {
            var snippet3 = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet3 && out.indexOf(snippet3) === -1) out.push(snippet3);
        }
        if (out.length) return out;
        codeRe.lastIndex = 0;
        while ((m = codeRe.exec(decoded)) !== null) {
            var snippet4 = cleanIncorrectCodeSnippet(m[1] || '');
            if (snippet4 && out.indexOf(snippet4) === -1) out.push(snippet4);
        }
        if (out.length) return out;

        // Fallback: treat entire text as one snippet.
        var single = cleanIncorrectCodeSnippet(src);
        if (single) out.push(single);
        return out;
    }

    function renderIncorrectCodeBlocks(codeList) {
        if (!Array.isArray(codeList) || !codeList.length) return '<code class="issue-incorrect-code"></code>';
        return codeList.map(function (c) {
            var safe = escapeHtml(String(c || '')).replace(/\n/g, '<br>');
            return '<code class="issue-incorrect-code d-block mb-2">' + safe + '</code>';
        }).join('');
    }

    function injectIncorrectCodeBlocksIntoSectionedRaw(raw, codeList) {
        var text = String(raw || '');
        if (!text) return text;
        var blocks = renderIncorrectCodeBlocks(codeList);

        // HTML section format
        var htmlPattern = /(<p[^>]*>\s*<strong>\s*\[Incorrect Code\]\s*<\/strong>\s*<\/p>)([\s\S]*?)(<p[^>]*>\s*<strong>\s*\[(Screenshots|Recommendation)\]\s*<\/strong>\s*<\/p>)/i;
        if (htmlPattern.test(text)) {
            return text.replace(htmlPattern, '$1' + blocks + '$3');
        }

        // Plain-text section format
        var plainPattern = /(\[Incorrect Code\]\s*)([\s\S]*?)(\n\s*\[(Screenshots|Recommendation)\])/i;
        if (plainPattern.test(text)) {
            return text.replace(plainPattern, '$1\n\n' + blocks + '\n\n$3');
        }
        return text;
    }

    function extractIncorrectCodeSectionRaw(raw) {
        var text = String(raw || '');
        if (!text) return '';
        var mHtml = text.match(/<p[^>]*>\s*<strong>\s*\[Incorrect Code\]\s*<\/strong>\s*<\/p>([\s\S]*?)<p[^>]*>\s*<strong>\s*\[(Screenshots|Recommendation)\]\s*<\/strong>\s*<\/p>/i);
        if (mHtml && mHtml[1]) return String(mHtml[1]).trim();
        var mPlain = text.match(/\[Incorrect Code\]\s*([\s\S]*?)\n\s*\[(Screenshots|Recommendation)\]/i);
        if (mPlain && mPlain[1]) return String(mPlain[1]).trim();
        return '';
    }

    function normalizeReviewDetailsForEditor(raw) {
        var text = String(raw || '');
        if (!text) return text;
        if (!/\[Incorrect Code\]/i.test(text)) return text;
        var sectionRaw = extractIncorrectCodeSectionRaw(text);
        var snippets = extractIncorrectCodeSnippets(sectionRaw);
        var codeList = normalizeIncorrectCodeList(snippets, '');
        return injectIncorrectCodeBlocksIntoSectionedRaw(text, codeList);
    }

    function wrapReviewDetailsWithMeta(detailsHtml, title, meta) {
        var raw = String(detailsHtml || '');
        // Clean known broken leading fragments injected by previous malformed saves.
        raw = raw.replace(/^\s*(<\/strong><\/p>\s*)+/i, '');
        var cleanTitle = String(title || '').replace(/-->/g, '').trim();
        var ruleId = String((meta && meta.rule_id) || '').replace(/-->/g, '').trim();
        var impact = String((meta && meta.impact) || '').replace(/-->/g, '').trim();
        var sourceUrl = String((meta && meta.source_url) || '').replace(/-->/g, '').trim();
        var marker =
            '<!-- ISSUE_TITLE: ' + cleanTitle + ' -->\n' +
            '<!-- RULE_ID: ' + ruleId + ' -->\n' +
            '<!-- IMPACT: ' + impact + ' -->\n' +
            '<!-- SOURCE_URL: ' + sourceUrl + ' -->\n';
        // Replace existing marker if present.
        raw = raw.replace(/^\s*<!--\s*ISSUE_TITLE:.*?-->\s*/i, '');
        raw = raw.replace(/^\s*<!--\s*RULE_ID:.*?-->\s*/i, '');
        raw = raw.replace(/^\s*<!--\s*IMPACT:.*?-->\s*/i, '');
        raw = raw.replace(/^\s*<!--\s*SOURCE_URL:.*?-->\s*/i, '');
        return marker + raw;
    }

    function extractUrlsFromDetails(details) {
        var text = String(details || '');
        var urls = [];
        var re = /https?:\/\/[^\s<>"']+/ig;
        var m;
        while ((m = re.exec(text)) !== null) {
            var u = String(m[0] || '').trim();
            if (u && urls.indexOf(u) === -1) urls.push(u);
        }
        return urls;
    }

    function extractSourceUrlsFromDetails(details) {
        var text = String(details || '');
        var urls = [];
        var re = /URL\s+\d+\s*:\s*(https?:\/\/[^\s<>"']+)/ig;
        var m;
        while ((m = re.exec(text)) !== null) {
            var u = String(m[1] || '').trim();
            if (u && urls.indexOf(u) === -1) urls.push(u);
        }
        return urls;
    }

    function extractLabeledValue(text, label) {
        var src = String(text || '');
        var labels = ['Issue', 'Rule ID', 'Impact', 'Source URL', 'Description', 'Failure', 'Incorrect Code', 'Screenshots', 'Recommendation'];
        var pattern = new RegExp('\\b' + label.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&') + ':\\s*([\\s\\S]*?)(?=\\s+(?:' + labels.join('|') + '):|$)', 'i');
        var m = src.match(pattern);
        return m && m[1] ? String(m[1]).trim() : '';
    }

    function normalizeScreenshotList(rawValue, extra) {
        var all = [];
        function add(v) {
            var s = String(v || '').trim();
            if (!s) return;
            if (!/^https?:\/\//i.test(s) && s.indexOf('/assets/uploads/automated_findings/') === -1) return;
            if (all.indexOf(s) === -1) all.push(s);
        }
        String(rawValue || '').split(/\s*\|\s*|\s*,\s*|\s*\n\s*/).forEach(add);
        if (Array.isArray(extra)) extra.forEach(add);
        return all;
    }

    function buildSectionedReviewDetails(baseDetails, urls, instances, fallback, entryRows, incorrectCodes, screenshots) {
        var raw = String(baseDetails || '');
        var bad = extractLabeledValue(raw, 'Incorrect Code') || String((fallback && fallback.incorrect_code) || '').trim();
        var codeList = normalizeIncorrectCodeList(incorrectCodes, bad);
        if (/\[Actual Results\]|\[Incorrect Code\]|\[Recommendation\]|\[Correct Code\]/i.test(raw)) {
            var cleanedRaw = raw.replace(/Fix any of the following:\s*/ig, '');
            return injectIncorrectCodeBlocksIntoSectionedRaw(cleanedRaw, codeList);
        }
        var desc = extractLabeledValue(raw, 'Description') || String((fallback && fallback.description_text) || '').trim();
        var fail = formatFailureSummaryText(extractLabeledValue(raw, 'Failure') || String((fallback && fallback.failure_summary) || ''));
        var rec = extractLabeledValue(raw, 'Recommendation') || String((fallback && fallback.recommendation) || '').trim();

        var parts = [];
        parts.push('<strong>[Actual Results]</strong><br>');
        if (desc) parts.push('<span>' + escapeHtml(desc) + '</span><br>');
        var rows = Array.isArray(entryRows) ? entryRows.filter(function (r) { return r && r.instance; }) : [];
        if (rows.length) {
            var byUrl = {};
            rows.forEach(function (r) {
                var key = String(r.url || '').trim() || (urls[0] || '');
                if (!byUrl[key]) byUrl[key] = [];
                var rowKey = [String(r.instance || ''), String(r.failure || '')].join('||');
                var exists = byUrl[key].some(function (x) {
                    return [String(x.instance || ''), String(x.failure || '')].join('||') === rowKey;
                });
                if (!exists) byUrl[key].push({ instance: String(r.instance || ''), failure: formatFailureSummaryText(r.failure || '') });
            });
            var urlList = Object.keys(byUrl).filter(function (u) { return String(u || '').trim() !== ''; });
            if (!urlList.length && urls.length) urlList = [urls[0]];
            urlList.forEach(function (u, idx) {
                parts.push('<strong>URL ' + (idx + 1) + ':</strong> ' + escapeHtml(u) + '<br>');
                var urlRows = byUrl[u] || [];
                if (!urlRows.length && instances.length) {
                    if (fail) parts.push('<span class="review-actual-results-text">' + escapeHtml(fail) + '</span><br>');
                    parts.push('<ul class="review-actual-results-list">' + instances.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul>');
                    return;
                }
                var uniqueFails = [];
                urlRows.forEach(function (r) {
                    var f = String(r.failure || '').trim();
                    if (f && uniqueFails.indexOf(f) === -1) uniqueFails.push(f);
                });
                if (uniqueFails.length <= 1) {
                    var sharedFail = uniqueFails[0] || fail;
                    if (sharedFail) parts.push('<span class="review-actual-results-text">' + escapeHtml(sharedFail) + '</span><br>');
                    parts.push('<ul class="review-actual-results-list">' + urlRows.map(function (r) { return '<li>' + escapeHtml(r.instance) + '</li>'; }).join('') + '</ul>');
                } else {
                    parts.push('<ul class="review-actual-results-list">' + urlRows.map(function (r) {
                        var line = '<li>' + escapeHtml(r.instance);
                        if (r.failure) line += '<br><span class="review-actual-results-text">' + escapeHtml(r.failure) + '</span>';
                        line += '</li>';
                        return line;
                    }).join('') + '</ul>');
                }
            });
        } else {
            if (urls.length) parts.push('<strong>URL 1:</strong> ' + escapeHtml(urls[0]) + '<br>');
            if (fail) parts.push('<span class="review-actual-results-text">' + escapeHtml(fail) + '</span><br>');
            if (instances.length) {
                parts.push('<ul class="review-actual-results-list">' + instances.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul>');
            }
        }
        parts.push('<strong>[Incorrect Code]</strong><br>');
        parts.push(renderIncorrectCodeBlocks(codeList));
        parts.push('<strong>[Screenshots]</strong><br>');
        var shotList = normalizeScreenshotList(extractLabeledValue(raw, 'Screenshots'), screenshots);
        if (shotList.length) {
            parts.push('<div class="issue-image-grid">' + shotList.map(function (u, idx) {
                var src = (u.indexOf('http') === 0 ? u : (u.charAt(0) === '/' ? u : ('/' + u)));
                var safeSrc = escapeAttr(src);
                var alt = 'Screenshot ' + (idx + 1);
                return '<a href="' + safeSrc + '" target="_blank" rel="noopener" aria-label="' + escapeAttr(alt) + '">' +
                    '<img src="' + safeSrc + '" alt="' + escapeAttr(alt) + '" class="issue-image-thumb">' +
                    '</a>';
            }).join('') + '</div>');
        } else {
            parts.push('<br>');
        }
        parts.push('<strong>[Recommendation]</strong><br>');
        parts.push('<span>' + escapeHtml(rec) + '</span><br><br>');
        parts.push('<strong>[Correct Code]</strong><br>');
        parts.push('<pre><code></code></pre>');
        return parts.join('');
    }

    // Review scanning system removed.
    async function loadReviewFindings() { return; }

    async function loadFinalIssues(pageId, options) {
        var opts = options || {};
        if (!pageId) return;
        var tbody = document.getElementById('finalIssuesBody');
        if (tbody && !opts.silent) tbody.innerHTML = '<tr><td colspan="9" class="text-muted text-center">Loading final issues...</td></tr>';
        var store = issueData.pages;
        ensurePageStore(store, pageId);
        try {
            var url = issuesApiBase + '?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var text = await res.text();
            var json = JSON.parse(text.replace(/^\uFEFF/, ''));
            var items = (json && json.issues) ? json.issues : [];
            var nextFinal = items.map(function (it) {
                return {
                    id: String(it.id),
                    issue_key: it.issue_key || '',
                    title: it.title || 'Issue',
                    details: it.description || '',
                    status: it.status || 'open',
                    status_id: it.status_id || null,
                    qa_status: Array.isArray(it.qa_status) ? it.qa_status : (it.qa_status ? [it.qa_status] : []),
                    severity: it.severity || 'medium',
                    priority: it.priority || 'medium',
                    pages: it.pages || [],
                    grouped_urls: it.grouped_urls || [],
                    reporter_name: it.reporter_name || null,
                    qa_name: it.qa_name || null,
                    assignee_id: it.assignee_id || null,
                    assignee_ids: Array.isArray(it.assignee_ids) && it.assignee_ids.length ? it.assignee_ids.map(String) : (it.assignee_id ? [String(it.assignee_id)] : []),
                    page_id: it.page_id || pageId,
                    client_ready: it.client_ready || 0,
                    // Metadata fields - use correct field names from API
                    environments: it.environments || [],
                    usersaffected: it.usersaffected || [],
                    wcagsuccesscriteria: it.wcagsuccesscriteria || [],
                    wcagsuccesscriterianame: it.wcagsuccesscriterianame || [],
                    wcagsuccesscriterialevel: it.wcagsuccesscriterialevel || [],
                    gigw30: it.gigw30 || [],
                    is17802: it.is17802 || [],
                    common_title: it.common_title || '',
                    reporters: it.reporters || [],
                    reporter_qa_status_map: it.reporter_qa_status_map || {},
                    has_comments: !!it.has_comments,
                    can_tester_delete: (it.can_tester_delete !== false),
                    // Add created_at and updated_at timestamps
                    created_at: it.created_at || null,
                    updated_at: it.updated_at || null,
                    latest_history_id: (it.latest_history_id != null ? Number(it.latest_history_id) : 0)
                };
            });
            var prevSerialized = JSON.stringify(store[pageId].final || []);
            var nextSerialized = JSON.stringify(nextFinal || []);
            if (opts.onlyIfChanged && prevSerialized === nextSerialized) return;
            store[pageId].final = nextFinal;
            renderFinalIssues();
        } catch (e) {
            if (tbody && !opts.silent) tbody.innerHTML = '<tr><td colspan="9" class="text-muted text-center">Unable to load final issues.</td></tr>';
        }
    }

    async function loadCommonIssues(options) {
        var opts = options || {};
        var tbody = document.getElementById('commonIssuesBody');
        if (tbody && !opts.silent) tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Loading common issues...</td></tr>';
        try {
            var url = issuesApiBase + '?action=common_list&project_id=' + encodeURIComponent(projectId);
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            var items = (json && json.common) ? json.common : [];
            var nextCommon = items.map(function (it) {
                return {
                    id: String(it.id),
                    issue_id: it.issue_id,
                    title: it.title || 'Common Issue',
                    description: it.description || '',
                    pages: it.pages || [],
                    has_comments: !!it.has_comments,
                    can_tester_delete: (it.can_tester_delete !== false)
                };
            });
            var prevSerialized = JSON.stringify(issueData.common || []);
            var nextSerialized = JSON.stringify(nextCommon || []);
            if (opts.onlyIfChanged && prevSerialized === nextSerialized) return;
            issueData.common = nextCommon;
            renderCommonIssues();
        } catch (e) {
            if (tbody && !opts.silent) tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Unable to load common issues.</td></tr>';
        }
    }

    function initSelect2() {
        if (!window.jQuery || !jQuery.fn.select2) return;
        jQuery('.issue-select2').each(function () {
            var $el = jQuery(this);
            var $parent = $el.closest('.modal');
            $el.select2({
                width: '100%',
                dropdownParent: $parent.length ? $parent : null
            });
        });
        jQuery('.issue-select2-tags').each(function () {
            var $el = jQuery(this);
            var $parent = $el.closest('.modal');
            $el.select2({
                width: '100%',
                tags: true,
                tokenSeparators: [','],
                dropdownParent: $parent.length ? $parent : null
            });
        });

        // Grouped URLs should allow adding ad-hoc URLs from the modal.
        var $grouped = jQuery('#finalIssueGroupedUrls');
        if ($grouped.length) {
            try { if ($grouped.data('select2')) $grouped.select2('destroy'); } catch (e) { }
            var $gpParent = $grouped.closest('.modal');
            $grouped.select2({
                width: '100%',
                tags: true,
                tokenSeparators: [','],
                closeOnSelect: false,
                placeholder: 'Search or add URLs...',
                dropdownParent: $gpParent.length ? $gpParent : null
            });
        }

        // Add event listener for pages select to auto-populate grouped URLs
        jQuery('#finalIssuePages').off('change.issueGrouped').on('change.issueGrouped', function () {
            // Use the existing updateGroupedUrls function which properly handles URLs
            updateGroupedUrls();
        });

        jQuery('#finalIssueGroupedUrls').off('change.issueSummary').on('change.issueSummary', function () {
            updateUrlSelectionSummary();
            updateGroupedUrlsPreview();
        });

        jQuery('#finalIssueReporters').off('change.issueReporterQa').on('change.issueReporterQa', function () {
            refreshReporterQaStatusEditor();
        });

    }

    function uploadIssueImage(file, $el) {
        if (!file || !file.type || !file.type.startsWith('image/')) return;
        var now = Date.now();
        if (now - issueData.imageUpload.lastPasteTime < 1500) return;
        issueData.imageUpload.lastPasteTime = now;
        issueData.imageUpload.savedRange = $el.summernote('createRange');
        issueData.imageUpload.pendingFile = file;
        issueData.imageUpload.pendingEditor = $el;
        issueData.imageUpload.isEditing = false;
        showImageAltModal('');
    }

    function showImageAltModal(currentAlt) {
        var $modal = jQuery('#imageAltTextModal');
        if (!$modal.length) {
            var modalHtml = `
                <div class="modal fade" id="imageAltTextModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Image Alt-Text</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <label class="form-label">Enter descriptive alt-text for this image:</label>
                                <input type="text" class="form-control" id="imageAltTextInput" placeholder="e.g., Screenshot showing login error">
                                <div class="form-text">Alt-text helps with accessibility and SEO. You can edit this later by clicking the image.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="btnConfirmAltText">Upload Image</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            jQuery('body').append(modalHtml);
            $modal = jQuery('#imageAltTextModal');
            jQuery('#btnConfirmAltText').on('click', confirmImageAltText);
            jQuery('#imageAltTextInput').on('keypress', function (e) {
                if (e.which === 13) { e.preventDefault(); confirmImageAltText(); }
            });
        }
        jQuery('#imageAltTextInput').val(currentAlt || 'Screenshot showing [element] with [accessibility issue]');
        var modal = new bootstrap.Modal($modal[0]);
        modal.show();
        $modal.one('shown.bs.modal', function () { jQuery('#imageAltTextInput').focus(); });
    }

    function confirmImageAltText() {
        var altText = jQuery('#imageAltTextInput').val().trim();
        if (issueData.imageUpload.isEditing && issueData.imageUpload.editingImg) {
            issueData.imageUpload.editingImg.attr('alt', altText || 'Issue Screenshot');
            bootstrap.Modal.getInstance(jQuery('#imageAltTextModal')[0]).hide();
            issueData.imageUpload.isEditing = false;
            issueData.imageUpload.editingImg = null;
        } else if (issueData.imageUpload.pendingFile && issueData.imageUpload.pendingEditor) {
            var file = issueData.imageUpload.pendingFile;
            var $el = issueData.imageUpload.pendingEditor;
            var uploadPromise = (window.PMSSummernoteImage && typeof window.PMSSummernoteImage.uploadImage === 'function')
                ? window.PMSSummernoteImage.uploadImage(file, { uploadUrl: issueImageUploadUrl, credentials: 'same-origin' })
                : (function () {
                    var fd = new FormData();
                    fd.append('image', file);
                    return fetch(issueImageUploadUrl, { 
                        method: 'POST', 
                        body: fd, 
                        headers: { 'X-CSRF-Token': window._csrfToken || '' },
                        credentials: 'same-origin' 
                    }).then(function (r) { return r.json(); });
                })();
            uploadPromise
                .then(function (res) {
                    if (res && res.success && res.url) {
                        var normalizedImageUrl = normalizeIssueImageSrc(res.url);
                        var safeAlt = escapeAttr(altText || 'Issue Screenshot');
                        var imgHtml = '<img src="' + escapeAttr(normalizedImageUrl) + '" alt="' + safeAlt + '" style="max-width:100%; height:auto; cursor:pointer;" class="editable-issue-image" />';
                        if (issueData.imageUpload.savedRange) {
                            $el.summernote('restoreRange');
                            issueData.imageUpload.savedRange.pasteHTML(imgHtml);
                            issueData.imageUpload.savedRange = null;
                        } else {
                            $el.summernote('insertNode', $('<img>').attr({ src: normalizedImageUrl, alt: altText || 'Issue Screenshot', style: 'max-width:100%; height:auto; cursor:pointer;', class: 'editable-issue-image' })[0]);
                        }
                        bootstrap.Modal.getInstance(jQuery('#imageAltTextModal')[0]).hide();
                    } else if (res && res.error) { issueNotify(res.error, 'danger'); }
                }).catch(function () { issueNotify('Image upload failed', 'danger'); })
                .finally(function () {
                    issueData.imageUpload.pendingFile = null;
                    issueData.imageUpload.pendingEditor = null;
                });
        }
    }

    function initSummernote(el) {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        var $el = jQuery(el);
        if ($el.data('summernote')) return;

        if (!document.getElementById('issue-codeblock-btn-style')) {
            var st = document.createElement('style');
            st.id = 'issue-codeblock-btn-style';
            st.textContent = '.note-btn-codeblock.active{background-color:#0d6efd!important;color:#fff!important;border-color:#0a58ca!important;}';
            document.head.appendChild(st);
        }
        if (!document.getElementById('issue-modal-fullscreen-style')) {
            var fs = document.createElement('style');
            fs.id = 'issue-modal-fullscreen-style';
            fs.textContent = ''
                + '.issue-editor-modal-full .modal-content{height:100%;display:flex;flex-direction:column;}'
                + '.issue-editor-modal-full .modal-body{flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;}'
                + '.issue-editor-modal-full .issue-summernote-full-host{flex:1;min-height:0;display:flex;flex-direction:column;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor{flex:1;min-height:0;display:flex;flex-direction:column;height:100%;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor.note-frame{height:100%;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor .note-toolbar{flex:0 0 auto;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor .note-editing-area{flex:1;min-height:0;overflow:hidden;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor .note-editable{height:100%!important;min-height:0!important;max-height:none!important;overflow-y:auto!important;overflow-x:auto!important;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor .note-statusbar{display:none!important;}'
                + '.issue-editor-modal-full .issue-summernote-full-editor .note-resizebar{display:none!important;}'
                + '.issue-editor-temp-hidden{display:none!important;}'
                + '.note-btn-modalfullscreen.active{background-color:#0d6efd!important;color:#fff!important;border-color:#0a58ca!important;}';
            document.head.appendChild(fs);
        }
        var fullscreenState = { hiddenEls: [], activeEditor: null, resizeHandler: null };

        function setCodeBlockButtonState() {
            var $btn = $el.next('.note-editor').find('.note-btn-codeblock');
            if (!$btn.length) return;
            var inCode = false;
            try {
                var range = $el.summernote('createRange');
                var sc = range && range.sc ? range.sc : null;
                var node = sc && sc.nodeType === 3 ? sc.parentNode : sc;
                var editable = $el.next('.note-editor').find('.note-editable')[0];
                inCode = !!(node && editable && jQuery(node).closest('code', editable).length);
            } catch (e) { inCode = false; }
            $btn
                .toggleClass('active', inCode)
                .attr('aria-pressed', inCode ? 'true' : 'false')
                .attr('title', 'Code Block')
                .attr('aria-label', 'Code Block');
        }

        function isEditorModalFullscreenActive() {
            return $el.data('issueModalFullscreen') === true;
        }

        function syncModalFullscreenButtonState() {
            var $btn = $el.next('.note-editor').find('.note-btn-modalfullscreen');
            if (!$btn.length) return;
            var active = isEditorModalFullscreenActive();
            $btn
                .toggleClass('active', active)
                .attr('aria-pressed', active ? 'true' : 'false')
                .attr('title', active ? 'Exit Fullscreen' : 'Fullscreen')
                .attr('aria-label', active ? 'Exit Fullscreen' : 'Fullscreen');
            var $icon = $btn.find('i').first();
            if ($icon.length) {
                $icon.toggleClass('fa-expand', !active);
                $icon.toggleClass('fa-compress', active);
            }
        }

        function setEditorModalFullscreen(enabled) {
            var $modal = $el.closest('.modal');
            var $editor = $el.next('.note-editor');
            if (!$modal.length || !$editor.length) return;
            var $dialog = $modal.find('.modal-dialog').first();
            var $content = $modal.find('.modal-content').first();
            var $body = $content.find('.modal-body').first();
            var $editingArea = $editor.find('.note-editing-area').first();
            var $editable = $editor.find('.note-editable').first();
            var $toolbar = $editor.find('.note-toolbar').first();

            function markTempHidden($nodes) {
                $nodes.each(function () {
                    if (!this) return;
                    var $n = jQuery(this);
                    if ($n.hasClass('issue-editor-temp-hidden')) return;
                    $n.addClass('issue-editor-temp-hidden').attr('aria-hidden', 'true');
                    fullscreenState.hiddenEls.push(this);
                });
            }

            function clearTempHidden() {
                (fullscreenState.hiddenEls || []).forEach(function (node) {
                    var $n = jQuery(node);
                    $n.removeClass('issue-editor-temp-hidden').removeAttr('aria-hidden');
                });
                fullscreenState.hiddenEls = [];
            }

            function hideNonEditorContent() {
                clearTempHidden();
                if (!$content.length || !$body.length) return;
                markTempHidden($content.children('.modal-header'));
                markTempHidden($content.children('.modal-footer'));
                $body.children().each(function () {
                    var keep = (this === $editor[0]) || jQuery.contains(this, $editor[0]);
                    if (!keep) markTempHidden(jQuery(this));
                });
                var node = $editor[0];
                while (node && node !== $body[0]) {
                    var parent = node.parentNode;
                    if (!parent) break;
                    jQuery(parent).children().each(function () {
                        if (this !== node) markTempHidden(jQuery(this));
                    });
                    node = parent;
                }
            }

            function applyFullscreenEditorSizing() {
                if (!$body.length || !$editingArea.length || !$editable.length) return;
                var bodyHeight = 0;
                try {
                    bodyHeight = Math.floor(($body.get(0).getBoundingClientRect() || {}).height || 0);
                } catch (e) { bodyHeight = 0; }
                if (!bodyHeight) bodyHeight = Math.floor(window.innerHeight || 0);
                var toolbarHeight = Math.floor($toolbar.length ? ($toolbar.outerHeight(true) || 0) : 0);
                var editorHeight = Math.max(220, bodyHeight - toolbarHeight - 12);
                $editingArea.css({
                    height: editorHeight + 'px',
                    maxHeight: editorHeight + 'px',
                    minHeight: '0',
                    overflow: 'hidden'
                });
                $editable.css({
                    height: editorHeight + 'px',
                    maxHeight: editorHeight + 'px',
                    minHeight: '0',
                    overflowY: 'auto',
                    overflowX: 'auto'
                });
            }

            function clearFullscreenEditorSizing() {
                if ($editingArea.length) {
                    $editingArea.css({ height: '', maxHeight: '', minHeight: '', overflow: '' });
                }
                if ($editable.length) {
                    $editable.css({ height: '', maxHeight: '', minHeight: '', overflowY: '', overflowX: '' });
                }
            }

            if (enabled) {
                $el.data('issueModalFullscreen', true);
                fullscreenState.activeEditor = $editor.get(0);
                $modal.addClass('issue-editor-modal-full');
                $dialog.addClass('modal-fullscreen');
                $editor.parent().addClass('issue-summernote-full-host');
                $editor.addClass('issue-summernote-full-editor');
                hideNonEditorContent();
                setTimeout(applyFullscreenEditorSizing, 0);
                setTimeout(applyFullscreenEditorSizing, 60);
                if (!fullscreenState.resizeHandler) {
                    fullscreenState.resizeHandler = function () {
                        if ($el.data('issueModalFullscreen') === true) {
                            applyFullscreenEditorSizing();
                        }
                    };
                    window.addEventListener('resize', fullscreenState.resizeHandler);
                }
            } else {
                $el.data('issueModalFullscreen', false);
                fullscreenState.activeEditor = null;
                $editor.removeClass('issue-summernote-full-editor');
                $editor.parent().removeClass('issue-summernote-full-host');
                clearFullscreenEditorSizing();
                clearTempHidden();
                if (fullscreenState.resizeHandler) {
                    window.removeEventListener('resize', fullscreenState.resizeHandler);
                    fullscreenState.resizeHandler = null;
                }
                if (!$modal.find('.issue-summernote-full-editor').length) {
                    $modal.removeClass('issue-editor-modal-full');
                    $dialog.removeClass('modal-fullscreen');
                }
            }
            syncModalFullscreenButtonState();
            setTimeout(function () { try { $el.summernote('focus'); } catch (e) { } }, 0);
        }

        function toggleEditorModalFullscreen() {
            setEditorModalFullscreen(!isEditorModalFullscreenActive());
        }

        var modalEl = $el.closest('.modal').get(0);
        if (modalEl && !modalEl._issueModalFullscreenEscBound) {
            modalEl.addEventListener('keydown', function (e) {
                var key = e.key || '';
                var isEsc = key === 'Escape' || key === 'Esc' || e.keyCode === 27;
                if (!isEsc) return;
                var hasActiveFullscreen = false;
                try {
                    hasActiveFullscreen = !!modalEl.querySelector('.issue-summernote-full-editor');
                } catch (err) { hasActiveFullscreen = false; }
                if (!hasActiveFullscreen) return;
                e.preventDefault();
                if (typeof e.stopPropagation === 'function') e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                setEditorModalFullscreen(false);
            }, true);
            modalEl._issueModalFullscreenEscBound = true;
        }
        if (modalEl && !modalEl._issueModalFullscreenBound) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                var $m = jQuery(modalEl);
                $m.find('.issue-editor-temp-hidden').removeClass('issue-editor-temp-hidden').removeAttr('aria-hidden');
                $m.removeClass('issue-editor-modal-full');
                $m.find('.modal-dialog').removeClass('modal-fullscreen');
                $m.find('.issue-summernote-full-editor').removeClass('issue-summernote-full-editor');
                $m.find('.issue-summernote-full-host').removeClass('issue-summernote-full-host');
                $m.find('.note-editing-area').css({ height: '', maxHeight: '', minHeight: '', overflow: '' });
                $m.find('.note-editable').css({ height: '', maxHeight: '', minHeight: '', overflowY: '', overflowX: '' });
                $m.find('.issue-summernote').each(function () {
                    jQuery(this).data('issueModalFullscreen', false);
                    var $btn = jQuery(this).next('.note-editor').find('.note-btn-modalfullscreen');
                    if ($btn.length) {
                        $btn.removeClass('active').attr('aria-pressed', 'false').attr('title', 'Fullscreen').attr('aria-label', 'Fullscreen');
                        var $icon = $btn.find('i').first();
                        if ($icon.length) {
                            $icon.addClass('fa-expand').removeClass('fa-compress');
                        }
                    }
                });
                fullscreenState.hiddenEls = [];
                fullscreenState.activeEditor = null;
                if (fullscreenState.resizeHandler) {
                    window.removeEventListener('resize', fullscreenState.resizeHandler);
                    fullscreenState.resizeHandler = null;
                }
            });
            modalEl._issueModalFullscreenBound = true;
        }

        function enableToolbarKeyboardA11y() {
            var $toolbar = $el.next('.note-editor').find('.note-toolbar').first();
            if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;
            function getItems() {
                return $toolbar.find('.note-btn-group button').filter(function () {
                    var $b = jQuery(this);
                    if ($b.is(':hidden')) return false;
                    if ($b.prop('disabled')) return false;
                    if ($b.closest('.dropdown-menu').length) return false;
                    if ($b.attr('aria-hidden') === 'true') return false;
                    return true;
                });
            }

            function setActiveIndex(idx) {
                var $items = getItems();
                if (!$items.length) return;
                var next = Math.max(0, Math.min(idx, $items.length - 1));
                $items.attr('tabindex', '-1');
                $items.eq(next).attr('tabindex', '0');
                $toolbar.data('kbdIndex', next);
            }

            function ensureOneTabStop() {
                var $items = getItems();
                if (!$items.length) return;
                if (!$items.filter('[tabindex="0"]').length) {
                    $items.attr('tabindex', '-1');
                    $items.eq(0).attr('tabindex', '0');
                }
            }

            $toolbar.attr('role', 'toolbar');
            if (!$toolbar.attr('aria-label')) {
                $toolbar.attr('aria-label', 'Editor toolbar');
            }

            setActiveIndex(0);

            $toolbar.on('focusin', 'button', function () {
                var $items = getItems();
                var idx = $items.index(this);
                if (idx >= 0) setActiveIndex(idx);
            });
            $toolbar.on('click', 'button', function () {
                var $items = getItems();
                var idx = $items.index(this);
                if (idx >= 0) setActiveIndex(idx);
            });

            function handleToolbarArrowNav(e) {
                var key = e.key || e.originalEvent && e.originalEvent.key;
                if (key !== 'ArrowRight' && key !== 'ArrowLeft' && key !== 'Home' && key !== 'End') return;

                var $items = getItems();
                if (!$items.length) return;
                var activeEl = document.activeElement;
                var idx = $items.index(activeEl);
                if (idx < 0 && activeEl && activeEl.closest) {
                    var parentBtn = activeEl.closest('button');
                    if (parentBtn) idx = $items.index(parentBtn);
                }
                if (idx < 0) {
                    var savedIdx = parseInt($toolbar.data('kbdIndex'), 10);
                    if (!isNaN(savedIdx) && savedIdx >= 0 && savedIdx < $items.length) idx = savedIdx;
                }
                if (idx < 0) idx = $items.index($items.filter('[tabindex="0"]').first());
                if (idx < 0) idx = 0;

                e.preventDefault();
                if (e.stopPropagation) e.stopPropagation();
                if (key === 'Home') idx = 0;
                else if (key === 'End') idx = $items.length - 1;
                else if (key === 'ArrowRight') idx = (idx + 1) % $items.length;
                else if (key === 'ArrowLeft') idx = (idx - 1 + $items.length) % $items.length;

                setActiveIndex(idx);
                var $target = $items.eq(idx);
                $target.focus();
                if (document.activeElement !== $target.get(0)) {
                    setTimeout(function () { $target.focus(); }, 0);
                }
            }

            $toolbar.on('keydown', handleToolbarArrowNav);
            if (!$toolbar.data('kbdA11yNativeKeyBound')) {
                $toolbar.get(0).addEventListener('keydown', handleToolbarArrowNav, true);
                $toolbar.data('kbdA11yNativeKeyBound', true);
            }

            // Keep one button tabbable even if Summernote resets tabindex to -1.
            var observer = new MutationObserver(function () { ensureOneTabStop(); });
            observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
            $toolbar.data('kbdA11yObserver', observer);
            var fixTimer = setInterval(ensureOneTabStop, 1000);
            $toolbar.data('kbdA11yTimer', fixTimer);
            ensureOneTabStop();

            $toolbar.data('kbdA11yBound', true);
        }

        function toggleCodeBlock(context) {
            context.invoke('editor.focus');
            context.invoke('editor.saveRange');
            var range = context.invoke('editor.createRange');
            var sc = range && range.sc ? range.sc : null;
            var node = sc && sc.nodeType === 3 ? sc.parentNode : sc;
            var editable = context.layoutInfo && context.layoutInfo.editable ? context.layoutInfo.editable[0] : ($el.next('.note-editor').find('.note-editable')[0] || null);
            var inCode = false;
            if (node && editable) {
                inCode = jQuery(node).closest('code', editable).length > 0;
            }
            if (inCode) {
                var $code = jQuery(node).closest('code', editable).first();
                if ($code.length) {
                    var txt = document.createTextNode($code.text());
                    var codeNode = $code.get(0);
                    codeNode.parentNode.replaceChild(txt, codeNode);
                    try {
                        var sel = window.getSelection();
                        if (sel) {
                            var r = document.createRange();
                            r.setStart(txt, txt.textContent.length);
                            r.collapse(true);
                            sel.removeAllRanges();
                            sel.addRange(r);
                        }
                    } catch (e) { }
                }
            } else {
                var sel = window.getSelection();
                if (sel && sel.rangeCount) {
                    var nativeRange = sel.getRangeAt(0);
                    var selectedText = nativeRange.toString();
                    var code = document.createElement('code');
                    if (selectedText) {
                        code.textContent = selectedText;
                        nativeRange.deleteContents();
                        nativeRange.insertNode(code);
                        var after = document.createRange();
                        after.setStartAfter(code);
                        after.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(after);
                    } else {
                        code.textContent = '\u200B';
                        nativeRange.insertNode(code);
                        var inside = document.createRange();
                        inside.setStart(code.firstChild, 1);
                        inside.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(inside);
                    }
                }
            }
            setTimeout(setCodeBlockButtonState, 0);
        }

        var isClientCommentEditor = !!($el && $el.attr && $el.attr('id') === 'finalIssueCommentEditor' && userRole === 'client');
        var editorHeight = 180;
        if ($el && $el.attr && $el.attr('id') === 'reviewIssueDetails') {
            editorHeight = 320;
        } else if (isClientCommentEditor) {
            editorHeight = 80;
        }
        var toolbarConfig = isClientCommentEditor
            ? [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
                ['fontname', ['fontname']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'video']],
                ['view', ['codeview', 'help']]
            ]
            : [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
                ['fontname', ['fontname']],
                ['fontsize', ['fontsize']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph', 'height']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'video', 'hr', 'codeBlockToggle']],
                ['view', ['modalFullscreen', 'help']]
            ];
        $el.summernote({
            height: editorHeight,
            placeholder: isClientCommentEditor ? 'Type your comment...' : undefined,
            toolbar: toolbarConfig,
            styleTags: ['p', { title: 'Blockquote', tag: 'blockquote', className: 'blockquote', value: 'blockquote' }, 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            popover: { image: [['image', ['resizeFull', 'resizeHalf', 'resizeQuarter', 'resizeNone']], ['float', ['floatLeft', 'floatRight', 'floatNone']], ['remove', ['removeMedia']], ['custom', ['imageAltText']]] },
            buttons: {
                codeBlockToggle: function (context) {
                    var ui = jQuery.summernote.ui;
                    var $btn = ui.button({
                        contents: '&lt;/&gt;',
                        className: 'note-btn-codeblock',
                        click: function () { toggleCodeBlock(context); }
                    }).render();
                    try {
                        $btn.attr('title', 'Code Block');
                        $btn.attr('aria-label', 'Code Block');
                    } catch (e) { }
                    return $btn;
                },
                modalFullscreen: function () {
                    var ui = jQuery.summernote.ui;
                    var $btn = ui.button({
                        contents: '<i class="fas fa-expand"></i>',
                        className: 'note-btn-modalfullscreen',
                        tooltip: 'Fullscreen',
                        click: function () {
                            toggleEditorModalFullscreen();
                        }
                    }).render();
                    if (!isClientCommentEditor) {
                        syncModalFullscreenButtonState();
                    }
                    return $btn;
                },
                imageAltText: function (context) {
                    var ui = jQuery.summernote.ui;
                    return ui.button({
                        contents: '<i class="fas fa-tag"/> <span style="font-size:0.75em;">Alt Text</span>',
                        tooltip: 'Edit alt text',
                        click: function () {
                            var $img = jQuery(context.invoke('restoreTarget'));
                            if ($img && $img.length) {
                                issueData.imageUpload.isEditing = true;
                                issueData.imageUpload.editingImg = $img;
                                showImageAltModal($img.attr('alt') || '');
                            }
                        }
                    }).render();
                }
            },
            callbacks: {
                onInit: function () {
                    setTimeout(setCodeBlockButtonState, 0);
                    if (!isClientCommentEditor) {
                        setTimeout(syncModalFullscreenButtonState, 0);
                    }
                    setTimeout(enableToolbarKeyboardA11y, 0);
                    setTimeout(enableToolbarKeyboardA11y, 200);
                    if (isClientCommentEditor) updateFinalIssueCommentCharCount();
                },
                onFocus: function () { setCodeBlockButtonState(); },
                onKeyup: function () { setCodeBlockButtonState(); },
                onMouseup: function () { setCodeBlockButtonState(); },
                onChange: function () {
                    setCodeBlockButtonState();
                    if (isClientCommentEditor) updateFinalIssueCommentCharCount();
                },
                onImageUpload: function (files) {
                    // Skip if onPaste already handled this (suppress window active)
                    if (issueData.imageUpload.suppressUntil && Date.now() < issueData.imageUpload.suppressUntil) return;
                    var list = files || [];
                    for (var i = 0; i < list.length; i++) {
                        uploadIssueImage(list[i], $el);
                    }
                },
                onPaste: function (e) {
                    var files = [];
                    if (window.PMSSummernoteImage && typeof window.PMSSummernoteImage.extractClipboardImageFiles === 'function') {
                        files = window.PMSSummernoteImage.extractClipboardImageFiles(e) || [];
                    } else {
                        var clipboard = e.originalEvent && e.originalEvent.clipboardData;
                        if (clipboard && clipboard.items) {
                            for (var i = 0; i < clipboard.items.length; i++) {
                                var item = clipboard.items[i];
                                if (item.type && item.type.indexOf('image') === 0) {
                                    files.push(item.getAsFile()); break;
                                }
                            }
                        }
                    }
                    if (files.length) {
                        e.preventDefault();
                        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                        var oe = e.originalEvent;
                        if (oe && typeof oe.stopImmediatePropagation === 'function') oe.stopImmediatePropagation();
                        // Suppress onImageUpload for next 2s so it doesn't double-upload
                        issueData.imageUpload.suppressUntil = Date.now() + 2000;
                        uploadIssueImage(files[0], $el);
                    }
                }
            }
        });

        // Auto-formatting for bullet lists: Convert "- " or "* " at line start to bullet list
        $el.on('summernote.keyup', function(we, e) {
            // Only process Space key (32)
            if (e.keyCode !== 32) return;

            try {
                var range = $el.summernote('createRange');
                if (!range || !range.sc) return;

                var node = range.sc.nodeType === 3 ? range.sc.parentNode : range.sc;
                var currentP = node.closest ? node.closest('p') : null;
                if (!currentP) return;

                var text = currentP.textContent || '';
                // Match "- " or "* " at the very start (the space was just typed)
                if (!/^[\-\*]\s$/.test(text)) return;

                // Clear the trigger characters
                currentP.textContent = '';

                // Use Summernote native command — preserves undo history
                $el.summernote('saveRange');
                document.execCommand('insertUnorderedList');
                $el.summernote('restoreRange');
            } catch (err) {
                // Silently ignore
            }
        });

        // Auto-formatting for backticks: Convert `text` to <code>text</code>
        $el.on('summernote.keyup', function(we, e) {
            // More robust key detection
            var key = e.key || (e.originalEvent && e.originalEvent.key);
            if (key !== '`') return;

            try {
                var range = $el.summernote('createRange');
                if (!range || !range.sc || range.sc.nodeType !== 3) return;

                var text = range.sc.textContent;
                var pos = range.so;
                
                // Check if we are already inside a code tag
                if (jQuery(range.sc.parentNode).closest('code').length) return;

                // Look for an opening backtick before the one just typed (at pos-1)
                var lastBacktick = text.lastIndexOf('`', pos - 2);
                if (lastBacktick === -1) return;

                // Extract content between backticks
                var codeText = text.substring(lastBacktick + 1, pos - 1);
                if (codeText.length === 0) return;

                // Create elements
                var parent = range.sc.parentNode;
                var beforeText = text.substring(0, lastBacktick);
                var afterText = text.substring(pos);
                
                var codeEl = document.createElement('code');
                codeEl.textContent = codeText;
                
                // We need to use Summernote's insertNode or direct DOM manipulation that stays in context
                // Directly replacing text content is safest for simple text nodes
                var prefixNode = document.createTextNode(beforeText);
                var suffixNode = document.createTextNode('\u200B' + afterText); // Zero-width space helps positioning

                parent.insertBefore(prefixNode, range.sc);
                parent.insertBefore(codeEl, range.sc);
                parent.insertBefore(suffixNode, range.sc);
                parent.removeChild(range.sc);

                // Position cursor at start of suffixNode (after the zero-width space)
                var newRange = document.createRange();
                newRange.setStart(suffixNode, 1);
                newRange.collapse(true);
                var sel = window.getSelection();
                if (sel) {
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                }

                // Notify Summernote of change
                $el.summernote('triggerEvent', 'change');
            } catch (err) { }
        });

        // Exit bullet list on Enter in empty <li>, and handle Backspace for code blocks
        $el.on('summernote.keydown', function(we, e) {
            // Handle Enter (13)
            if (e.keyCode === 13) {
                try {
                    var range = $el.summernote('createRange');
                    if (!range || !range.sc) return;

                    var node = range.sc.nodeType === 3 ? range.sc.parentNode : range.sc;
                    var currentLi = node.closest ? node.closest('li') : null;
                    if (!currentLi || currentLi.textContent.trim() !== '') return;

                    var list = currentLi.closest('ul, ol');
                    if (!list) return;

                    e.preventDefault();
                    e.stopPropagation();

                    // Remove the empty li
                    var listParent = list.parentNode;
                    currentLi.parentNode.removeChild(currentLi);
                    if (list.children.length === 0 && list.parentNode) {
                        list.parentNode.removeChild(list);
                    }

                    // Insert a new <p> after the list and place cursor there
                    var newP = document.createElement('p');
                    newP.appendChild(document.createElement('br'));
                    if (listParent) {
                        var listRef = list.parentNode ? list : null;
                        // Insert after the list's current position
                        var nextSibling = list.nextSibling;
                        if (nextSibling) {
                            listParent.insertBefore(newP, nextSibling);
                        } else {
                            listParent.appendChild(newP);
                        }
                    }

                    // Place cursor inside the new paragraph
                    var sel = window.getSelection();
                    if (sel) {
                        var r = document.createRange();
                        r.setStart(newP, 0);
                        r.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(r);
                    }
                    $el.summernote('triggerEvent', 'change');
                } catch (err) { }
                return;
            }

            // Handle Backspace (8)
            if (e.keyCode === 8) {
                try {
                    var range = $el.summernote('createRange');
                    if (!range || !range.sc) return;

                    var codeNode = null;
                    var textNodeToCleanup = null;

                    // Support identifying code node from different cursor positions
                    if (range.sc.nodeType === 3) {
                        // Case A: Inside the <code> tag at the end
                        if (range.sc.parentNode && range.sc.parentNode.nodeName === 'CODE') {
                            if (range.so === range.sc.textContent.length) {
                                codeNode = range.sc.parentNode;
                                // No text node cleanup needed if we are inside
                            }
                        } 
                        // Case B: Immediately after the <code> tag in a text node
                        else {
                            if (range.so === 1 && range.sc.textContent.charAt(0) === '\u200B') {
                                codeNode = range.sc.previousSibling;
                                textNodeToCleanup = range.sc;
                            } else if (range.so === 0) {
                                codeNode = range.sc.previousSibling;
                            }
                        }
                    }

                    if (codeNode && codeNode.nodeName === 'CODE') {
                        e.preventDefault();
                        var codeText = codeNode.textContent;
                        var parent = codeNode.parentNode;
                        
                        // Replace <code>text</code> with `text (plain text)
                        var newNode = document.createTextNode('`' + codeText);
                        parent.insertBefore(newNode, codeNode);
                        parent.removeChild(codeNode);
                        
                        // Cleanup trailing markers if we were in the suffix node
                        if (textNodeToCleanup) {
                            if (textNodeToCleanup.textContent === '\u200B' || textNodeToCleanup.textContent === '') {
                                if (textNodeToCleanup.parentNode === parent) parent.removeChild(textNodeToCleanup);
                            } else if (textNodeToCleanup.textContent.startsWith('\u200B')) {
                                textNodeToCleanup.textContent = textNodeToCleanup.textContent.substring(1);
                            }
                        }

                        // Position cursor at end of the restored plain text
                        var newRange = document.createRange();
                        newRange.setStart(newNode, newNode.length);
                        newRange.collapse(true);
                        var sel = window.getSelection();
                        if (sel) {
                            sel.removeAllRanges();
                            sel.addRange(newRange);
                        }
                        $el.summernote('triggerEvent', 'change');
                        return;
                    }
                } catch (err) { }
            }
        });
    }

    function initEditors() {
        document.querySelectorAll('.issue-summernote').forEach(function (el) { initSummernote(el); });
        if (!initEditors._imgClickBound) {
            initEditors._imgClickBound = true;
            jQuery(document).on('click', '.note-editable img', function (e) {
                var $img = jQuery(this);
                var $editor = $img.closest('.note-editor');
                var $source = $editor.prev('textarea');
                var isFinalIssueDetailsEditor = $source.length && $source.attr('id') === 'finalIssueDetails';

                e.preventDefault();
                issueData.imageUpload.isEditing = true;
                issueData.imageUpload.editingImg = $img;
                showImageAltModal($img.attr('alt') || '');
            });
        }

        // Initialize @ mention for comment editor
        initMentionSupport();
    }

    function initMentionSupport() {
        var $editor = jQuery('#finalIssueCommentEditor');
        if (!$editor.length) return;

        var mentionDropdown = null;
        var mentionIndex = -1;
        var mentionList = [];
        var mentionRange = null;

        // Create mention dropdown
        var dropdownHtml = '<div id="issueMentionDropdown" class="dropdown-menu" style="display:none; position:fixed; z-index:99999; max-height:200px; overflow-y:auto;"></div>';
        if (!document.getElementById('issueMentionDropdown')) {
            jQuery('body').append(dropdownHtml);
        }
        mentionDropdown = document.getElementById('issueMentionDropdown');

        function getMentionContext($editable) {
            if (!$editable || !$editable.length) return null;
            var editableEl = $editable[0];
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            var range = sel.getRangeAt(0);
            if (!editableEl.contains(range.endContainer)) return null;

            var preRange = range.cloneRange();
            preRange.selectNodeContents(editableEl);
            preRange.setEnd(range.endContainer, range.endOffset);
            var textBeforeCaret = String(preRange.toString() || '');

            var lastAtPos = textBeforeCaret.lastIndexOf('@');
            if (lastAtPos < 0) return null;

            // Mention should only be active while caret is in the same token as '@'
            var query = textBeforeCaret.substring(lastAtPos + 1);
            if (/\s/.test(query)) return null;
            if (query.length > 50) return null;
            if (!/^[A-Za-z0-9._-]*$/.test(query)) return null;

            return {
                query: query
            };
        }

        // Handle keydown in Summernote (for preventing default behavior)
        $editor.off('summernote.keydown.issueMention');
        $editor.on('summernote.keydown.issueMention', function (we, e) {
            // Check if dropdown is visible
            var dropdownVisible = mentionDropdown && mentionDropdown.style.display === 'block';

            if (dropdownVisible) {
                if (e.keyCode === 40) { // Arrow down
                    e.preventDefault();
                    e.stopPropagation();
                    moveMentionHighlight(1);
                    return false;
                } else if (e.keyCode === 38) { // Arrow up
                    e.preventDefault();
                    e.stopPropagation();
                    moveMentionHighlight(-1);
                    return false;
                } else if (e.keyCode === 13) { // Enter
                    e.preventDefault();
                    e.stopPropagation();
                    var active = mentionDropdown.querySelector('.mention-item.active');
                    if (active) {
                        insertMention(active.getAttribute('data-username'));
                    }
                    return false;
                } else if (e.keyCode === 9) { // Tab
                    e.preventDefault();
                    e.stopPropagation();
                    var active = mentionDropdown.querySelector('.mention-item.active');
                    if (active) {
                        insertMention(active.getAttribute('data-username'));
                    }
                    return false;
                } else if (e.keyCode === 27) { // Escape
                    e.preventDefault();
                    e.stopPropagation(); // CRITICAL: Stop event from reaching modal
                    e.stopImmediatePropagation(); // Also stop other handlers
                    hideMentionDropdown();
                    return false;
                }
            }
        });

        // Handle keyup in Summernote (for showing/hiding dropdown)
        $editor.off('summernote.keyup.issueMention');
        $editor.on('summernote.keyup.issueMention', function (we, e) {
            // Don't process if we just handled navigation keys
            if (mentionDropdown && mentionDropdown.style.display === 'block') {
                if ([9, 13, 27, 38, 40].indexOf(e.keyCode) !== -1) {
                    return;
                }
            }

            // Don't show dropdown if it was just closed by Escape
            if (e.keyCode === 27) {
                return;
            }

            // Get the editable div content
            var $editable = $editor.next('.note-editor').find('.note-editable');
            if (!$editable.length) return;

            var mentionContext = getMentionContext($editable);
            if (!mentionContext) {
                hideMentionDropdown();
                return;
            }

            showMentionDropdown(mentionContext.query, $editable);
        });

        function showMentionDropdown(query, $editable) {
            var users = ProjectConfig.projectUsers || [];
            var q = String(query || '').toLowerCase();
            mentionList = users.filter(function (u) {
                var fullName = String(u.full_name || '').toLowerCase();
                var username = String(u.username || '').toLowerCase();
                return fullName.indexOf(q) >= 0 || username.indexOf(q) >= 0;
            }).sort(function (a, b) {
                var aUser = String(a.username || '').toLowerCase();
                var bUser = String(b.username || '').toLowerCase();
                var aIsAdmin = aUser === 'admin' || aUser === 'admin' || aUser === 'superadmin' || String(a.role || '').toLowerCase().indexOf('admin') >= 0;
                var bIsAdmin = bUser === 'admin' || bUser === 'admin' || bUser === 'superadmin' || String(b.role || '').toLowerCase().indexOf('admin') >= 0;
                if (aIsAdmin !== bIsAdmin) return aIsAdmin ? -1 : 1;
                return String(a.full_name || '').localeCompare(String(b.full_name || ''));
            });

            if (mentionList.length === 0) {
                hideMentionDropdown();
                return;
            }

            var html = mentionList.map(function (u, idx) {
                var username = String(u.username || '').trim() || String(u.full_name || '').replace(/\s+/g, '');
                return '<a href="#" class="dropdown-item mention-item' + (idx === 0 ? ' active' : '') + '" data-username="' +
                    escapeHtml(username) + '" data-id="' + u.id + '">' +
                    escapeHtml(u.full_name) + '</a>';
            }).join('');

            mentionDropdown.innerHTML = html;
            mentionDropdown.style.display = 'block';
            mentionIndex = 0;
            try { mentionRange = $editor.summernote('createRange'); } catch (e) { mentionRange = null; }

            // Position dropdown near @ symbol using cursor position
            if ($editable && $editable.length) {
                try {
                    // Get cursor position from Summernote
                    var range = $editor.summernote('createRange');
                    if (range && range.getClientRects) {
                        var rects = range.getClientRects();
                        if (rects && rects.length > 0) {
                            var rect = rects[0];
                            // Position dropdown just below cursor
                            mentionDropdown.style.left = rect.left + 'px';
                            mentionDropdown.style.top = (rect.bottom + 5) + 'px';
                        } else {
                            // Fallback to editor position
                            var offset = $editable.offset();
                            mentionDropdown.style.left = offset.left + 'px';
                            mentionDropdown.style.top = (offset.top + 30) + 'px';
                        }
                    } else {
                        // Fallback to editor position
                        var offset = $editable.offset();
                        mentionDropdown.style.left = offset.left + 'px';
                        mentionDropdown.style.top = (offset.top + 30) + 'px';
                    }
                } catch (e) {
                    // Fallback to editor position
                    var offset = $editable.offset();
                    mentionDropdown.style.left = offset.left + 'px';
                    mentionDropdown.style.top = (offset.top + 30) + 'px';
                }
            }

            // Click handler
            jQuery(mentionDropdown).find('.mention-item').off('click').on('click', function (e) {
                e.preventDefault();
                insertMention(jQuery(this).attr('data-username'));
            });
        }

        function hideMentionDropdown() {
            if (mentionDropdown) {
                mentionDropdown.style.display = 'none';
                mentionDropdown.innerHTML = '';
            }
            mentionList = [];
            mentionIndex = -1;
            mentionRange = null;
        }

        function moveMentionHighlight(direction) {
            var items = mentionDropdown.querySelectorAll('.mention-item');
            if (items.length === 0) return;

            items[mentionIndex].classList.remove('active');
            mentionIndex += direction;
            if (mentionIndex < 0) mentionIndex = items.length - 1;
            if (mentionIndex >= items.length) mentionIndex = 0;
            items[mentionIndex].classList.add('active');
            items[mentionIndex].scrollIntoView({ block: 'nearest' });
        }

        function insertMention(username) {
            var $editable = $editor.next('.note-editor').find('.note-editable');
            if (!$editable.length) {
                hideMentionDropdown();
                return;
            }
            try {
                if (mentionRange && typeof mentionRange.select === 'function') {
                    mentionRange.select();
                } else {
                    $editor.summernote('editor.restoreRange');
                }
            } catch (e) { }
            var editableEl = $editable[0];
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                hideMentionDropdown();
                return;
            }
            var range = sel.getRangeAt(0);
            if (!editableEl.contains(range.startContainer)) {
                hideMentionDropdown();
                return;
            }

            function findMentionStart(node, offset) {
                function previousPosition(n, o) {
                    if (!n) return null;
                    if (n.nodeType === 3 && o > 0) {
                        return { node: n, offset: o - 1, ch: n.textContent.charAt(o - 1) };
                    }
                    var cur = n;
                    while (cur) {
                        if (cur.previousSibling) {
                            cur = cur.previousSibling;
                            while (cur && cur.lastChild) cur = cur.lastChild;
                            if (cur && cur.nodeType === 3) {
                                var len = cur.textContent.length;
                                if (len > 0) return { node: cur, offset: len - 1, ch: cur.textContent.charAt(len - 1) };
                            }
                        } else {
                            cur = cur.parentNode;
                            if (!cur || cur === editableEl) break;
                        }
                    }
                    return null;
                }

                var pos = { node: node, offset: offset };
                while (true) {
                    var prev = previousPosition(pos.node, pos.offset);
                    if (!prev) return null;
                    if (prev.ch === '@') return { node: prev.node, offset: prev.offset };
                    if (/\s/.test(prev.ch || '')) return null;
                    pos = { node: prev.node, offset: prev.offset };
                }
            }

            var startPos = findMentionStart(range.startContainer, range.startOffset);
            if (!startPos) {
                hideMentionDropdown();
                return;
            }

            var deleteRange = document.createRange();
            deleteRange.setStart(startPos.node, startPos.offset);
            deleteRange.setEnd(range.startContainer, range.startOffset);
            deleteRange.deleteContents();

            var caretRange = sel.rangeCount ? sel.getRangeAt(0) : null;
            var needsLeadingSpace = false;
            if (caretRange) {
                var probe = caretRange.cloneRange();
                probe.selectNodeContents(editableEl);
                probe.setEnd(caretRange.startContainer, caretRange.startOffset);
                var textBefore = String(probe.toString() || '');
                needsLeadingSpace = !!(textBefore && !/\s$/.test(textBefore));
            }
            var mentionText = (needsLeadingSpace ? ' ' : '') + '@' + String(username || '') + ' ';

            // Insert mention text - use direct node insertion to preserve spaces
            var node = document.createTextNode(mentionText);
            var insertRange = sel.rangeCount ? sel.getRangeAt(0) : null;
            if (insertRange) {
                insertRange.insertNode(node);
                insertRange.setStartAfter(node);
                insertRange.collapse(true);
                sel.removeAllRanges();
                sel.addRange(insertRange);
            }

            mentionRange = null;
            hideMentionDropdown();
        }

        // Hide dropdown when clicking outside mention UI.
        jQuery(document).off('mousedown.issueMention').on('mousedown.issueMention', function (e) {
            var $target = jQuery(e.target);
            var clickedInDropdown = $target.closest('#issueMentionDropdown').length > 0;
            var clickedInEditor = $target.closest('#finalIssueModal .note-editor').length > 0;
            if (!clickedInDropdown && !clickedInEditor) {
                hideMentionDropdown();
            }
        });

        // Ensure stale dropdown is cleared when issue modal closes.
        jQuery('#finalIssueModal').off('hidden.bs.modal.issueMention').on('hidden.bs.modal.issueMention', function () {
            hideMentionDropdown();
        });
    }

    function showFinalIssuesTab() {
        var tabBtn = document.getElementById('final-issues-tab');
        if (!tabBtn) return;
        try { new bootstrap.Tab(tabBtn).show(); } catch (e) { }
    }

    function setSelectedPage(btn) {
        if (!btn) return;
        var pid = btn.getAttribute('data-page-id');
        if (!pid || pid === '0') return;
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (b) { b.classList.remove('table-active'); });
        btn.classList.add('table-active');
        issueData.selectedPageId = pid;
        ensurePageStore(issueData.pages, issueData.selectedPageId);

        var name = btn.getAttribute('data-page-name') || 'Page';
        var tester = btn.getAttribute('data-page-tester') || '-';
        var env = btn.getAttribute('data-page-env') || '-';
        var issues = btn.getAttribute('data-page-issues') || '0';
        var nameEl = document.getElementById('issueSelectedPageName');
        var metaEl = document.getElementById('issueSelectedPageMeta');
        if (nameEl) nameEl.textContent = name;
        if (metaEl) metaEl.textContent = 'Tester: ' + tester + ' | Env: ' + env + ' | Issues: ' + issues;
        showFinalIssuesTab();

        showIssuesDetail();
        updateEditingState();
        populatePageUrls(issueData.selectedPageId);
        renderAll();
        loadFinalIssues(issueData.selectedPageId);
    }

    function attachPageClickListeners() {
        var pageRows = document.querySelectorAll('#issuesPageList .issues-page-row');
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (btn) {
            // Remove existing listeners to avoid duplicates
            if (btn._pageClickHandler) {
                btn.removeEventListener('click', btn._pageClickHandler);
            }
            // Create and attach new handler
            btn._pageClickHandler = function (e) {
                // Don't trigger if clicking on the collapse button
                if (e.target.closest('button[data-bs-toggle="collapse"]')) return;
                var pageId = btn.getAttribute('data-page-id');
                if (pageId && pageId !== '0') {
                    setSelectedPage(btn);
                    return;
                }
                var uniqueId = btn.getAttribute('data-unique-id');
                if (!uniqueId) return;
                setSelectedUniquePage(btn, uniqueId);
            };
            btn.addEventListener('click', btn._pageClickHandler);
        });

        // Delegated click handler as a fallback for dynamic row updates
        try {
            var container = document.getElementById('issuesPageList');
            if (container && !container._issuesDelegateAttached) {
                container._issuesDelegateAttached = true;
                try { } catch (e) { }
                container.addEventListener('click', function (e) {
                    var row = e.target.closest && e.target.closest('.issues-page-row');
                    if (!row) return;
                    // Ignore clicks on collapse toggle buttons
                    if (e.target.closest && e.target.closest('button[data-bs-toggle="collapse"]')) return;
                    var pageId = row.getAttribute('data-page-id');
                    if (pageId && pageId !== '0') { setSelectedPage(row); return; }
                    var uniqueId = row.getAttribute('data-unique-id');
                    if (!uniqueId) return;
                    setSelectedUniquePage(row, uniqueId);
                });
            }
        } catch (e) { /* ignore */ }
    }

    function populatePageUrls(pageId) {
        var card = document.getElementById('pageUrlsCard');
        var content = document.getElementById('pageUrlsListContent');
        var count = document.getElementById('urlsCount');

        if (!pageId || !card || !content) return;

        // Get URLs for this page from groupedUrls array
        var urls = groupedUrls.filter(function (u) {
            return u.mapped_page_id == pageId;
        });

        if (urls.length === 0) {
            card.style.display = 'none';
            return;
        }

        // Show card and populate URLs
        card.style.display = 'block';
        count.textContent = urls.length;

        content.innerHTML = urls.map(function (u) {
            return '<li class="mb-1"><i class="fas fa-angle-right text-muted me-2"></i>' +
                escapeHtml(u.url) + '</li>';
        }).join('');
    }

    function showIssuesDetail() {
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) pagesCol.classList.add('d-none');
        if (detailCol) {
            detailCol.classList.remove('d-none');
            detailCol.classList.remove('col-lg-8');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.remove('d-none');
    }

    function showIssuesPages() {
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) {
            pagesCol.classList.remove('d-none');
            pagesCol.classList.remove('col-lg-4');
            pagesCol.classList.add('col-lg-12');
        }
        if (detailCol) {
            detailCol.classList.add('d-none');
            detailCol.classList.remove('col-lg-12');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.add('d-none');
    }

    function getProjectUserNameById(userId) {
        var uid = String(userId || '');
        if (uid && !/^\d+$/.test(uid)) return uid;
        if (!uid || !window.ProjectConfig || !Array.isArray(ProjectConfig.projectUsers)) return 'User ' + uid;
        var found = ProjectConfig.projectUsers.find(function (u) { return String(u.id) === uid; });
        return found ? String(found.full_name || ('User ' + uid)) : ('User ' + uid);
    }

    function getReporterQaStatusMapFromUi() {
        var out = {};
        var rows = document.querySelectorAll('#reporterQaStatusRows .reporter-qa-status-select');
        rows.forEach(function (el) {
            var rid = String(el.getAttribute('data-reporter-id') || '').trim();
            if (!rid) return;
            var selected = [];
            if (window.jQuery) {
                selected = jQuery(el).val() || [];
            } else if (el.multiple && el.selectedOptions) {
                selected = Array.from(el.selectedOptions).map(function (opt) { return opt.value; });
            } else {
                selected = [el.value || ''];
            }
            var statusKeys = selected.map(function (v) {
                return String(v || '').trim();
            }).filter(function (v) { return v !== ''; });
            if (statusKeys.length) out[rid] = statusKeys;
        });
        return out;
    }

    function normalizeReporterQaStatusMapForReporters(mapObj, reportersVal) {
        var out = {};
        var allowed = {};
        (Array.isArray(reportersVal) ? reportersVal : []).forEach(function (rid) {
            var key = String(rid || '').trim();
            if (key) allowed[key] = true;
        });
        if (!mapObj || typeof mapObj !== 'object') return out;
        Object.keys(mapObj).forEach(function (rid) {
            var key = String(rid || '').trim();
            var statusKeys = [];
            if (Array.isArray(mapObj[rid])) {
                statusKeys = mapObj[rid];
            } else if (typeof mapObj[rid] === 'string') {
                statusKeys = [mapObj[rid]];
            } else if (mapObj[rid] != null) {
                statusKeys = [String(mapObj[rid])];
            }
            statusKeys = statusKeys.map(function (v) { return String(v || '').trim(); }).filter(Boolean);
            if (!key || !statusKeys.length) return;
            if (!allowed[key]) return;
            out[key] = statusKeys;
        });
        return out;
    }

    function renderReporterQaStatusEditor(reportersVal, seedMap) {
        var container = document.getElementById('reporterQaStatusContainer');
        var rowsHost = document.getElementById('reporterQaStatusRows');
        if (!container || !rowsHost) return;

        var reporterIds = (Array.isArray(reportersVal) ? reportersVal : []).map(function (v) { return String(v || '').trim(); }).filter(Boolean);
        var map = normalizeReporterQaStatusMapForReporters(seedMap || {}, reporterIds);

        if (!reporterIds.length) {
            container.classList.add('d-none');
            rowsHost.innerHTML = '';
            return;
        }

        var qaStatuses = (window.ProjectConfig && Array.isArray(ProjectConfig.qaStatuses)) ? ProjectConfig.qaStatuses : [];
        var optionsHtml = qaStatuses.map(function (qs) {
            return '<option value="' + escapeAttr(qs.status_key || '') + '">' + escapeHtml(qs.status_label || qs.status_key || '') + '</option>';
        }).join('');

        rowsHost.innerHTML = reporterIds.map(function (rid) {
            var reporterName = getProjectUserNameById(rid);
            var selectedVals = Array.isArray(map[rid]) ? map[rid] : [];
            return '<div class="row g-2 align-items-center mb-2">' +
                '<div class="col-5"><span class="fw-semibold">' + escapeHtml(reporterName) + '</span></div>' +
                '<div class="col-7">' +
                '<select class="form-select form-select-sm issue-select2 reporter-qa-status-select" data-reporter-id="' + escapeAttr(rid) + '" multiple>' + optionsHtml + '</select>' +
                '</div>' +
                '</div>';
        }).join('');

        rowsHost.querySelectorAll('.reporter-qa-status-select').forEach(function (sel) {
            var rid = String(sel.getAttribute('data-reporter-id') || '').trim();
            var selectedVals = Array.isArray(map[rid]) ? map[rid] : [];
            if (window.jQuery) {
                jQuery(sel).val(selectedVals);
            } else {
                Array.from(sel.options).forEach(function (opt) {
                    opt.selected = selectedVals.indexOf(String(opt.value || '')) !== -1;
                });
            }
            sel.disabled = !canUpdateIssueQaStatus;
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $modal = jQuery('#finalIssueModal');
            jQuery(rowsHost).find('.reporter-qa-status-select').each(function () {
                var $el = jQuery(this);
                var rid = String($el.attr('data-reporter-id') || '').trim();
                var selectedVals = Array.isArray(map[rid]) ? map[rid] : [];
                try { if ($el.data('select2')) $el.select2('destroy'); } catch (e) { }
                $el.select2({
                    width: '100%',
                    dropdownParent: $modal.length ? $modal : null
                });
                // Ensure any stored values that aren't in the options list are added
                // (handles case where qa_status_master options don't match stored keys)
                selectedVals.forEach(function (v) {
                    if (!v) return;
                    var exists = $el.find('option[value="' + String(v).replace(/"/g, '\\"') + '"]').length > 0;
                    if (!exists) {
                        $el.append(new Option(v, v, false, false));
                    }
                });
                // Set values AFTER select2 init and trigger change so select2 reflects them
                $el.val(selectedVals).trigger('change');
            });
        }
        container.classList.remove('d-none');
    }

    function refreshReporterQaStatusEditor(seedMap) {
        var reportersVal = jQuery('#finalIssueReporters').val() || [];
        var currentMap = getReporterQaStatusMapFromUi();
        var baseMap = (seedMap && typeof seedMap === 'object') ? seedMap : currentMap;
        renderReporterQaStatusEditor(reportersVal, baseMap);
    }

    function getQaBadgeInfo(statusKey) {
        var key = String(statusKey || '').toLowerCase().trim();
        if (!key) return null;
        var label = key;
        var badgeColor = 'secondary';
        if (ProjectConfig.qaStatuses) {
            var found = ProjectConfig.qaStatuses.find(function (s) {
                return String(s.status_key || '').toLowerCase() === key;
            });
            if (found) {
                label = found.status_label || found.status_key || key;
                badgeColor = found.badge_color || 'secondary';
            } else {
                label = key.split('_').map(function (word) {
                    return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
                }).join(' ');
            }
        }

        var colorMap = {
            'primary': '#0d6efd',
            'secondary': '#6c757d',
            'success': '#198754',
            'danger': '#dc3545',
            'warning': '#ffc107',
            'info': '#0dcaf0',
            'light': '#f8f9fa',
            'dark': '#212529'
        };
        var bgColor = (badgeColor && String(badgeColor).startsWith('#')) ? badgeColor : (colorMap[badgeColor] || colorMap.secondary);
        var hex = bgColor.replace('#', '');
        var r = parseInt(hex.substr(0, 2), 16);
        var g = parseInt(hex.substr(2, 2), 16);
        var b = parseInt(hex.substr(4, 2), 16);
        var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        var textColor = luminance > 0.5 ? '#000000' : '#ffffff';
        return { key: key, label: label, bgColor: bgColor, textColor: textColor };
    }

    function normalizeQaStatusList(raw) {
        var list = [];
        if (Array.isArray(raw)) {
            list = raw;
        } else if (typeof raw === 'string') {
            list = raw.split(',').map(function (v) { return String(v || '').trim(); });
        } else if (raw != null) {
            list = [String(raw)];
        }
        return list.map(function (v) { return String(v || '').trim(); }).filter(Boolean);
    }

    function setFinalQaStatusValue(raw) {
        var values = normalizeQaStatusList(raw);
        var $qa = jQuery('#finalIssueQaStatus');
        if (!$qa.length) return;
        values.forEach(function (v) {
            var exists = false;
            $qa.find('option').each(function () {
                if (String(this.value || '') === v) exists = true;
            });
            if (!exists) {
                $qa.append(new Option(v, v, false, false));
            }
        });
        $qa.val(values).trigger('change');
    }

    function getIssueReporterIds(issue) {
        var ids = [];
        if (issue && Array.isArray(issue.reporters) && issue.reporters.length > 0) {
            ids = issue.reporters.map(function (rid) { return String(rid || '').trim(); }).filter(Boolean);
        }
        if (!ids.length && issue && issue.reporter_name) {
            ids = [String(issue.reporter_name)];
        }
        return ids;
    }

    function getReporterQaStatusHtml(issue) {
        var reporterQaMap = (issue && issue.reporter_qa_status_map && typeof issue.reporter_qa_status_map === 'object')
            ? issue.reporter_qa_status_map
            : {};
        var reporterIds = getIssueReporterIds(issue);
        if (!reporterIds.length) return '<span class="text-muted">N/A</span>';

        var rows = [];
        reporterIds.forEach(function (rid) {
            var reporterName = getProjectUserNameById(rid);
            var statusKeys = [];
            if (reporterQaMap && Object.prototype.hasOwnProperty.call(reporterQaMap, rid)) {
                statusKeys = reporterQaMap[rid];
            } else if (reporterQaMap && Object.prototype.hasOwnProperty.call(reporterQaMap, parseInt(rid, 10))) {
                statusKeys = reporterQaMap[parseInt(rid, 10)];
            }
            if (!Array.isArray(statusKeys)) {
                statusKeys = statusKeys ? [statusKeys] : [];
            }
            statusKeys = statusKeys.map(function (v) { return String(v || '').trim(); }).filter(Boolean);
            if (!statusKeys.length) {
                rows.push('<div class="mb-1"><span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span><span class="text-muted">N/A</span></div>');
                return;
            }
            var qaBadges = statusKeys.map(function (statusKey) {
                var badgeInfo = getQaBadgeInfo(statusKey);
                if (!badgeInfo) return '';
                return '<span class="qa-status-badge me-1" style="background-color: ' + badgeInfo.bgColor + ' !important; color: ' + badgeInfo.textColor + ' !important;">' + escapeHtml(badgeInfo.label) + '</span>';
            }).filter(Boolean).join('');
            if (!qaBadges) {
                rows.push('<div class="mb-1"><span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span><span class="text-muted">N/A</span></div>');
                return;
            }
            rows.push(
                '<div class="mb-1">' +
                '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>' +
                qaBadges +
                '</div>'
            );
        });
        if (rows.length) return rows.join('');

        var fallbackStatuses = normalizeQaStatusList(issue && issue.qa_status ? issue.qa_status : []);
        if (!fallbackStatuses.length) return '<span class="text-muted">N/A</span>';
        var fallbackBadges = fallbackStatuses.map(function (statusKey) {
            var badgeInfo = getQaBadgeInfo(statusKey);
            if (!badgeInfo) return '';
            return '<span class="qa-status-badge me-1" style="background-color: ' + badgeInfo.bgColor + ' !important; color: ' + badgeInfo.textColor + ' !important;">' + escapeHtml(badgeInfo.label) + '</span>';
        }).filter(Boolean).join('');
        return fallbackBadges || '<span class="text-muted">N/A</span>';
    }

    function captureFormState() {
        var titleInput = document.getElementById('customIssueTitle');
        var titleVal = titleInput ? titleInput.value.trim() : '';
        var detailsVal = jQuery('#finalIssueDetails').summernote('code') || '';
        var statusVal = document.getElementById('finalIssueStatus').value;
        var qaStatusVal = jQuery('#finalIssueQaStatus').val() || [];
        var pagesVal = jQuery('#finalIssuePages').val() || [];
        var groupedUrlsVal = normalizeGroupedUrlsSelection(jQuery('#finalIssueGroupedUrls').val() || []);
        var reportersVal = jQuery('#finalIssueReporters').val() || [];
        var reporterQaStatusMapVal = getReporterQaStatusMapFromUi();
        var commonTitleVal = document.getElementById('finalIssueCommonTitle').value;
        var assigneeVal = jQuery('#finalIssueAssignee').val() || [];
        var clientReadyEl = document.getElementById('finalIssueClientReady');
        var clientReadyVal = clientReadyEl ? (clientReadyEl.checked ? '1' : '0') : '0';
        var dynamicFields = {};
        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                var el = document.getElementById('finalIssueField_' + f.field_key);
                if (el) dynamicFields[f.field_key] = jQuery(el).val();
            });
        }
        return {
            title: titleVal, details: detailsVal, status: statusVal, qa_status: qaStatusVal,
            pages: pagesVal, grouped_urls: groupedUrlsVal, reporters: reportersVal,
            reporter_qa_status_map: reporterQaStatusMapVal,
            common_title: commonTitleVal, assignee_ids: assigneeVal,
            client_ready: clientReadyVal, dynamic_fields: dynamicFields
        };
    }

    function hasFormChanges() {
        if (!issueData.initialFormState) return false;
        var current = captureFormState();
        var initial = issueData.initialFormState;
        // Normalize Summernote output — empty editor can produce '<p><br></p>' vs ''
        function normalizeHtml(h) {
            var s = String(h || '').trim();
            return (s === '<p><br></p>' || s === '<p></p>' || s === '<br>') ? '' : s;
        }
        if (normalizeHtml(current.details) !== normalizeHtml(initial.details)) return true;
        // Compare everything else except details
        var a = Object.assign({}, current, { details: '' });
        var b = Object.assign({}, initial, { details: '' });
        return JSON.stringify(a) !== JSON.stringify(b);
    }

    async function saveDraft() {
        if (!projectId) return;
        var formState = captureFormState();
        var plainText = String(formState.details || '').replace(/<[^>]*>/g, '').trim();
        if (!formState.title && !plainText) return;
        var $indicator = jQuery('#draftSaveIndicator');
        if ($indicator.length) { $indicator.text('Saving...').show(); }
        try {
            var fd = new FormData();
            fd.append('action', 'save'); fd.append('project_id', projectId);
            fd.append('issue_params', JSON.stringify(formState));
            await fetch(issueDraftsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
            if ($indicator.length) {
                $indicator.text('Draft saved');
                clearTimeout(issueData._draftIndicatorTimer);
                issueData._draftIndicatorTimer = setTimeout(function () { $indicator.fadeOut(400); }, 2500);
            }
        } catch (e) {
            if ($indicator.length) { $indicator.text('').hide(); }
        }
    }

    async function loadDraft() {
        if (!projectId) return null;
        try {
            var res = await fetch(issueDraftsApi + '?action=get&project_id=' + encodeURIComponent(projectId), { credentials: 'same-origin' });
            var json = await res.json();
            if (json && json.success && json.draft) return { data: json.draft, updated_at: json.updated_at };
        } catch (e) { }
        return null;
    }

    async function deleteDraft() {
        if (!projectId) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId);
            await fetch(issueDraftsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
    }

    function startDraftAutosave() {
        if (issueData.draftTimer) clearInterval(issueData.draftTimer);
        issueData.draftTimer = setInterval(function () { if (hasFormChanges()) saveDraft(); }, 8000);
    }

    function stopDraftAutosave() {
        if (issueData.draftTimer) { clearInterval(issueData.draftTimer); issueData.draftTimer = null; }
    }

    function getReviewDraftStorageKey() {
        return 'pms_review_issue_draft_' + String(projectId || '0') + '_' + String(issueData.selectedPageId || '0');
    }

    function captureReviewFormState() {
        var details = '';
        if (window.jQuery && jQuery.fn.summernote) details = jQuery('#reviewIssueDetails').summernote('code') || '';
        else details = (document.getElementById('reviewIssueDetails') || {}).value || '';
        return {
            title: (document.getElementById('reviewIssueTitle') || {}).value || '',
            instance: (document.getElementById('reviewIssueInstance') || {}).value || '',
            source_urls: (document.getElementById('reviewIssueSourceUrls') || {}).value || '',
            wcag: (document.getElementById('reviewIssueWcag') || {}).value || '',
            severity: (document.getElementById('reviewIssueSeverity') || {}).value || 'medium',
            details: details
        };
    }

    function hasReviewFormChanges() {
        if (!reviewIssueInitialFormState) return false;
        return JSON.stringify(captureReviewFormState()) !== JSON.stringify(reviewIssueInitialFormState);
    }

    function applyReviewFormState(state) {
        if (!state || typeof state !== 'object') return;
        if (document.getElementById('reviewIssueTitle')) document.getElementById('reviewIssueTitle').value = state.title || '';
        if (document.getElementById('reviewIssueInstance')) document.getElementById('reviewIssueInstance').value = state.instance || '';
        if (document.getElementById('reviewIssueSourceUrls')) document.getElementById('reviewIssueSourceUrls').value = state.source_urls || '';
        if (document.getElementById('reviewIssueWcag')) document.getElementById('reviewIssueWcag').value = state.wcag || '';
        if (document.getElementById('reviewIssueSeverity')) document.getElementById('reviewIssueSeverity').value = state.severity || 'medium';
        if (window.jQuery && jQuery.fn.summernote) jQuery('#reviewIssueDetails').summernote('code', state.details || '');
    }

    function loadReviewDraftLocal() {
        try {
            var raw = localStorage.getItem(getReviewDraftStorageKey());
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            return parsed && parsed.form ? parsed : null;
        } catch (e) { return null; }
    }

    function saveReviewDraftLocal() {
        try {
            var form = captureReviewFormState();
            var plainText = String(form.details || '').replace(/<[^>]*>/g, '').trim();
            if (!String(form.title || '').trim() && !plainText) return false;
            localStorage.setItem(getReviewDraftStorageKey(), JSON.stringify({
                updated_at: new Date().toISOString(),
                form: form
            }));
            return true;
        } catch (e) { return false; }
    }

    function clearReviewDraftLocal() {
        try { localStorage.removeItem(getReviewDraftStorageKey()); } catch (e) { }
    }

    function hideEditors() {
        ['finalIssueModal', 'commonIssueModal', 'urlSelectionModal', 'draftConfirmModal'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;

            if (id === 'finalIssueModal' && userRole === 'client') {
                requestClientFinalIssueOverlayClose();
                return;
            }
            
            // Ensure focus is moved out of the modal before hiding to avoid ARIA warnings
            if (el.contains(document.activeElement)) {
                document.activeElement.blur();
            }

            var inst = bootstrap.Modal.getInstance(el);
            if (inst) {
                el.addEventListener('hidden.bs.modal', function onHidden() {
                    el.removeEventListener('hidden.bs.modal', onHidden);
                    cleanupModalOverlayState();
                }, { once: true });
                inst.hide();
            }
            
            // Cleanup aria-hidden immediately if element exists
            el.setAttribute('aria-hidden', 'true');
        });
    }

    function toggleFinalIssueFields(enable) {
        var modal = document.getElementById('finalIssueModal');
        if (!modal) return;

        var runFieldCheck = function(source) {
            
            // If we are supposed to be enabled AND user is NOT a client, FORCE IT
            var forceOn = (enable && userRole !== 'client');
            
            modal.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'hidden') return;
                if (el.closest('#finalIssueComments')) return;
                
                // Fields that must ALWAYS be enabled for every Edit mode
                if (el.id === 'finalIssueCommentType' || el.classList.contains('issue-dynamic-field')) {
                    el.disabled = false;
                    return;
                }
                
                // If forceOn is true, we must be enabled
                if (forceOn) {
                    el.disabled = false;
                } else {
                    el.disabled = !enable;
                }
            });

            if (window.jQuery && jQuery.fn.summernote) {
                if (forceOn) {
                    jQuery('#finalIssueDetails').summernote('enable');
                } else {
                    jQuery('#finalIssueDetails').summernote(enable ? 'enable' : 'disable');
                }
                jQuery('#finalIssueCommentEditor').summernote('enable');
            }

            if (window.jQuery && jQuery.fn.select2) {
                if (forceOn) {
                    jQuery('.issue-select2, .issue-select2-tags').prop('disabled', false);
                } else {
                    jQuery('.issue-select2, .issue-select2-tags').not('.issue-dynamic-field').prop('disabled', !enable);
                    jQuery('.issue-dynamic-field').prop('disabled', false);
                }
                
                jQuery('#finalIssueMetadataContainer select, #finalIssueMetadataContainer input').prop('disabled', !forceOn && !enable);
                if (enable) {
                    jQuery('.issue-dynamic-field').filter(':visible').trigger('change.select2');
                }
            }

            applyIssueQaPermissionState();
            applyClientIssueEditingState(enable);
            applyTesterRegressionReadonlyState();
        };

        // Pass 1: Immediate
        runFieldCheck('initial');
        
        // Only run safety loop if we are trying to ENABLE or if it's already "enabled"
        if (enable) {
            // Pass 2: 500ms
            setTimeout(function() { runFieldCheck('500ms-safety'); }, 500);
            // Pass 3: 2000ms
            setTimeout(function() { runFieldCheck('2s-safety'); }, 2000);
            // Pass 4: 5000ms (Total brute force for slow servers)
            setTimeout(function() { runFieldCheck('5s-brute-force'); }, 5000);
        }
    }

    function openFinalViewer(issue) {
        if (!issue) return;
        updateClientIssueSidebarHeader(issue);
        document.getElementById('finalIssueEditId').value = issue.id;
        var expectedUpdatedAtEl = document.getElementById('finalIssueExpectedUpdatedAt');
        if (expectedUpdatedAtEl) expectedUpdatedAtEl.value = issue.updated_at || '';
        startIssuePresenceTracking(issue.id);

        // Inject/update custom title field with issue title
        if (window.injectIssueTitleField) {
            window.injectIssueTitleField(issue.title || '');
        }

        document.getElementById('finalIssueStatus').value = issue.status || 'Open';
        syncResolutionStatusOptions();
        setFinalQaStatusValue(issue.qa_status || []);
        // Set pages - use val() method
        var pagesToSet = issue.pages || [issueData.selectedPageId];
        var $pagesEl = jQuery('#finalIssuePages');
        $pagesEl.val(pagesToSet).trigger('change');
        jQuery('#finalIssueGroupedUrls').val(issue.grouped_urls || []).trigger('change');
        if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('code', issue.description || '');
        else document.getElementById('finalIssueDetails').value = issue.description || '';
        document.getElementById('finalIssueCommonTitle').value = issue.common_title || '';

        Object.keys(issue).forEach(function (k) {
            if (k.startsWith('meta:')) {
                var fieldKey = k.substring(5);
                var el = document.getElementById('finalIssueField_' + fieldKey);
                if (el) {
                    var val = issue[k];
                    if (val && typeof val === 'string' && val.startsWith('[')) { try { val = JSON.parse(val); } catch (e) { } }
                    jQuery(el).val(val).trigger('change');
                }
            } else if (k === 'reporters') { jQuery('#finalIssueReporters').val(issue.reporters || []).trigger('change'); }
        });
        refreshReporterQaStatusEditor(issue.reporter_qa_status_map || {});

        renderIssueComments(issue.id);
        loadIssueComments(issue.id);

        var modalTitle = document.getElementById('finalIssueModalLabel');
        if (modalTitle) modalTitle.textContent = 'View Issue';
        document.getElementById('finalIssueSaveBtn').classList.add('d-none');

        var footer = document.querySelector('#finalIssueModal .modal-footer');
        var editBtn = document.getElementById('finalIssueEditBtn');
        if (!editBtn && footer) {
            editBtn = document.createElement('button');
            editBtn.type = 'button'; editBtn.id = 'finalIssueEditBtn'; editBtn.className = 'btn btn-primary';
            editBtn.textContent = 'Edit Issue';
            editBtn.addEventListener('click', function () {
                toggleFinalIssueFields(true);
                this.classList.add('d-none');
                document.getElementById('finalIssueSaveBtn').classList.remove('d-none');
                if (modalTitle) modalTitle.textContent = 'Edit Issue';
            });
            var saveBtn = document.getElementById('finalIssueSaveBtn');
            if (saveBtn) footer.insertBefore(editBtn, saveBtn); else footer.appendChild(editBtn);
        }
        if (editBtn) editBtn.classList.remove('d-none');
        toggleFinalIssueFields(false);
        applyIssueQaPermissionState();
        var chatDiv = document.getElementById('finalIssueComments');
        if (chatDiv) {
            chatDiv.querySelectorAll('input, select, textarea, button').forEach(function (el) { el.disabled = false; el.classList.remove('disabled'); });
            if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueCommentEditor').summernote('enable');
        }
        showFinalIssueOverlay(document.getElementById('finalIssueModal'));
        document.getElementById('finalIssueModal').addEventListener('shown.bs.modal', function onViewerShown() {
            document.getElementById('finalIssueModal').removeEventListener('shown.bs.modal', onViewerShown);
            var activeTab = document.querySelector('#finalIssueModal .nav-link.active');
            if (activeTab) activeTab.dispatchEvent(new Event('shown.bs.tab', { bubbles: true }));
        });
    }

    async function openFinalEditor(issue, options) {
        var opts = options || {};
        var modalEl = document.getElementById('finalIssueModal');
        if (!modalEl) return;

        if (userRole === 'client' && issue && issue.id != null) {
            var currentIssueIdEl = document.getElementById('finalIssueEditId');
            var currentIssueId = currentIssueIdEl ? String(currentIssueIdEl.value || '') : '';
            var requestedIssueId = String(issue.id);
            var isClientSidebarOpen = modalEl.classList.contains('show') || modalEl.classList.contains('is-open');

            if (isClientSidebarOpen && currentIssueId === requestedIssueId) {
                requestClientFinalIssueOverlayClose();
                return;
            }
        }

        clearIssueConflictNotice();
        
        toggleFinalIssueFields(true);
        document.getElementById('finalEditorTitle').textContent = issue ? 'Edit Final Issue' : 'New Final Issue';
        updateClientIssueSidebarHeader(issue);
        document.getElementById('finalIssueEditId').value = issue ? issue.id : '';
        var expectedUpdatedAtEl = document.getElementById('finalIssueExpectedUpdatedAt');
        if (expectedUpdatedAtEl) expectedUpdatedAtEl.value = issue && issue.updated_at ? issue.updated_at : '';
        modalEl.dataset.expectedHistoryId = issue && issue.latest_history_id != null ? String(issue.latest_history_id) : '0';
        startIssuePresenceTracking(issue && issue.id ? issue.id : null);

        // Ensure save button is visible and edit button is hidden
        var saveBtn = document.getElementById('finalIssueSaveBtn');
        if (saveBtn) saveBtn.classList.remove('d-none');
        var editBtn = document.getElementById('finalIssueEditBtn');
        if (editBtn) editBtn.classList.add('d-none');

        var draftData = null;
        // Don't await draft load — show modal immediately, apply draft after modal opens
        if (!issue) {
            loadDraft().then(function(draft) {
                var existingTitleInput = document.getElementById('customIssueTitle');
                if (draft && draft.data && (!existingTitleInput || !existingTitleInput.value)) {
                    draftData = draft.data;
                    issueData.isDraftRestored = true;
                    if (window.showToast) showToast('Draft restored from ' + new Date(draft.updated_at).toLocaleString(), 'info');
                    // Apply draft values to already-open modal
                    if (window.injectIssueTitleField) window.injectIssueTitleField(draft.data.title || '');
                    jQuery('#finalIssueDetails').summernote('code', draft.data.details || '');
                    updateFinalIssueCommentCharCount();
                }
            }).catch(function() {});
        }

        // Inject title field with value (won't re-inject if exists, just updates value)
        var titleVal = issue ? (issue.title || '') : '';
        if (window.injectIssueTitleField) {
            window.injectIssueTitleField(titleVal);
        }

        // Verify field was created/updated
        setTimeout(function () {
            var titleInput = document.getElementById('customIssueTitle');
            var applyBtn = document.getElementById('applyPresetBtn');
        }, 100);

        var detailsVal = issue ? (issue.details || '') : '';
        jQuery('#finalIssueDetails').summernote('code', detailsVal);

        // Note: Issue status options are already populated by PHP in the modal HTML
        // We only need to set the selected value

        // Set the selected value - convert status name to ID if needed
        var statusValue = '1'; // Default to Open
        if (issue && issue.status_id) {
            // Ensure it's a string for proper comparison with option values
            statusValue = String(issue.status_id);
        } else if (issue && issue.status && ProjectConfig.issueStatuses) {
            // Try to find the ID by name or label
            var statusOption = ProjectConfig.issueStatuses.find(function (s) {
                var label = s.status_label || s.name || '';
                return label && issue.status && label.toLowerCase() === issue.status.toLowerCase();
            });
            if (statusOption) statusValue = String(statusOption.id);
        } else if (draftData && draftData.status && ProjectConfig.issueStatuses) {
            var statusOption = ProjectConfig.issueStatuses.find(function (s) {
                var label = s.status_label || s.name || '';
                return label && draftData.status && label.toLowerCase() === draftData.status.toLowerCase();
            });
            if (statusOption) statusValue = String(statusOption.id);
        }
        document.getElementById('finalIssueStatus').value = statusValue;
        syncClientIssueStatusOptions();
        syncResolutionStatusOptions();

        // Store values to set after modal is shown
        var reportersValue = issue ? (issue.reporters || []) : (draftData ? draftData.reporters : []);
        var qaStatusValue = issue ? (issue.qa_status || []) : (draftData ? (draftData.qa_status || []) : []);
        var reporterQaStatusMapValue = issue
            ? (function() {
                var raw = issue.reporter_qa_status_map;
                // PHP now always returns a plain object; handle legacy array-of-strings just in case
                if (Array.isArray(raw)) {
                    for (var i = 0; i < raw.length; i++) {
                        try {
                            var p = (typeof raw[i] === 'string') ? JSON.parse(raw[i]) : raw[i];
                            if (p && typeof p === 'object' && !Array.isArray(p)) return p;
                        } catch(e) {}
                    }
                    return {};
                }
                if (typeof raw === 'string') { try { return JSON.parse(raw); } catch(e) { return {}; } }
                return (raw && typeof raw === 'object') ? raw : {};
            })()
            : (draftData ? (draftData.reporter_qa_status_map || {}) : {});
        var pageIds = normalizeProjectPageIds((issue && issue.pages) ? issue.pages : ((draftData && draftData.pages) ? draftData.pages : [issueData.selectedPageId]));
        if (!pageIds.length) {
            var fallbackSelectedPageId = resolveValidSelectedPageId(issueData.selectedPageId, []);
            pageIds = fallbackSelectedPageId ? [fallbackSelectedPageId] : [];
            if (fallbackSelectedPageId) {
                issueData.selectedPageId = fallbackSelectedPageId;
            }
        }

        // Set common title earlier so toggleCommonTitle (triggered by pages change) can see it
        var commonTitleVal = issue ? (issue.common_title || '') : (draftData ? draftData.common_title : '');
        // For common issues being edited from the common issues page, if common_title is missing, use title
        if (issue && !commonTitleVal && issue.issue_key && (issueData.common || []).some(function(ci) { return ci.id === issue.id; })) {
            commonTitleVal = issue.title || '';
        }
        var commonTitleInput = document.getElementById('finalIssueCommonTitle');
        if (commonTitleInput) {
            commonTitleInput.value = commonTitleVal;
        }

        // Set pages immediately (this usually works)
        jQuery('#finalIssuePages').val(pageIds).trigger('change');

        // Wait for modal to be fully shown before setting Select2 values.
        var modalAlreadyOpen = modalEl.classList.contains('show');
        var skipShow = !!opts.skipShow || modalAlreadyOpen;
        var modal = getFinalIssueModalInstance(modalEl);

        function applySelectValuesNow() {
            // Use microtask + rAF to ensure DOM is ready before applying Select2 values,
            // avoiding the previous nested setTimeout(50) / setTimeout(60) / setTimeout(150) race conditions.
            Promise.resolve().then(function () {
                applyIssueQaPermissionState();
                applyClientIssueEditingState(true);
                setFinalQaStatusValue(qaStatusValue);
                // Temporarily suppress the reporter change handler to avoid
                // refreshReporterQaStatusEditor() firing without seedMap (which blanks the field)
                jQuery('#finalIssueReporters').off('change.issueReporterQa');
                jQuery('#finalIssueReporters').val(reportersValue).trigger('change');
                // Re-attach the handler after setting value
                jQuery('#finalIssueReporters').on('change.issueReporterQa', function () {
                    refreshReporterQaStatusEditor();
                });
                // Render reporter QA status directly with known reporters + seedMap
                var reportersForQa = (Array.isArray(reportersValue) ? reportersValue : []).map(String).filter(Boolean);
                renderReporterQaStatusEditor(reportersForQa, reporterQaStatusMapValue);
                // Set QA Names (multi-select assignees)
                var assigneeIds = [];
                if (issue) {
                    if (Array.isArray(issue.assignee_ids) && issue.assignee_ids.length) {
                        assigneeIds = issue.assignee_ids.map(String);
                    } else if (issue.assignee_id) {
                        assigneeIds = [String(issue.assignee_id)];
                    }
                }
                jQuery('#finalIssueAssignee').val(assigneeIds).trigger('change');
                // Capture initial state AFTER all Select2 values are set — prevents false "unsaved changes" on edit open
                if (issue) {
                    // rAF ensures Select2 internal state has settled before we snapshot
                    requestAnimationFrame(function () {
                        issueData.initialFormState = captureFormState();
                    });
                }
            });
        }

        if (skipShow) {
            applySelectValuesNow();
        } else {
            // Remove any existing event listeners to avoid duplicates
            modalEl.removeEventListener('shown.bs.modal', modalEl._select2SetterHandler);
            // Create new handler
            modalEl._select2SetterHandler = function () {
                applySelectValuesNow();
            };
            // Attach the handler
            modalEl.addEventListener('shown.bs.modal', modalEl._select2SetterHandler, { once: true });
        }

        // Apply metadata field values once fields are loaded (no polling loop needed)
        function applyMetadataFieldValues() {
            issueMetadataFields.forEach(function (f) {
                var elId = 'finalIssueField_' + f.field_key;
                var val = null;

                if (issue) {
                    if (issue[f.field_key] !== undefined) {
                        val = issue[f.field_key];
                    } else if (issue.metadata && issue.metadata[f.field_key] !== undefined) {
                        val = issue.metadata[f.field_key];
                    }
                } else if (draftData && draftData.dynamic_fields && draftData.dynamic_fields[f.field_key] !== undefined) {
                    val = draftData.dynamic_fields[f.field_key];
                } else if (!issue && (f.field_key === 'severity' || f.field_key === 'priority')) {
                    val = 'medium';
                }

                var $el = jQuery('#' + elId);
                if (!$el.length) return;
                if ($el.prop('multiple') && val && !Array.isArray(val)) val = [val];
                if (!$el.prop('multiple') && Array.isArray(val)) val = val[0] || null;
                if ($el.hasClass('issue-select2-tags') && val) {
                    var valArr = Array.isArray(val) ? val : [val];
                    valArr.forEach(function (v) {
                        if (!v) return;
                        var exists = $el.find('option[value="' + String(v).replace(/"/g, '\\"') + '"]').length > 0;
                        if (!exists) $el.append(new Option(v, v, false, false));
                    });
                }
                $el.val(val).trigger('change');
            });
        }
        onMetadataReady(applyMetadataFieldValues);

        // Set client_ready checkbox
        var clientReadyCheckbox = document.getElementById('finalIssueClientReady');
        if (clientReadyCheckbox) {
            // Handle both string "1" and integer 1
            clientReadyCheckbox.checked = (issue && (issue.client_ready == 1 || issue.client_ready === '1' || issue.client_ready === true));
        }
        
        if (issue && issue.grouped_urls) setGroupedUrls(issue.grouped_urls);
        else if (draftData && draftData.grouped_urls) setGroupedUrls(draftData.grouped_urls);
        else updateGroupedUrls();
        toggleCommonTitle();
        if (!issue) ensureDefaultSections();
        renderIssueComments(issue ? String(issue.id) : 'new');
        if (issue && issue.id) loadIssueComments(String(issue.id));
        setFinalIssueComposeExpanded(false, { focus: false });
        updateFinalIssueCommentCharCount();

        setTimeout(function () {
            // For new issues only — edit issues capture state inside applySelectValuesNow after Select2 is set
            if (!issue) {
                issueData.initialFormState = captureFormState();
                startDraftAutosave();
            }
        }, 600);

        if (!skipShow) {
            showFinalIssueOverlay(modalEl);
        }
        // Removed the condition - always ensure metadata fields are properly initialized
        Promise.resolve().then(function () { var at = modalEl.querySelector('.nav-link.active'); if (at) at.dispatchEvent(new Event('shown.bs.tab', { bubbles: true })); });
    }

    function openReviewEditor(issue) {
        if (!reviewFeaturesEnabled) {
            issueNotify('Review issues feature is disabled.', 'warning');
            return;
        }
        if (!canEdit()) return;
        var modalEl = document.getElementById('reviewIssueModal');
        if (!modalEl) return;
        document.getElementById('reviewEditorTitle').textContent = issue ? 'Edit Review Issue' : 'New Review Issue';
        document.getElementById('reviewIssueEditId').value = issue ? issue.id : '';
        var ruleInput = document.getElementById('reviewIssueRuleId');
        var impactInput = document.getElementById('reviewIssueImpact');
        var sourceInput = document.getElementById('reviewIssuePrimarySourceUrl');
        if (ruleInput) ruleInput.value = issue ? (issue.rule_id || '') : '';
        if (impactInput) impactInput.value = issue ? (issue.impact || '') : '';
        if (sourceInput) sourceInput.value = issue ? ((issue.source_url || (Array.isArray(issue.source_urls) && issue.source_urls[0]) || '')) : '';
        document.getElementById('reviewIssueTitle').value = issue ? issue.title : '';
        document.getElementById('reviewIssueInstance').value = issue ? issue.instance : '';
        var urlsEl = document.getElementById('reviewIssueSourceUrls');
        if (urlsEl) {
            var urlsText = '';
            if (issue && Array.isArray(issue.source_urls) && issue.source_urls.length) {
                urlsText = issue.source_urls.map(function (u, idx) { return (idx + 1) + '. ' + u; }).join('\n');
            } else if (issue && issue.source_url) {
                urlsText = issue.source_url;
            }
            urlsEl.value = urlsText;
        }
        document.getElementById('reviewIssueWcag').value = issue ? issue.wcag : '';
        document.getElementById('reviewIssueSeverity').value = issue ? (issue.severity || 'medium') : 'medium';

        var detailsForEditor = issue ? normalizeReviewDetailsForEditor(issue.details || '') : '';
        jQuery('#reviewIssueDetails').summernote('code', detailsForEditor);
        if (!issue) {
            var savedDraft = loadReviewDraftLocal();
            if (savedDraft && savedDraft.form) {
                applyReviewFormState(savedDraft.form);
                if (window.showToast && savedDraft.updated_at) {
                    showToast('Review draft restored from ' + new Date(savedDraft.updated_at).toLocaleString(), 'info');
                }
            }
        }
        var moveBtn = document.getElementById('reviewIssueMoveToFinalBtn');
        if (moveBtn) {
            if (issue && issue.id) {
                moveBtn.classList.remove('d-none');
                var moveIds = (issue && Array.isArray(issue.ids) && issue.ids.length) ? issue.ids : [issue.id];
                moveBtn.setAttribute('data-ids', moveIds.join(','));
            } else {
                moveBtn.classList.add('d-none');
                moveBtn.removeAttribute('data-ids');
            }
        }
        reviewIssueInitialFormState = captureReviewFormState();
        reviewIssueBypassCloseConfirm = false;
        new bootstrap.Modal(modalEl).show();
    }

    function openCommonEditor(issue) {
        var modalEl = document.getElementById('commonIssueModal');
        if (!modalEl) return;
        document.getElementById('commonEditorTitle').textContent = issue ? 'Edit Common Issue' : 'New Common Issue';
        document.getElementById('commonIssueEditId').value = issue ? issue.id : '';
        document.getElementById('commonIssueTitle').value = issue ? issue.title : '';
        jQuery('#commonIssuePages').val(issue ? issue.pages : []).trigger('change');
        jQuery('#commonIssueDetails').summernote('code', issue ? issue.details : '');
        new bootstrap.Modal(modalEl).show();
    }

    function toggleCommonTitle() {
        var sel = jQuery('#finalIssuePages').val() || [];
        var wrap = document.getElementById('finalIssueCommonTitleWrap');
        var input = document.getElementById('finalIssueCommonTitle');
        if (!wrap || !input) return;

        // Show if multiple pages selected OR if it already has a value (editing an existing common issue)
        if (sel.length > 1 || (input.value && input.value.trim() !== '')) {
            wrap.classList.remove('d-none');
            input.required = true;
            input.setAttribute('aria-required', 'true');
        } else {
            wrap.classList.add('d-none');
            input.required = false;
            input.removeAttribute('aria-required');
        }
    }

    function validateCommonTitleRequirement() {
        var wrap = document.getElementById('finalIssueCommonTitleWrap');
        var input = document.getElementById('finalIssueCommonTitle');
        if (!wrap || !input || wrap.classList.contains('d-none')) {
            return true;
        }

        if (input.value.trim()) {
            return true;
        }

        input.focus();
        issueNotify('Common Issue Title is required when multiple pages are selected.', 'warning');
        return false;
    }

    function groupedUrlsByPages(pageIds) {
        var urls = [];
        function addUrl(val) {
            if (!val) return;
            var s = String(val).trim();
            if (!s) return;
            if (urls.indexOf(s) === -1) urls.push(s);
        }

        // For each selected page
        pageIds.forEach(function (pageId) {
            var page = pages.find(function (p) { return String(p.id) === String(pageId); });
            var pageUrl = page && page.url ? String(page.url).trim().toLowerCase() : '';

            var hasGroupedUrls = false;
            groupedUrls.forEach(function (row) {
                var rowPageId = row.unique_page_id != null ? row.unique_page_id : (row.mapped_page_id != null ? row.mapped_page_id : null);
                var matchById = rowPageId !== null && String(rowPageId) === String(pageId);
                var rowUrl = String(row.url || row.normalized_url || '').trim().toLowerCase();
                var matchByUrl = pageUrl && rowUrl && rowUrl === pageUrl;

                if (matchById || matchByUrl) {
                    hasGroupedUrls = true;
                    addUrl(row.url || row.normalized_url);
                }
            });

            // fallback: page's own URL
            if (!hasGroupedUrls) {
                if (page) {
                    addUrl(page.url || page.canonical_url || page.unique_url || page.normalized_url || page.page_url);
                }
            }
        });

        console.log('[groupedUrlsByPages] pageIds:', pageIds, '| groupedUrls.length:', groupedUrls.length, '| result count:', urls.length);
        if (groupedUrls.length > 0) {
            console.log('[groupedUrlsByPages] sample groupedUrls[0]:', JSON.stringify(groupedUrls[0]));
        }
        return urls;
    }

    function getAllGroupedUrlOptions() {
        var all = [];
        function addUrl(val) {
            if (!val) return;
            var s = String(val).trim();
            if (!s) return;
            if (all.indexOf(s) === -1) all.push(s);
        }

        (groupedUrls || []).forEach(function (row) {
            addUrl(row.url || row.normalized_url);
        });

        // Keep useful fallbacks visible too.
        (uniqueIssuePages || []).forEach(function (row) {
            addUrl(row.canonical_url || row.url || row.unique_url);
        });

        return all;
    }

    function updateGroupedUrls() {
        var pageIds = jQuery('#finalIssuePages').val() || [];
        var urls = groupedUrlsByPages(pageIds);
        setGroupedUrls(urls);
    }

    function setGroupedUrls(values) {
        var $sel = jQuery('#finalIssueGroupedUrls');
        var uniqueValues = [];
        (values || []).forEach(function (u) {
            var s = String(u || '').trim();
            if (!s) return;
            if (uniqueValues.indexOf(s) === -1) uniqueValues.push(s);
        });

        // Destroy select2, rebuild options, reinit, then set value
        try { $sel.select2('destroy'); } catch (e) {}

        $sel.empty();
        // Add all available options
        getAllGroupedUrlOptions().forEach(function (u) {
            $sel.append(new Option(u, u, false, false));
        });
        // Ensure selected values exist as options
        uniqueValues.forEach(function (u) {
            if (!$sel.find('option[value="' + u.replace(/"/g, '\\"') + '"]').length) {
                $sel.append(new Option(u, u, false, false));
            }
        });

        // Reinit select2
        var $gpParent = $sel.closest('.modal');
        $sel.select2({
            width: '100%',
            tags: true,
            tokenSeparators: [','],
            closeOnSelect: false,
            placeholder: 'Search or add URLs...',
            dropdownParent: $gpParent.length ? $gpParent : null
        });

        $sel.val(uniqueValues).trigger('change');
        updateUrlSelectionSummary();
        updateGroupedUrlsPreview();
    }

    function normalizeGroupedUrlsSelection(rawValues) {
        return (Array.isArray(rawValues) ? rawValues : []).filter(function (v) {
            return String(v || '').trim() !== '';
        });
    }

    function updateUrlSelectionSummary() {
        var summary = document.getElementById('urlSelectionSummary');
        if (!summary) return;
        var pagesCount = (jQuery('#finalIssuePages').val() || []).length;
        var urlsCount = (jQuery('#finalIssueGroupedUrls').val() || []).length;
        summary.textContent = 'Pages: ' + pagesCount + ' | Grouped URLs: ' + urlsCount + ' selected';
    }

    function updateGroupedUrlsPreview() {
        var countEl = document.getElementById('groupedUrlsPreviewCount');
        var listEl = document.getElementById('finalIssueGroupedUrlsPreviewList');
        if (!countEl || !listEl) return;
        var urls = normalizeGroupedUrlsSelection(jQuery('#finalIssueGroupedUrls').val() || []);
        countEl.textContent = String(urls.length);
        if (!urls.length) {
            listEl.innerHTML = '<li class="text-muted">No grouped URLs selected.</li>';
            return;
        }
        listEl.innerHTML = urls.map(function (u) {
            return '<li class="text-break"><a href="' + encodeURI(u) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(u) + '</a></li>';
        }).join('');
    }

    function syncUrlModalFromMain() {
        if (isSyncingUrlModal) return;
        isSyncingUrlModal = true;
        try {
            var $mainPages = jQuery('#finalIssuePages');
            var $mainUrls = jQuery('#finalIssueGroupedUrls');
            var $modalPages = jQuery('#urlModalPages');
            var $modalUrls = jQuery('#urlModalGroupedUrls');

            if (!$modalPages.length || !$modalUrls.length || !$mainPages.length || !$mainUrls.length) return;

            $modalPages.empty();
            $mainPages.find('option').each(function () {
                $modalPages.append('<option value="' + String(this.value).replace(/"/g, '&quot;') + '">' + this.text + '</option>');
            });
            $modalPages.val($mainPages.val() || []).trigger('change');

            $modalUrls.empty();
            $mainUrls.find('option').each(function () {
                $modalUrls.append('<option value="' + String(this.value).replace(/"/g, '&quot;') + '">' + this.text + '</option>');
            });
            var selectedUrls = $mainUrls.val() || [];
            selectedUrls.forEach(function (u) {
                if ($modalUrls.find('option').filter(function () { return this.value === String(u); }).length === 0) {
                    $modalUrls.append('<option value="' + String(u).replace(/"/g, '&quot;') + '">' + String(u) + '</option>');
                }
            });
            $modalUrls.val(selectedUrls).trigger('change');
        } finally {
            isSyncingUrlModal = false;
        }
    }

    function applyUrlModalToMain() {
        var modalPages = jQuery('#urlModalPages').val() || [];
        var modalUrls = normalizeGroupedUrlsSelection(jQuery('#urlModalGroupedUrls').val() || []);
        var $mainPages = jQuery('#finalIssuePages');
        var $mainUrls = jQuery('#finalIssueGroupedUrls');

        $mainPages.val(modalPages).trigger('change');

        modalUrls.forEach(function (u) {
            if ($mainUrls.find('option').filter(function () { return this.value === String(u); }).length === 0) {
                $mainUrls.append('<option value="' + String(u).replace(/"/g, '&quot;') + '">' + String(u) + '</option>');
            }
        });
        $mainUrls.val(modalUrls).trigger('change');
        updateUrlSelectionSummary();
        updateGroupedUrlsPreview();
    }

    function initUrlSelectionModal() {
        var $openBtn = jQuery('#btnOpenUrlSelectionModal');
        var $modal = jQuery('#urlSelectionModal');
        var $modalPages = jQuery('#urlModalPages');
        var $modalUrls = jQuery('#urlModalGroupedUrls');
        if (!$openBtn.length || !$modal.length || !$modalPages.length || !$modalUrls.length) return;

        if (window.jQuery && jQuery.fn.select2) {
            try { if ($modalPages.data('select2')) $modalPages.select2('destroy'); } catch (e) { }
            try { if ($modalUrls.data('select2')) $modalUrls.select2('destroy'); } catch (e) { }
            $modalPages.select2({ width: '100%', closeOnSelect: false, dropdownParent: $modal });
            $modalUrls.select2({ width: '100%', tags: true, tokenSeparators: [','], closeOnSelect: false, dropdownParent: $modal });

            // Multi-paste handler helper
            var setupMultiPaste = function($s2, isTags) {
                $s2.on('select2:open', function() {
                    var $search = jQuery('.select2-container--open .select2-search__field');
                    $search.off('paste.multis2').on('paste.multis2', function(e) {
                        var cb = e.originalEvent.clipboardData || window.clipboardData;
                        var text = cb.getData('text');
                        var items = text.split(/[,\r\n]+/).map(function(s){ return s.trim(); }).filter(Boolean);
                        if (items.length > 1) {
                            e.preventDefault();
                            var current = $s2.val() || [];
                            items.forEach(function(item) {
                                if (current.indexOf(item) === -1) {
                                    var exists = $s2.find("option").filter(function() { return this.value === item; }).length > 0;
                                    if (!exists && isTags) {
                                        $s2.append(new Option(item, item, true, true));
                                        current.push(item);
                                    } else if (exists) {
                                        current.push(item);
                                    }
                                }
                            });
                            $s2.val(current).trigger('change');
                            $s2.select2('close');
                        }
                    });
                });
            };

            setupMultiPaste($modalPages, false);
            setupMultiPaste($modalUrls, true);
        }

        $openBtn.off('click.urlModal').on('click.urlModal', function () {
            syncUrlModalFromMain();
            var instance = bootstrap.Modal.getInstance($modal[0]) || new bootstrap.Modal($modal[0]);
            instance.show();
        });

        $modalPages.off('change.urlModalPages').on('change.urlModalPages', function () {
            if (isSyncingUrlModal) return;
            var selectedPages = $modalPages.val() || [];
            jQuery('#finalIssuePages').val(selectedPages).trigger('change');
            syncUrlModalFromMain();
        });

        jQuery('#btnApplyUrlSelection').off('click.urlModalApply').on('click.urlModalApply', function () {
            applyUrlModalToMain();
        });

        jQuery('#btnCopyGroupedUrls').off('click.urlModalCopy').on('click.urlModalCopy', function () {
            var selected = normalizeGroupedUrlsSelection($modalUrls.val() || []);
            var text = selected.join('\n');
            if (!text) {
                if (window.showToast) showToast('No URLs selected to copy', 'info');
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    if (window.showToast) showToast('Grouped URLs copied', 'success');
                }).catch(function () {
                    if (window.showToast) showToast('Copy failed', 'danger');
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); if (window.showToast) showToast('Grouped URLs copied', 'success'); } catch (e) { if (window.showToast) showToast('Copy failed', 'danger'); }
                document.body.removeChild(ta);
            }
        });

        jQuery('#btnClearGroupedUrls').off('click.urlModalClear').on('click.urlModalClear', function () {
            if (confirm('Are you sure you want to remove all grouped URLs?')) {
                $modalUrls.val(null).trigger('change');
            }
        });
        
        // Add native Ctrl+A + Delete support inside the Select2 search field
        jQuery(document).off('keydown.urlSelect2Clear').on('keydown.urlSelect2Clear', '.select2-container--open .select2-search__field', function(e) {
            if (e.ctrlKey && e.key === 'a') {
                jQuery(this).data('ctrlA_pressed', true);
            } else if ((e.key === 'Backspace' || e.key === 'Delete') && jQuery(this).data('ctrlA_pressed')) {
                var $select2Input = jQuery(this).closest('.select2-container').prev('select');
                if ($select2Input.attr('id') === 'urlModalGroupedUrls' || $select2Input.attr('id') === 'finalIssueGroupedUrls') {
                    $select2Input.val(null).trigger('change');
                    jQuery(this).val(''); // Clear the search box too
                    jQuery(this).data('ctrlA_pressed', false);
                    e.preventDefault();
                }
            } else {
                jQuery(this).data('ctrlA_pressed', false);
            }
        });
    }

    function clearIssueMetadataForTemplateReset() {
        // Clear metadata fields (dynamic + key meta controls) before applying template content.
        var container = document.getElementById('finalIssueMetadataContainer');
        if (container) {
            container.querySelectorAll('[id^="finalIssueField_"]').forEach(function (el) {
                var id = el.id || '';
                if (id === 'finalIssueField_severity' || id === 'finalIssueField_priority') {
                    jQuery(el).val(['medium']).trigger('change');
                    return;
                }
                if (el.multiple) jQuery(el).val([]).trigger('change');
                else jQuery(el).val('').trigger('change');
            });
        }

        jQuery('#finalIssueReporters').val([]).trigger('change');
        refreshReporterQaStatusEditor({});
        document.getElementById('finalIssueCommonTitle').value = '';

        // Recalculate grouped URLs from selected page(s), with fallback to page URL if no grouped URL exists.
        updateGroupedUrls();
    }

    // Helper functions for badges
    function getSeverityBadge(s) {
        if (!s || s === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        s = String(s).toLowerCase();
        var colors = {
            'critical': 'danger',
            'high': 'warning',
            'medium': 'info',
            'low': 'success',
            'major': 'warning',
            'minor': 'info'
        };
        var color = colors[s] || 'secondary';
        return '<span class="badge bg-' + color + '">' + escapeHtml(s.toUpperCase()) + '</span>';
    }

    function getPriorityBadge(p) {
        if (!p || p === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        p = String(p).toLowerCase();
        var colors = {
            'critical': 'danger',
            'high': 'warning',
            'medium': 'info',
            'low': 'success'
        };
        var color = colors[p] || 'secondary';
        return '<span class="badge bg-' + color + '">' + escapeHtml(p.toUpperCase()) + '</span>';
    }

    function getStatusBadge(statusId, statusLabel) {
        if (!statusId) return '<span class="badge bg-secondary">N/A</span>';
        // statusId can be either numeric ID or status key string
        if (ProjectConfig.issueStatuses) {
            var found = ProjectConfig.issueStatuses.find(function (s) {
                // Try matching by ID first (numeric comparison)
                if (s.id == statusId) return true;
                // Fallback to matching by slug/key/label (case-insensitive)
                var candidate = String(statusId).toLowerCase();
                if (s.name && String(s.name).toLowerCase() === candidate) return true;
                if (s.status_key && String(s.status_key).toLowerCase() === candidate) return true;
                if (s.status_label && String(s.status_label).toLowerCase() === candidate) return true;
                if (s.label && String(s.label).toLowerCase() === candidate) return true;
                return false;
            });
            if (found) {
                var color = found.badge_color || found.color || '#6c757d';
                var name = statusLabel || found.status_label || found.name || found.label || found.status_key || 'Unknown';
                // If color is a hex code, use inline style; otherwise use Bootstrap class
                if (String(color).startsWith('#')) {
                    return '<span class="badge" style="background-color: ' + color + '; color: white;">' + escapeHtml(name) + '</span>';
                } else {
                    return '<span class="badge bg-' + color + '">' + escapeHtml(name) + '</span>';
                }
            }
        }
        return '<span class="badge bg-secondary">' + escapeHtml(String(statusLabel || statusId)) + '</span>';
    }

    function getQaBadge(q) {
        if (!q || q === 'pending' || q === 'N/A') return '<span class="badge bg-secondary">N/A</span>';
        q = String(q).toLowerCase();
        if (q === 'pass' || q === 'passed') return '<span class="badge bg-success">PASS</span>';
        if (q === 'fail' || q === 'failed') return '<span class="badge bg-danger">FAIL</span>';
        return '<span class="badge bg-warning">' + escapeHtml(q.toUpperCase()) + '</span>';
    }

    function getClientReadyBadge(clientReady) {
        if (clientReady == 1) return '<span class="badge bg-success">Yes</span>';
        return '<span class="badge bg-secondary">No</span>';
    }

    function generateIssueDetailsContent(issue) {
        // Generate the details content for expanded row with proper image handling
        var details = decorateIssueImages(issue.details || '');
        if (!details) {
            details = '<p class="text-muted">No details provided.</p>';
        }
        
        return '<div class="issue-details-content">' +
            '<div class="mb-3">' + details + '</div>' +
            '<div class="row g-2 small text-muted">' +
            '<div class="col-md-6"><strong>URL:</strong> ' + escapeHtml(issue.url || 'N/A') + '</div>' +
            '<div class="col-md-6"><strong>Environment:</strong> ' + escapeHtml(issue.environment || 'N/A') + '</div>' +
            '</div>' +
            '</div>';
    }

    function stripHtml(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html || '';
        return tmp.textContent || tmp.innerText || '';
    }

    function renderFinalIssues() {
        var tbody = document.getElementById('finalIssuesBody');
        if (!tbody) return;
        // Preserve expanded rows across live refresh/re-render.
        var expandedIssueIds = [];
        document.querySelectorAll('#finalIssuesBody tr.collapse.show[id^="issue-details-"]').forEach(function (row) {
            var id = String(row.id || '');
            var issueId = id.replace('issue-details-', '');
            if (issueId) expandedIssueIds.push(issueId);
        });
        if (!issueData.selectedPageId) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-5"><div class="text-muted mb-2"><i class="fas fa-arrow-left fa-2x opacity-25"></i></div><div class="text-muted fw-medium">Select a page from the list to view issues.</div></td></tr>';
            updateIssueTabCounts();
            return;
        }
        var issues = issueData.pages[issueData.selectedPageId].final || [];
        
        // Sort issues by issue_key (natural sorting: ISS-1, ISS-2, ISS-10, ISS-11)
        issues = issues.slice().sort(function(a, b) {
            var keyA = String(a.issue_key || '');
            var keyB = String(b.issue_key || '');
            
            // Extract prefix and number from issue key (e.g., "ISS-123" -> ["ISS", "123"])
            var partsA = keyA.split('-');
            var partsB = keyB.split('-');
            
            // Compare prefix first
            if (partsA[0] !== partsB[0]) {
                return partsA[0].localeCompare(partsB[0]);
            }
            
            // Compare numeric part
            var numA = parseInt(partsA[1]) || 0;
            var numB = parseInt(partsB[1]) || 0;
            return numA - numB;
        });
        
        issues = applyClientFinalIssueFilters(issues);

        if (!issues.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center py-5"><div class="text-muted mb-2"><i class="fas fa-check-circle fa-2x opacity-25"></i></div><div class="text-muted fw-medium">No final issues recorded yet.</div></td></tr>';
            updateIssueTabCounts();
            return;
        }

        var stripHtml = function (html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            return tmp.textContent || tmp.innerText || '';
        };

        tbody.innerHTML = issues.map(function (issue) {
            // Extract values and handle arrays OR stringified arrays
            var severity = issue.severity || 'N/A';

            // Check if it's a stringified array like '["Low"]'
            if (typeof severity === 'string' && severity.startsWith('[')) {
                try {
                    var parsed = JSON.parse(severity);
                    if (Array.isArray(parsed)) {
                        severity = parsed[0] || 'N/A';
                    }
                } catch (e) { }
            } else if (Array.isArray(severity)) {
                severity = severity[0] || 'N/A';
            }

            var priority = issue.priority || 'N/A';

            // Check if it's a stringified array like '["Low"]'
            if (typeof priority === 'string' && priority.startsWith('[')) {
                try {
                    var parsed = JSON.parse(priority);
                    if (Array.isArray(parsed)) {
                        priority = parsed[0] || 'N/A';
                    }
                } catch (e) { }
            } else if (Array.isArray(priority)) {
                priority = priority[0] || 'N/A';
            }

            var status = issue.status_name || issue.status || 'open';
            var statusId = issue.status_id || null;
            var qaStatusHtml = getReporterQaStatusHtml(issue);

            // Handle multiple reporters
            var reportersArray = Array.isArray(issue.reporters) && issue.reporters.length > 0
                ? issue.reporters
                : (issue.reporter_name ? [issue.reporter_name] : []);

            var reporterHtml = '';
            if (reportersArray.length > 0) {
                reporterHtml = reportersArray.map(function (reporterId) {
                    var reporterName = getProjectUserNameById(reporterId);
                    return '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>';
                }).join('');
            } else {
                reporterHtml = '<span class="text-muted">N/A</span>';
            }

            var qaName = issue.qa_name || 'N/A';
            var issueKey = issue.issue_key || 'N/A';
            var pageCount = (issue.pages && issue.pages.length) || 1;
            var titlePreview = stripHtml(issue.details).substring(0, 100);
            if (titlePreview && stripHtml(issue.details).length > 100) titlePreview += '...';
            var uniqueId = 'issue-details-' + issue.id;
            var testerDeleteBlocked = !!(isTesterRole && issue.can_tester_delete === false);
            var deleteTitle = testerDeleteBlocked
                ? 'Testers cannot delete this issue because it has comments or QA status is set on an Open issue.'
                : 'Delete Issue';

            // Main row - NOT directly clickable, only chevron button is
            var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '">';
            
            // Checkbox column - hide for client
            if (userRole !== 'client') {
                mainRow += '<td class="checkbox-cell"><input type="checkbox" class="final-select" value="' + issue.id + '"' + (testerDeleteBlocked ? ' disabled' : '') + '></td>';
            }
            
            mainRow += '<td><span class="badge bg-primary">' + escapeHtml(issueKey) + '</span></td>' +
                '<td style="min-width: 250px; max-width: 400px;">' +
                '<div class="d-flex align-items-center">' +
                '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" ' +
                'data-collapse-target="#' + uniqueId + '" ' +
                'aria-label="Expand details for ' + escapeHtml(issueKey) + ': ' + escapeHtml(issue.title) + '" ' +
                'style="border: none; background: none; font-size: 1rem;">' +
                '<i class="fas fa-chevron-right chevron-icon"></i>' +
                '</button>' +
                '<div style="cursor: pointer;" class="issue-title-click" data-issue-id="' + issue.id + '">' +
                (issue.common_title ?
                    '<div class="fw-bold">' + escapeHtml(issue.common_title) + '</div>' +
                    '<div class="small text-muted">' + escapeHtml(issue.title) + '</div>'
                    :
                    '<div>' + escapeHtml(issue.title) + '</div>' +
                    (titlePreview ? '<div class="small text-muted">' + escapeHtml(titlePreview) + '</div>' : '')
                ) +
                '</div>' +
                '</div>' +
                '</td>' +
                '<td>' + getStatusBadge(statusId, status) + '</td>';
            
            // QA Status, Reporter, QA Name, Client Ready columns - hide for client
            if (userRole !== 'client') {
                mainRow += '<td>' + qaStatusHtml + '</td>' +
                    '<td>' + reporterHtml + '</td>' +
                    '<td>' +
                    (qaName !== 'N/A' ?
                        '<span class="badge bg-success">' + escapeHtml(qaName) + '</span>' :
                        '<span class="text-muted">N/A</span>') +
                    '</td>' +
                    '<td>' +
                    (issue.client_ready == 1 ?
                        '<span class="badge bg-success"><i class="fas fa-check"></i> Yes</span>' :
                        '<span class="badge bg-secondary"><i class="fas fa-times"></i> No</span>') +
                    '</td>';
            }
            
            mainRow += '<td>' +
                '<span class="badge bg-secondary">' + pageCount + ' page(s)</span>' +
                '</td>';
            
            // Actions column
            if (userRole !== 'client') {
                mainRow += '<td class="action-buttons-cell">' +
                    '<button class="btn btn-sm btn-outline-primary me-1 final-edit" data-id="' + issue.id + '" type="button" title="Edit Issue">' +
                    '<i class="fas fa-edit"></i>' +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger final-delete" data-id="' + issue.id + '" type="button" title="' + escapeAttr(deleteTitle) + '"' + (testerDeleteBlocked ? ' disabled' : '') + '>' +
                    '<i class="fas fa-trash"></i>' +
                    '</button>' +
                    '</td>';
            } else {
                mainRow += '<td class="action-buttons-cell">' +
                    '<button class="btn btn-sm btn-outline-primary issue-open" data-id="' + issue.id + '" type="button" title="Update status or add comment">' +
                    '<i class="fas fa-pen-to-square me-1"></i>Update' +
                    '</button>' +
                    '</td>';
            }
            
            mainRow += '</tr>';

            // Expandable details row
            var detailsRow = '<tr class="collapse" id="' + uniqueId + '">' +
                '<td colspan="9" class="p-0 border-0">' +
                '<div class="bg-light p-4 border-top">' +
                '<div class="row g-3">' +
                '<div class="col-md-8">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
                '<div class="card">' +
                '<div class="card-body issue-content">' +
                (decorateIssueImages(issue.details) || '<p class="text-muted">No details provided.</p>') +
                '</div>' +
                '</div>' +
                '</div>' +
                '<div class="col-md-4">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                '<div class="mb-2"><strong>Issue Key:</strong><br>' +
                '<span class="badge bg-primary">' + escapeHtml(issueKey) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Status:</strong><br>' + getStatusBadge(statusId, status) + '</div>' +
                (userRole !== 'client' ? '<div class="mb-2"><strong>QA Status:</strong><br>' + qaStatusHtml + '</div>' : '') +
                '<div class="mb-2"><strong>Severity:</strong><br>' +
                '<span class="badge bg-warning text-dark">' + escapeHtml((severity || 'N/A').toUpperCase()) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Priority:</strong><br>' +
                '<span class="badge bg-info text-dark">' + escapeHtml((priority || 'N/A').toUpperCase()) + '</span>' +
                '</div>' +
                (userRole !== 'client' ? '<div class="mb-2"><strong>Reporter(s):</strong><br>' +
                (reportersArray.length > 0 ? reportersArray.map(function (reporterId) {
                    var reporterName = 'Unknown';
                    if (ProjectConfig.projectUsers) {
                        var found = ProjectConfig.projectUsers.find(function (u) { return u.id == reporterId; });
                        if (found) reporterName = found.full_name;
                    }
                    return escapeHtml(reporterName);
                }).join(', ') : (issue.reporter_name ? escapeHtml(issue.reporter_name) : '<span class="text-muted">N/A</span>')) +
                '</div>' : '') +
                (userRole !== 'client' ? '<div class="mb-2"><strong>QA Name:</strong><br>' + escapeHtml(qaName) + '</div>' : '') +
                (function () {
                    // Pages section - bullet list with scrollable container
                    var pagesHtml = '<div class="mb-2"><strong>Pages:</strong> ';
                    if (issue.pages && issue.pages.length > 0) {
                        var pageNames = issue.pages.map(function (pageId) {
                            return getPageName(pageId);
                        });
                        pagesHtml += '<span class="badge bg-secondary ms-1">' + pageNames.length + '</span>';
                        pagesHtml += '<div class="mt-1 border rounded bg-white p-2" style="max-height:120px;overflow-y:auto;">';
                        pagesHtml += '<ul class="list-unstyled mb-0 small">';
                        pageNames.forEach(function(name) {
                            pagesHtml += '<li><i class="fas fa-file-alt text-muted me-1"></i>' + escapeHtml(name) + '</li>';
                        });
                        pagesHtml += '</ul></div>';
                    } else {
                        pagesHtml += '<span class="text-muted">N/A</span>';
                    }
                    pagesHtml += '</div>';

                    // Grouped URLs section - same as issues-all.js
                    var urlsHtml = '';
                    if (issue.grouped_urls && issue.grouped_urls.length > 0) {
                        urlsHtml += '<div class="mb-2"><strong>Grouped URLs:</strong> <span class="badge bg-info ms-1">' + issue.grouped_urls.length + '</span>' +
                            '<button class="btn btn-link p-0 ms-1 text-primary" style="font-size:12px;text-decoration:none;" onclick="toggleGroupedUrls(\'' + issue.id + '\',event)"><small>Show/Hide</small></button>' +
                            '<div id="grouped-urls-content-' + issue.id + '" style="display:none;margin-top:4px;">' +
                            '<div class="border rounded bg-white p-2" style="max-height:120px;overflow-y:auto;">' +
                            '<ul class="list-unstyled mb-0 small">';
                        issue.grouped_urls.forEach(function (urlString) {
                            var urlData = (ProjectConfig.groupedUrls || []).find(function (u) {
                                return u.url === urlString || u.normalized_url === urlString;
                            });
                            var displayUrl = urlData ? urlData.url : urlString;
                            if (displayUrl) {
                                urlsHtml += '<li class="mb-1 text-break"><a href="' + escapeHtml(displayUrl) + '" target="_blank" class="text-decoration-none"><i class="fas fa-link me-1 text-primary"></i>' + escapeHtml(displayUrl) + '</a></li>';
                            }
                        });
                        urlsHtml += '</ul></div></div></div>';
                    }

                    if (userRole === 'client') {
                        return '<div class="mb-2"><strong>Page:</strong><br><small class="text-muted">' + escapeHtml(getPageName(issueData.selectedPageId || (issue.pages && issue.pages[0]) || '')) + '</small></div>';
                    }

                    return pagesHtml + urlsHtml;
                })() +
                (function () {
                    if (userRole === 'client') {
                        return buildClientQuickStatusActions(issue) +
                            '<div class="small text-muted mt-3">Open the panel above to post regression comments with screenshots.</div>';
                    }

                    var metaHtml = '';
                    if (typeof issueMetadataFields !== 'undefined') {
                        issueMetadataFields.forEach(function (f) {
                            // Skip severity and priority as they're already shown above
                            if (f.field_key === 'severity' || f.field_key === 'priority') return;

                            var value = issue[f.field_key];
                            if (value && value.length > 0) {
                                var displayValue = Array.isArray(value) ? value.join(', ') : value;
                                metaHtml += '<div class="mb-2"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                            }
                        });
                    }
                    // Add created_at and updated_at timestamps - hide for client
                    if (userRole !== 'client') {
                        if (issue.created_at) {
                            metaHtml += '<div class="mb-2"><strong>Created:</strong><br><small class="text-muted">' + new Date(issue.created_at).toLocaleString() + '</small></div>';
                        }
                        if (issue.updated_at) {
                            metaHtml += '<div class="mb-2"><strong>Updated:</strong><br><small class="text-muted">' + new Date(issue.updated_at).toLocaleString() + '</small></div>';
                        }
                    }
                    return metaHtml;
                })() +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</td>' +
                '</tr>';

            return mainRow + detailsRow;
        }).join('');

        // Add click handlers for chevron toggle buttons
        document.querySelectorAll('#finalIssuesBody .chevron-toggle-btn').forEach(function (btn) {
            // Click handler for chevron button
            btn.addEventListener('click', function (e) {
                e.stopPropagation(); // Prevent event bubbling
                toggleIssueRow(this);
            });

            // Keyboard handler (Enter or Space)
            btn.addEventListener('keydown', function (e) {
                // Only handle Enter (13) or Space (32)
                if (e.keyCode === 13 || e.keyCode === 32) {
                    e.preventDefault(); // Prevent page scroll on Space
                    e.stopPropagation();
                    toggleIssueRow(this);
                }
            });
        });

        // Add click handler for issue title
        document.querySelectorAll('#finalIssuesBody .issue-title-click').forEach(function (titleEl) {
            titleEl.addEventListener('click', function (e) {
                e.stopPropagation();
                
                // For client users, open the restricted modal
                if (userRole === 'client') {
                    var issueId = this.getAttribute('data-issue-id');
                    if (issueId && issueData.selectedPageId) {
                        var clientIssue = issueData.pages[issueData.selectedPageId].final.find(function (i) {
                            return String(i.id) === String(issueId);
                        });
                        if (clientIssue) openFinalEditor(clientIssue);
                    }
                } else {
                    // For non-client users, open edit modal
                    var issueId = this.getAttribute('data-issue-id');
                    if (issueId && issueData.selectedPageId) {
                        var issue = issueData.pages[issueData.selectedPageId].final.find(function (i) {
                            return String(i.id) === String(issueId);
                        });
                        if (issue) openFinalEditor(issue);
                    }
                }
            });
        });

        document.querySelectorAll('#finalIssuesBody .client-quick-status').forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                quickUpdateClientIssueStatus(this.getAttribute('data-issue-id'), this.getAttribute('data-status-id'), this);
            });
        });

        // Add click handler for entire row (for mouse users)
        document.querySelectorAll('#finalIssuesBody .issue-expandable-row').forEach(function (row) {
            row.style.cursor = 'pointer';

            row.addEventListener('click', function (e) {
                // Don't expand if clicking on buttons, checkbox, inputs, or action buttons
                if (e.target.closest('button') ||
                    e.target.closest('input') ||
                    e.target.closest('select') ||
                    e.target.closest('.action-buttons-cell') ||
                    e.target.closest('.checkbox-cell') ||
                    e.target.closest('.issue-title-click')) {
                    return;
                }

                // Find the chevron button in this row and trigger it
                var chevronBtn = this.querySelector('.chevron-toggle-btn');
                if (chevronBtn) {
                    toggleIssueRow(chevronBtn);
                }
            });
        });

        document.dispatchEvent(new CustomEvent('pms:issueTableUpdated'));

        // Helper function to toggle issue row expansion
        function toggleIssueRow(btn) {
            var targetId = btn.getAttribute('data-collapse-target');
            if (targetId) {
                var collapseEl = document.querySelector(targetId);
                var chevronIcon = btn.querySelector('.chevron-icon');

                if (collapseEl) {
                    // Check current state and toggle
                    var isExpanded = collapseEl.classList.contains('show');

                    if (isExpanded) {
                        // Collapse
                        collapseEl.classList.remove('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-right chevron-icon';
                    } else {
                        // Expand
                        collapseEl.classList.add('show');
                        if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                    }
                }
            }
        }

        // Restore expanded state after table render.
        if (expandedIssueIds.length) {
            expandedIssueIds.forEach(function (issueId) {
                var detailsRow = document.getElementById('issue-details-' + issueId);
                if (detailsRow) detailsRow.classList.add('show');
                var chevronIcon = document.querySelector('#finalIssuesBody [data-collapse-target="#issue-details-' + issueId + '"] .chevron-icon');
                if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
            });
        }

        // Auto-expand issue from URL parameter
        if (window.expandIssueId) {
            var expandIssueId = window.expandIssueId;
            var detailsRow = document.getElementById('issue-details-' + expandIssueId);
            if (detailsRow) {
                detailsRow.classList.add('show');
                var chevronIcon = document.querySelector('#finalIssuesBody [data-collapse-target="#issue-details-' + expandIssueId + '"] .chevron-icon');
                if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                
                // Scroll to the expanded issue
                setTimeout(function() {
                    var mainRow = document.querySelector('#finalIssuesBody [data-collapse-target="#issue-details-' + expandIssueId + '"]');
                    if (mainRow) {
                        mainRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            }
            // Clear the expand parameter after use
            window.expandIssueId = null;
        }

        // Update tab counts after rendering
        updateIssueTabCounts();

        // Add click handlers for images in expanded details
        setTimeout(function () {
            // Define the handler once for both types of image selection
            var commonImageClickHandler = function (e) {
                e.stopPropagation();
                e.preventDefault();
                var src = this.getAttribute('src') || this.src;
                var alt = this.getAttribute('alt') || this.alt || '';
                if (src) {
                    openIssueImageModal(src, alt);
                }
            };

            // Use a broader selector that covers all images within the container
            // and apply logic consistently
            document.querySelectorAll('#finalIssuesBody img').forEach(function (img) {
                img.style.cursor = 'pointer';
            });
        }, 100);
    }

    function renderReviewIssues() {
        if (!reviewFeaturesEnabled) {
            var tbodyDisabled = document.getElementById('reviewIssuesBody');
            if (tbodyDisabled) tbodyDisabled.innerHTML = '';
            return;
        }
        var tbody = document.getElementById('reviewIssuesBody');
        if (!tbody) return;
        var rawItems = (issueData.selectedPageId && issueData.pages[issueData.selectedPageId]) ? (issueData.pages[issueData.selectedPageId].review || []) : [];
        var allItems = groupReviewItems(rawItems);
        if (!allItems.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-5 text-muted">No review findings logged.</td></tr>';
            renderReviewPagination(0);
            updateIssueTabCounts();
            return;
        }
        var totalPages = Math.max(1, Math.ceil(allItems.length / reviewPageSize));
        if (reviewCurrentPage > totalPages) reviewCurrentPage = totalPages;
        if (reviewCurrentPage < 1) reviewCurrentPage = 1;
        var start = (reviewCurrentPage - 1) * reviewPageSize;
        var pageItems = allItems.slice(start, start + reviewPageSize);

        tbody.innerHTML = pageItems.map(function (it) {
            var instanceText = it.instances && it.instances.length ? it.instances.map(formatInstanceReadable).join(' || ') : '-';
            var fullDesc = (it.description_text || stripHtml(it.details || '') || '');
            if (instanceText && instanceText !== '-') fullDesc += (fullDesc ? ' | ' : '') + 'Instances: ' + instanceText;
            var detailsPreview = escapeHtml(fullDesc.slice(0, 140));
            if (fullDesc.length > 140) detailsPreview += '...';
            var displayInstances = escapeHtml(instanceText.length > 110 ? (instanceText.slice(0, 107) + '...') : instanceText);
            var sourceUrls = Array.isArray(it.source_urls) ? it.source_urls.filter(Boolean) : (it.source_url ? [it.source_url] : []);
            var sourceUrl = sourceUrls.length ? sourceUrls[0] : '-';
            var safeHref = (/^https?:\/\//i.test(String(sourceUrl)) ? sourceUrl : '#');
            var sourceUrlHtml = sourceUrl !== '-' ? '<a href="' + escapeAttr(safeHref) + '" target="_blank" rel="noopener">' + escapeHtml(sourceUrl) + '</a>' : '-';
            var sourceCountHtml = '<div class="small text-muted">' + sourceUrls.length + ' URL' + (sourceUrls.length === 1 ? '' : 's') + '</div>';
            var recommendation = String(it.recommendation || '-').trim() || '-';
            var idsCsv = (it.ids || []).join(',');
            var primaryId = String(it.primary_id || (it.ids && it.ids[0]) || '');
            return '<tr class="align-middle">' +
                '<td class="text-center"><input type="checkbox" class="form-check-input review-select" data-id="' + escapeAttr(idsCsv) + '"></td>' +
                '<td><div class="fw-medium text-dark">' + escapeHtml(it.title) + '</div>' + (detailsPreview ? '<div class="small text-muted">' + detailsPreview + '</div>' : '') + '</td>' +
                '<td class="small text-break">' + sourceUrlHtml + sourceCountHtml + '</td>' +
                '<td class="font-monospace small text-primary" title="' + escapeAttr(instanceText) + '">' + displayInstances + '</td>' +
                '<td><span class="badge bg-light text-dark border">' + escapeHtml(it.rule_id || '-') + '</span></td>' +
                '<td><span class="badge bg-secondary-subtle text-secondary text-uppercase">' + escapeHtml(it.impact || '-') + '</span></td>' +
                '<td><span class="badge bg-light text-dark border">' + escapeHtml(formatWcagDisplay(it.wcag || 'N/A')) + '</span></td>' +
                '<td><span class="badge bg-warning-subtle text-warning text-uppercase">' + escapeHtml(it.severity || '-') + '</span></td>' +
                '<td class="small text-break">' + escapeHtml(recommendation) + '</td>' +
                '<td class="text-end"><div class="btn-group"><button class="btn btn-sm btn-outline-primary review-edit bg-white" data-id="' + escapeAttr(primaryId) + '" data-ids="' + escapeAttr(idsCsv) + '"><i class="fas fa-pencil-alt"></i></button><button class="btn btn-sm btn-outline-danger review-delete bg-white" data-id="' + escapeAttr(idsCsv) + '"><i class="far fa-trash-alt"></i></button></div></td>' +
                '</tr>';
        }).join('');
        renderReviewPagination(allItems.length);
        updateIssueTabCounts();
    }

    function updateIssueTabCounts() {
        var finalCount = 0;
        var reviewCount = 0;
        var selectedId = String(issueData.selectedPageId || "");
        if (selectedId && issueData.pages[selectedId]) {
            finalCount = (issueData.pages[selectedId].final || []).length;
            reviewCount = groupReviewItems(issueData.pages[selectedId].review || []).length;
            
            // If needsReview exists in data, prefer it for the "Needs Review" tab if it's not and-on from automated scan
            // However, the "Needs Review" tab in issues_page_detail often shows automated scan results.
            // Let's ensure we don't zero out the badge if we have data.
            var nrItems = issueData.pages[selectedId].needsReview || [];
            if (nrItems.length > 0 && reviewCount === 0) {
                 reviewCount = nrItems.length;
            }
        }
        var finalBadge = document.getElementById('finalIssuesCountBadge');
        var reviewBadge = document.getElementById('needsReviewCountBadge');
        if (finalBadge) finalBadge.textContent = String(finalCount);
        if (reviewBadge) reviewBadge.textContent = String(reviewCount);
    }

    function normalizeIdList(raw) {
        if (Array.isArray(raw)) {
            return Array.from(new Set(raw.reduce(function (acc, item) {
                return acc.concat(normalizeIdList(item));
            }, [])));
        }
        if (raw == null) return [];
        return String(raw)
            .split(',')
            .map(function (x) { return String(x).trim(); })
            .filter(function (x) { return x !== ''; });
    }

    function collectSelectedReviewIds() {
        var selected = Array.from(document.querySelectorAll('.review-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
        return Array.from(new Set(normalizeIdList(selected)));
    }

    function mapImpactToSeverity(impact) {
        var v = String(impact || '').toLowerCase();
        if (v === 'critical') return 'critical';
        if (v === 'serious') return 'high';
        if (v === 'moderate') return 'medium';
        if (v === 'minor') return 'low';
        return 'medium';
    }

    function formatWcagDisplay(wcagRaw) {
        var src = String(wcagRaw || '');
        if (!src) return 'N/A';
        var tags = src.split(',').map(function (x) { return x.trim().toLowerCase(); }).filter(Boolean);
        var level = '';
        if (tags.indexOf('wcag2aaa') !== -1 || tags.indexOf('wcag21aaa') !== -1) level = 'AAA';
        else if (tags.indexOf('wcag2aa') !== -1 || tags.indexOf('wcag21aa') !== -1) level = 'AA';
        else if (tags.indexOf('wcag2a') !== -1 || tags.indexOf('wcag21a') !== -1) level = 'A';
        var criteria = tags
            .filter(function (t) { return /^wcag\d{3,4}$/.test(t); })
            .map(function (t) {
                var d = t.replace('wcag', '');
                if (d.length === 3) return d[0] + '.' + d[1] + '.' + d[2];
                if (d.length === 4) return d[0] + '.' + d[1] + '.' + d[2] + d[3];
                return t;
            });
        criteria = Array.from(new Set(criteria));
        if (!criteria.length) return level ? ('WCAG ' + level) : src;
        return criteria.join(', ') + (level ? (' (' + level + ')') : '');
    }

    function groupReviewItems(items) {
        var grouped = {};
        (items || []).forEach(function (it) {
            var title = String(it.title || 'Automated Issue').trim();
            var rule = String(it.rule_id || '').trim();
            var impact = String(it.impact || '').trim().toLowerCase();
            var wcag = String(it.wcag || '').trim();
            var source = String(it.source_url || '').trim();
            // Keep grouping tight to avoid unrelated findings being merged together.
            var key = [title.toLowerCase(), rule.toLowerCase(), impact, wcag.toLowerCase()].join('||');
            if (!grouped[key]) {
                grouped[key] = {
                    primary_id: String(it.id),
                    ids: [],
                    title: title,
                    source_url: source,
                    rule_id: rule,
                    impact: impact || '-',
                    wcag: wcag,
                    severity: mapImpactToSeverity(impact),
                    description_text: '',
                    details: '',
                    instances: [],
                    recommendation: '',
                    source_urls: []
                };
            }
            grouped[key].ids.push(String(it.id));
            if (source && grouped[key].source_urls.indexOf(source) === -1) grouped[key].source_urls.push(source);
            extractSourceUrlsFromDetails(it.details || '').forEach(function (u) {
                if (grouped[key].source_urls.indexOf(u) === -1) grouped[key].source_urls.push(u);
            });
            var cleanedInstance = enrichInstanceWithName(it.instance || '', it.incorrect_code || '');
            if (cleanedInstance && grouped[key].instances.indexOf(cleanedInstance) === -1) grouped[key].instances.push(cleanedInstance);
            var desc = String(it.description_text || stripHtml(it.details || '') || '').trim();
            if (desc) {
                if (!grouped[key].description_text) grouped[key].description_text = desc;
                else if (grouped[key].description_text.indexOf(desc) === -1) grouped[key].description_text += ' | ' + desc;
            }
            var rec = String(it.recommendation || '').trim();
            if (rec && !grouped[key].recommendation) grouped[key].recommendation = rec;
        });
        return Object.keys(grouped).map(function (k) { return grouped[k]; });
    }

    function buildReviewEditIssueFromIds(rawIds) {
        var ids = normalizeIdList(rawIds);
        var all = (issueData.selectedPageId && issueData.pages[issueData.selectedPageId]) ? (issueData.pages[issueData.selectedPageId].review || []) : [];
        var matched = all.filter(function (x) { return ids.indexOf(String(x.id)) !== -1; });
        if (!matched.length && ids.length) {
            matched = all.filter(function (x) { return String(x.id) === String(ids[0]); });
        }
        if (!matched.length) return null;
        var first = matched[0];
        var urls = [];
        var instances = [];
        var entryRows = [];
        var incorrectCodes = [];
        var screenshots = [];
        matched.forEach(function (x) {
            var u = String(x.source_url || '').trim();
            if (!u) {
                var m = String(x.description_text || x.details || '').match(/URL\s+\d+\s*:\s*(https?:\/\/\S+)/i);
                if (m && m[1]) u = String(m[1]).trim();
            }
            if (u && urls.indexOf(u) === -1) urls.push(u);
            var iv = formatInstanceReadable(enrichInstanceWithName(x.instance || '', x.incorrect_code || ''));
            if (iv && instances.indexOf(iv) === -1) instances.push(iv);
            if (iv) {
                entryRows.push({
                    url: u || '',
                    instance: iv,
                    failure: formatFailureSummaryText(x.failure_summary || extractLabeledValue(String(x.details || ''), 'Failure') || '')
                });
            }
            var codeVal = String(x.incorrect_code || extractLabeledValue(String(x.details || ''), 'Incorrect Code') || '').trim();
            extractIncorrectCodeSnippets(codeVal).forEach(function (snippet) {
                if (snippet && incorrectCodes.indexOf(snippet) === -1) incorrectCodes.push(snippet);
            });
            var shotVal = String(extractLabeledValue(String(x.details || ''), 'Screenshots') || '').trim();
            normalizeScreenshotList(shotVal, []).forEach(function (s) {
                if (screenshots.indexOf(s) === -1) screenshots.push(s);
            });
        });
        extractSourceUrlsFromDetails(first.details).forEach(function (u) {
            if (urls.indexOf(u) === -1) urls.push(u);
        });
        var instanceLines = instances.map(function (p, idx) { return '- Instance ' + (idx + 1) + ': ' + p; }).join('\n');
        var detailsOut = buildSectionedReviewDetails(first.details || '', urls, instances, first, entryRows, incorrectCodes, screenshots);
        return {
            id: String(first.id),
            ids: ids,
            title: first.title || 'Automated Issue',
            instance: instanceLines || first.instance || '',
            source_urls: urls,
            source_url: (urls[0] || String(first.source_url || '').trim() || ''),
            wcag: first.wcag || '',
            severity: first.severity || 'medium',
            details: detailsOut,
            rule_id: first.rule_id || '',
            impact: first.impact || ''
        };
    }

    function renderReviewPagination(totalItems) {
        var el = document.getElementById('reviewPagination');
        if (!el) return;
        if (!totalItems || totalItems <= reviewPageSize) {
            el.innerHTML = '';
            return;
        }
        var totalPages = Math.ceil(totalItems / reviewPageSize);
        var prevDisabled = reviewCurrentPage <= 1 ? ' disabled' : '';
        var nextDisabled = reviewCurrentPage >= totalPages ? ' disabled' : '';
        el.innerHTML =
            '<div class="d-flex justify-content-between align-items-center small text-muted">' +
            '<div>Showing ' + (((reviewCurrentPage - 1) * reviewPageSize) + 1) + '-' + Math.min(reviewCurrentPage * reviewPageSize, totalItems) + ' of ' + totalItems + '</div>' +
            '<div class="btn-group btn-group-sm">' +
            '<button type="button" class="btn btn-outline-secondary" data-review-page="prev"' + prevDisabled + '>Prev</button>' +
            '<button type="button" class="btn btn-outline-secondary disabled">Page ' + reviewCurrentPage + ' / ' + totalPages + '</button>' +
            '<button type="button" class="btn btn-outline-secondary" data-review-page="next"' + nextDisabled + '>Next</button>' +
            '</div>' +
            '</div>';
    }

    function updateSingleIssueRow(issueId, issueDataObj) {
        // Find the existing row in the table
        var tbody = document.getElementById('finalIssuesBody');
        if (!tbody) return;
        
        // Find the issue data
        var issue = null;
        if (issueData.selectedPageId && issueData.pages[issueData.selectedPageId].final) {
            issue = issueData.pages[issueData.selectedPageId].final.find(function(i) {
                return String(i.id) === String(issueId);
            });
        }
        
        if (!issue) {
            // If issue not found, fall back to full re-render
            renderFinalIssues();
            return;
        }
        
        // Find existing rows (main row and details row)
        var existingMainRow = null;
        var existingDetailsRow = null;
        var allRows = tbody.querySelectorAll('tr');
        
        for (var i = 0; i < allRows.length; i++) {
            var row = allRows[i];
            // Check if this is the main row by looking for edit button with matching data-id
            var editBtn = row.querySelector('.final-edit[data-id="' + issueId + '"]');
            if (editBtn) {
                existingMainRow = row;
                // The next row should be the details row
                if (i + 1 < allRows.length) {
                    var nextRow = allRows[i + 1];
                    if (nextRow.id === 'issue-details-' + issueId) {
                        existingDetailsRow = nextRow;
                    }
                }
                break;
            }
        }
        
        if (!existingMainRow) {
            // If row not found, fall back to full re-render
            renderFinalIssues();
            return;
        }
        
        // Check if details row was expanded
        var wasExpanded = existingDetailsRow && existingDetailsRow.classList.contains('show');

            // Generate new row HTML using the same logic as renderFinalIssues
            var stripHtml = function (html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html || '';
                return tmp.textContent || tmp.innerText || '';
            };

            // Extract values and handle arrays OR stringified arrays
            var severity = issue.severity || 'N/A';
            if (typeof severity === 'string' && severity.startsWith('[')) {
                try {
                    var parsed = JSON.parse(severity);
                    if (Array.isArray(parsed)) {
                        severity = parsed[0] || 'N/A';
                    }
                } catch (e) { }
            } else if (Array.isArray(severity)) {
                severity = severity[0] || 'N/A';
            }

            var priority = issue.priority || 'N/A';
            if (typeof priority === 'string' && priority.startsWith('[')) {
                try {
                    var parsed = JSON.parse(priority);
                    if (Array.isArray(parsed)) {
                        priority = parsed[0] || 'N/A';
                    }
                } catch (e) { }
            } else if (Array.isArray(priority)) {
                priority = priority[0] || 'N/A';
            }

            var status = issue.status_name || issue.status || 'open';
            var statusId = issue.status_id || null;
            var qaStatusHtml = getReporterQaStatusHtml(issue);

            // Handle multiple reporters
            var reportersArray = Array.isArray(issue.reporters) && issue.reporters.length > 0
                ? issue.reporters
                : (issue.reporter_name ? [issue.reporter_name] : []);

            var reporterHtml = '';
            if (reportersArray.length > 0) {
                reporterHtml = reportersArray.map(function (reporterId) {
                    var reporterName = 'Unknown';
                    if (ProjectConfig.projectUsers) {
                        var found = ProjectConfig.projectUsers.find(function (u) {
                            return u.id == reporterId;
                        });
                        if (found) {
                            reporterName = found.full_name;
                        }
                    }
                    return '<span class="badge bg-info me-1">' + escapeHtml(reporterName) + '</span>';
                }).join('');
            } else {
                reporterHtml = '<span class="text-muted">N/A</span>';
            }

            var qaName = issue.qa_name || 'N/A';
            var issueKey = issue.issue_key || 'N/A';
            var pageCount = (issue.pages && issue.pages.length) || 1;
            var titlePreview = stripHtml(issue.details).substring(0, 100);
            if (titlePreview && stripHtml(issue.details).length > 100) titlePreview += '...';
            var uniqueId = 'issue-details-' + issue.id;
            var testerDeleteBlocked = !!(isTesterRole && issue.can_tester_delete === false);
            var deleteTitle = testerDeleteBlocked
                ? 'Testers cannot delete this issue because it has comments or QA status is set on an Open issue.'
                : 'Delete Issue';

            // Create new main row HTML
            var mainRowHtml = '';

            // Checkbox column - hide for client
            if (userRole !== 'client') {
                mainRowHtml += '<td class="checkbox-cell"><input type="checkbox" class="final-select" value="' + issue.id + '"' + (testerDeleteBlocked ? ' disabled' : '') + '></td>';
            }

            mainRowHtml += '<td><span class="badge bg-primary">' + escapeHtml(issueKey) + '</span></td>' +
                '<td style="min-width: 250px; max-width: 400px;">' +
                '<div class="d-flex align-items-center">' +
                '<button class="btn btn-link p-0 me-2 text-muted chevron-toggle-btn" ' +
                'data-collapse-target="#' + uniqueId + '" ' +
                'aria-label="Expand details for ' + escapeHtml(issueKey) + ': ' + escapeHtml(issue.title) + '" ' +
                'style="border: none; background: none; font-size: 1rem;">' +
                '<i class="fas fa-chevron-' + (wasExpanded ? 'down' : 'right') + ' chevron-icon"></i>' +
                '</button>' +
                '<div style="cursor: pointer;" class="issue-title-click" data-issue-id="' + issue.id + '">' +
                (issue.common_title ?
                    '<div class="fw-bold">' + escapeHtml(issue.common_title) + '</div>' +
                    '<div class="small text-muted">' + escapeHtml(issue.title) + '</div>'
                    :
                    '<div>' + escapeHtml(issue.title) + '</div>' +
                    (titlePreview ? '<div class="small text-muted">' + escapeHtml(titlePreview) + '</div>' : '')
                ) +
                '</div>' +
                '</div>' +
                '</td>' +
                '<td>' + getSeverityBadge(severity) + '</td>' +
                '<td>' + getPriorityBadge(priority) + '</td>' +
                '<td>' + getStatusBadge(statusId, status) + '</td>';

            // QA Status, Reporter, QA Name, Client Ready columns - hide for client
            if (userRole !== 'client') {
                mainRowHtml += '<td>' + qaStatusHtml + '</td>' +
                    '<td>' + reporterHtml + '</td>' +
                    '<td>' +
                    (qaName !== 'N/A' ?
                        '<span class="badge bg-success">' + escapeHtml(qaName) + '</span>' :
                        '<span class="text-muted">N/A</span>') +
                    '</td>' +
                    '<td>' +
                    (issue.client_ready == 1 ?
                        '<span class="badge bg-success"><i class="fas fa-check"></i> Yes</span>' :
                        '<span class="badge bg-secondary"><i class="fas fa-times"></i> No</span>') +
                    '</td>';
            }

            mainRowHtml += '<td>' +
                '<span class="badge bg-secondary">' + pageCount + ' page(s)</span>' +
                '</td>';

            // Actions column
            if (userRole !== 'client') {
                mainRowHtml += '<td class="action-buttons-cell">' +
                    '<button class="btn btn-sm btn-outline-primary me-1 final-edit" data-id="' + issue.id + '" type="button" title="Edit Issue">' +
                    '<i class="fas fa-edit"></i>' +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger final-delete" data-id="' + issue.id + '" type="button" title="' + escapeAttr(deleteTitle) + '"' + (testerDeleteBlocked ? ' disabled' : '') + '>' +
                    '<i class="fas fa-trash"></i>' +
                    '</button>' +
                    '</td>';
            } else {
                mainRowHtml += '<td class="action-buttons-cell">' +
                    '<button class="btn btn-sm btn-outline-primary issue-open" data-id="' + issue.id + '" type="button" title="Update status or add comment">' +
                    '<i class="fas fa-pen-to-square me-1"></i>Update' +
                    '</button>' +
                    '</td>';
            }

            // Update the main row content
            existingMainRow.innerHTML = mainRowHtml;
            existingMainRow.className = 'align-middle issue-expandable-row';
            existingMainRow.setAttribute('data-collapse-target', '#' + uniqueId);
            existingMainRow.style.cursor = 'pointer';

            // Create new details row HTML
            var detailsRowHtml = '<td colspan="9" class="p-0 border-0">' +
                '<div class="bg-light p-4 border-top">' +
                '<div class="row g-3">' +
                '<div class="col-md-8">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
                '<div class="card">' +
                '<div class="card-body issue-content">' +
                (decorateIssueImages(issue.details) || '<p class="text-muted">No details provided.</p>') +
                '</div>' +
                '</div>' +
                '</div>' +
                '<div class="col-md-4">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                '<div class="mb-2"><strong>Issue Key:</strong><br>' +
                '<span class="badge bg-primary">' + escapeHtml(issueKey) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Status:</strong><br>' + getStatusBadge(statusId, status) + '</div>' +
                (userRole !== 'client' ? '<div class="mb-2"><strong>QA Status:</strong><br>' + qaStatusHtml + '</div>' : '') +
                '<div class="mb-2"><strong>Severity:</strong><br>' +
                '<span class="badge bg-warning text-dark">' + escapeHtml((severity || 'N/A').toUpperCase()) + '</span>' +
                '</div>' +
                '<div class="mb-2"><strong>Priority:</strong><br>' +
                '<span class="badge bg-info text-dark">' + escapeHtml((priority || 'N/A').toUpperCase()) + '</span>' +
                '</div>' +
                (userRole !== 'client' ? '<div class="mb-2"><strong>Reporter(s):</strong><br>' +
                (reportersArray.length > 0 ? reportersArray.map(function (reporterId) {
                    var reporterName = 'Unknown';
                    if (ProjectConfig.projectUsers) {
                        var found = ProjectConfig.projectUsers.find(function (u) { return u.id == reporterId; });
                        if (found) reporterName = found.full_name;
                    }
                    return escapeHtml(reporterName);
                }).join(', ') : (issue.reporter_name ? escapeHtml(issue.reporter_name) : '<span class="text-muted">N/A</span>')) +
                '</div>' : '') +
                (userRole !== 'client' ? '<div class="mb-2"><strong>QA Name:</strong><br>' + escapeHtml(qaName) + '</div>' : '') +
                (function () {
                    // Pages section with names
                    var pagesHtml = '<div class="mb-2"><strong>Pages:</strong> ';
                    if (issue.pages && issue.pages.length > 0) {
                        var pageNames = issue.pages.map(function (pageId) {
                            return getPageName(pageId);
                        });
                        pagesHtml += '<span class="badge bg-secondary">' + pageNames.length + '</span><br>';
                        pagesHtml += '<small class="text-muted">' + pageNames.join(', ') + '</small>';
                    } else {
                        pagesHtml += '<span class="text-muted">N/A</span>';
                    }
                    pagesHtml += '</div>';

                    // Grouped URLs section with expand/collapse
                    var urlsHtml = '';
                    if (issue.grouped_urls && issue.grouped_urls.length > 0) {
                        var urlsId = 'urls-' + issue.id;
                        urlsHtml += '<div class="mb-2">';
                        urlsHtml += '<strong>Grouped URLs:</strong> ';
                        urlsHtml += '<span class="badge bg-info">' + issue.grouped_urls.length + '</span> ';
                        urlsHtml += '<button class="btn btn-xs btn-link p-0 grouped-urls-toggle" data-bs-toggle="collapse" data-bs-target="#' + urlsId + '" aria-expanded="false">';
                        urlsHtml += '<i class="fas fa-chevron-down transition-transform"></i>';
                        urlsHtml += '</button>';
                        urlsHtml += '<div class="mt-2" id="' + urlsId + '" style="display: none;">';
                        urlsHtml += '<div class="small p-2 border rounded bg-light" style="max-height: 150px; overflow-y: auto;">';

                        var urlsFound = 0;
                        issue.grouped_urls.forEach(function (urlString) {
                            var urlData = (ProjectConfig.groupedUrls || []).find(function (u) {
                                return u.url === urlString || u.normalized_url === urlString;
                            });

                            var displayUrl = urlData ? urlData.url : urlString;

                            if (displayUrl) {
                                urlsFound++;
                                urlsHtml += '<div class="mb-1">';
                                urlsHtml += '<a href="' + escapeHtml(displayUrl) + '" target="_blank" class="text-decoration-none">';
                                urlsHtml += '<i class="fas fa-external-link-alt me-1 text-primary"></i>';
                                urlsHtml += '<span class="text-primary">' + escapeHtml(displayUrl) + '</span>';
                                urlsHtml += '</a>';
                                urlsHtml += '</div>';
                            }
                        });

                        if (urlsFound === 0) {
                            urlsHtml += '<div class="text-muted">No URL data available</div>';
                        }

                        urlsHtml += '</div></div></div>';
                    }

                    if (userRole === 'client') {
                        return '<div class="mb-2"><strong>Page:</strong><br><small class="text-muted">' + escapeHtml(getPageName(issueData.selectedPageId || (issue.pages && issue.pages[0]) || '')) + '</small></div>';
                    }

                    return pagesHtml + urlsHtml;
                })() +
                (function () {
                    if (userRole === 'client') {
                        return buildClientQuickStatusActions(issue) +
                            '<div class="small text-muted mt-3">Use comments below to share regression details and screenshots.</div>';
                    }

                    var metaHtml = '';
                    if (typeof issueMetadataFields !== 'undefined') {
                        issueMetadataFields.forEach(function (f) {
                            if (f.field_key === 'severity' || f.field_key === 'priority') return;

                            var value = issue[f.field_key];
                            if (value && value.length > 0) {
                                var displayValue = Array.isArray(value) ? value.join(', ') : value;
                                metaHtml += '<div class="mb-2"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                            }
                        });
                    }
                    if (userRole !== 'client') {
                        if (issue.created_at) {
                            metaHtml += '<div class="mb-2"><strong>Created:</strong><br><small class="text-muted">' + new Date(issue.created_at).toLocaleString() + '</small></div>';
                        }
                        if (issue.updated_at) {
                            metaHtml += '<div class="mb-2"><strong>Updated:</strong><br><small class="text-muted">' + new Date(issue.updated_at).toLocaleString() + '</small></div>';
                        }
                    }
                    return metaHtml;
                })() +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</td>';

            // Update or create details row
            if (existingDetailsRow) {
                existingDetailsRow.innerHTML = detailsRowHtml;
                // Restore expanded state
                if (wasExpanded) {
                    existingDetailsRow.classList.add('show');
                } else {
                    existingDetailsRow.classList.remove('show');
                }
            } else {
                // Create new details row
                var newDetailsRow = document.createElement('tr');
                newDetailsRow.className = 'collapse' + (wasExpanded ? ' show' : '');
                newDetailsRow.id = uniqueId;
                newDetailsRow.innerHTML = detailsRowHtml;
                existingMainRow.parentNode.insertBefore(newDetailsRow, existingMainRow.nextSibling);
            }

            // Re-attach event handlers for the updated row
            attachRowEventHandlers(existingMainRow);

            // Update issue tab counts
            updateIssueTabCounts();
        }

        // Helper function to attach event handlers to a specific row
        function attachRowEventHandlers(mainRow) {
            // Chevron toggle button
            var chevronBtn = mainRow.querySelector('.chevron-toggle-btn');
            if (chevronBtn) {
                chevronBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    toggleIssueRow(this);
                });

                chevronBtn.addEventListener('keydown', function (e) {
                    if (e.keyCode === 13 || e.keyCode === 32) {
                        e.preventDefault();
                        e.stopPropagation();
                        toggleIssueRow(this);
                    }
                });
            }

            // Issue title click
            var titleEl = mainRow.querySelector('.issue-title-click');
            if (titleEl) {
                titleEl.addEventListener('click', function (e) {
                    e.stopPropagation();

                    if (userRole === 'client') {
                        var issueId = this.getAttribute('data-issue-id');
                        if (issueId && issueData.selectedPageId) {
                            var clientIssue = issueData.pages[issueData.selectedPageId].final.find(function (i) {
                                return String(i.id) === String(issueId);
                            });
                            if (clientIssue) openFinalEditor(clientIssue);
                        }
                    } else {
                        var issueId = this.getAttribute('data-issue-id');
                        if (issueId && issueData.selectedPageId) {
                            var issue = issueData.pages[issueData.selectedPageId].final.find(function (i) {
                                return String(i.id) === String(issueId);
                            });
                            if (issue) openFinalEditor(issue);
                        }
                    }
                });
            }

            mainRow.querySelectorAll('.client-quick-status').forEach(function (button) {
                button.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    quickUpdateClientIssueStatus(this.getAttribute('data-issue-id'), this.getAttribute('data-status-id'), this);
                });
            });

            // Row click handler
            mainRow.addEventListener('click', function (e) {
                if (e.target.closest('button') ||
                    e.target.closest('input') ||
                    e.target.closest('select') ||
                    e.target.closest('.action-buttons-cell') ||
                    e.target.closest('.checkbox-cell') ||
                    e.target.closest('.issue-title-click')) {
                    return;
                }

                var chevronBtn = this.querySelector('.chevron-toggle-btn');
                if (chevronBtn) {
                    toggleIssueRow(chevronBtn);
                }
            });

            // Helper function to toggle issue row expansion (local version)
            function toggleIssueRow(btn) {
                var targetId = btn.getAttribute('data-collapse-target');
                if (targetId) {
                    var collapseEl = document.querySelector(targetId);
                    var chevronIcon = btn.querySelector('.chevron-icon');

                    if (collapseEl) {
                        var isExpanded = collapseEl.classList.contains('show');

                        if (isExpanded) {
                            collapseEl.classList.remove('show');
                            if (chevronIcon) chevronIcon.className = 'fas fa-chevron-right chevron-icon';
                        } else {
                            collapseEl.classList.add('show');
                            if (chevronIcon) chevronIcon.className = 'fas fa-chevron-down chevron-icon';
                        }
                    }
                }
            }

            // Add handlers for images and grouped URLs in details row
            setTimeout(function () {
                var detailsRow = mainRow.nextElementSibling;
                if (detailsRow && detailsRow.classList.contains('collapse')) {
                    // Image click handlers
                    detailsRow.querySelectorAll('img').forEach(function (img) {
                        img.style.cursor = 'pointer';
                        img.addEventListener('click', function (e) {
                            e.stopPropagation();
                            var imgSrc = this.src;
                            var imgAlt = this.alt || '';

                            var modal = document.getElementById('issueImageModal');
                            var previewImg = document.getElementById('issueImagePreview');
                            var altTextDiv = document.getElementById('issueImageAltText');
                            var altTextContent = document.getElementById('issueImageAltTextContent');

                            if (modal && previewImg) {
                                previewImg.src = imgSrc;
                                previewImg.alt = imgAlt;

                                if (imgAlt && altTextDiv && altTextContent) {
                                    altTextContent.textContent = imgAlt;
                                    altTextDiv.style.display = 'block';
                                } else if (altTextDiv) {
                                    altTextDiv.style.display = 'none';
                                }

                                var bsModal = new bootstrap.Modal(modal);
                                bsModal.show();
                            }
                        });
                    });

                    detailsRow.querySelectorAll('.issue-image-thumb').forEach(function (img) {
                        img.style.cursor = 'pointer';
                        img.removeEventListener('click', img._imageClickHandler);

                        img._imageClickHandler = function (e) {
                            e.stopPropagation();
                            e.preventDefault();
                            var src = this.getAttribute('src');
                            var alt = this.getAttribute('alt') || '';
                            if (src) openIssueImageModal(src, alt);
                        };

                        img.addEventListener('click', img._imageClickHandler);
                    });

                    // Grouped URLs toggle handlers
                    detailsRow.querySelectorAll('.grouped-urls-toggle').forEach(function (toggleBtn) {
                        toggleBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            e.stopPropagation();

                            var targetId = this.getAttribute('data-bs-target');
                            if (targetId) {
                                var collapseEl = document.querySelector(targetId);
                                var chevron = this.querySelector('i');

                                if (collapseEl) {
                                    var isHidden = collapseEl.style.display === 'none';

                                    if (isHidden) {
                                        collapseEl.style.display = 'block';
                                        if (chevron) {
                                            chevron.classList.remove('fa-chevron-down');
                                            chevron.classList.add('fa-chevron-up');
                                        }
                                        this.setAttribute('aria-expanded', 'true');
                                    } else {
                                        collapseEl.style.display = 'none';
                                        if (chevron) {
                                            chevron.classList.remove('fa-chevron-up');
                                            chevron.classList.add('fa-chevron-down');
                                        }
                                        this.setAttribute('aria-expanded', 'false');
                                    }
                                }
                            }
                        });
                    });
                }
            }, 100);
        }    function addNewIssueRow(issueDataObj) {
        // For new issues, just re-render the table
        // The new issue will appear at the top due to the unshift() in the save function
        renderFinalIssues();
    }

    function renderCommonIssues() {
        var tbody = document.getElementById('commonIssuesBody');
        if (!tbody) return;
        
        var userRole = (window.ProjectConfig ? window.ProjectConfig.userRole : '');
        var colspan = (userRole === 'client') ? 3 : 5;

        var commonIssues = (window.issueData && Array.isArray(window.issueData.common)) ? window.issueData.common : [];

        if (commonIssues.length === 0) {
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-5 text-muted">' + 
                (userRole === 'client' ? 'No shared issues available right now.' : 'No common issues found.') + 
                '</td></tr>';
            return;
        }

        tbody.innerHTML = commonIssues.map(function (it) {
            var uniqueId = 'common-issue-details-' + it.id;
            var rawDesc = it.description || it.details || '';
            var descriptionPreview = stripHtml(rawDesc).substring(0, 100);
            if (descriptionPreview && stripHtml(rawDesc).length > 100) descriptionPreview += '...';
            
            var severity = it.severity || 'N/A';
            var priority = it.priority || 'N/A';
            var status = it.status_name || it.status || 'open';
            var statusId = it.status_id || null;
            var qaStatusHtml = getReporterQaStatusHtml(it);
            var pageCount = (it.pages || []).length;
            var displayKey = it.issue_key || it.key || ((window.ProjectConfig ? window.ProjectConfig.projectCode : 'ISS') + '-' + (it.issue_id || it.id));

            var mainRow = '<tr class="align-middle issue-expandable-row" data-collapse-target="#' + uniqueId + '" style="cursor: pointer;">';
            
            // 1. Checkbox (non-client)
            if (userRole !== 'client') {
                mainRow += '<td class="text-center checkbox-cell" style="width: 40px;">' +
                    '<input type="checkbox" class="form-check-input common-select" data-id="' + it.id + '">' +
                    '</td>';
            }

            // 2. Issue Key
            mainRow += '<td style="width: 115px;"><span class="badge bg-primary">' + escapeHtml(displayKey) + '</span></td>';

            // 3. Title + Description
            mainRow += '<td>' +
                '<div class="d-flex align-items-center">' +
                '<div class="me-2 text-muted" style="width: 20px;"><i class="fas fa-chevron-right chevron-icon"></i></div>' +
                '<div>' +
                '<div class="fw-bold text-dark text-truncate-cell" title="' + escapeAttr(it.common_title || it.title) + '">' + escapeHtml(it.common_title || it.title) + '</div>' +
                (it.common_title && it.common_title !== it.title ? '<div class="small text-muted text-truncate-cell" title="' + escapeAttr(it.title) + '">Issue Title: ' + escapeHtml(it.title) + '</div>' : '') +
                (descriptionPreview ? '<div class="small text-muted text-truncate-cell" title="' + escapeAttr(stripHtml(rawDesc)) + '">' + escapeHtml(descriptionPreview) + '</div>' : '') +
                '</div>' +
                '</div>' +
                '</td>';

            // 4. Page(s)
            mainRow += '<td style="width: 125px;"><span class="badge bg-secondary">' + pageCount + ' page(s)</span></td>';

            // 5. Actions (non-client)
            if (userRole !== 'client') {
                mainRow += '<td class="text-end action-buttons-cell" style="width: 105px;">' +
                    '<div class="btn-group">' +
                    '<button class="btn btn-sm btn-outline-primary common-edit bg-white" data-id="' + it.id + '" title="Edit Common Issue"><i class="fas fa-pencil-alt"></i></button>' +
                    '<button class="btn btn-sm btn-outline-danger common-delete bg-white" data-id="' + it.id + '" title="Delete Common Issue"><i class="fas fa-trash"></i></button>' +
                    '</div>' +
                    '</td>';
            }

            mainRow += '</tr>';

            // Details Row (Expanded) - Matching Issues All Style
            var detailsRow = '<tr class="collapse" id="' + uniqueId + '">' +
                '<td colspan="' + colspan + '" class="p-0 border-0">' +
                '<div class="bg-light p-4 border-top">' +
                '<div class="row g-3">' +
                // Left Column: Details/Description
                '<div class="col-md-8">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
                '<div class="card">' +
                '<div class="card-body issue-content">' + (decorateIssueImages(rawDesc) || '<p class="text-muted">No details provided.</p>') + '</div>' +
                '</div>' +
                '</div>' +
                // Right Column: Metadata
                '<div class="col-md-4">' +
                '<h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6>' +
                '<div class="card">' +
                '<div class="card-body">' +
                '<div class="mb-2"><strong>Issue Key:</strong><br><span class="badge bg-primary">' + escapeHtml(displayKey) + '</span></div>' +
                '<div class="mb-2"><strong>Status:</strong><br>' + getStatusBadge(statusId, status) + '</div>' +
                (userRole !== 'client' ? '<div class="mb-2"><strong>QA Status:</strong><br>' + qaStatusHtml + '</div>' : '') +
                '<div class="mb-2"><strong>Severity:</strong><br><span class="badge bg-warning text-dark">' + escapeHtml((severity || 'N/A').toUpperCase()) + '</span></div>' +
                '<div class="mb-2"><strong>Priority:</strong><br><span class="badge bg-info text-dark">' + escapeHtml((priority || 'N/A').toUpperCase()) + '</span></div>' +
                (userRole !== 'client' ? '<div class="mb-2"><strong>Reporter(s):</strong><br>' + (it.reporters ? escapeHtml(it.reporters) : '<span class="text-muted">N/A</span>') + '</div>' : '') +
                    (function() {
                        // Pages section
                        var pagesHtml = '<div class="mb-2"><strong>Pages:</strong> <span class="badge bg-secondary ms-1">' + pageCount + '</span>' +
                            '<div class="mt-1 border rounded bg-white p-2" style="max-height:120px;overflow-y:auto;">' +
                            '<ul class="list-unstyled mb-0 small">';
                        (it.pages || []).forEach(function(pageId) {
                            pagesHtml += '<li><i class="fas fa-file-alt text-muted me-1"></i>' + escapeHtml(getPageName(pageId)) + '</li>';
                        });
                        pagesHtml += '</ul></div></div>';

                        // Grouped URLs section
                        if (it.grouped_urls && it.grouped_urls.length > 0) {
                            pagesHtml += '<div class="mb-2"><strong>Grouped URLs:</strong> <span class="badge bg-info ms-1">' + it.grouped_urls.length + '</span>' +
                                '<button class="btn btn-link p-0 ms-1 text-primary grouped-urls-toggle" style="font-size:12px;text-decoration:none;" data-bs-target="#common-grouped-urls-list-' + it.id + '"><small>Show/Hide</small></button>' +
                                '<div id="common-grouped-urls-list-' + it.id + '" class="mt-1 border rounded bg-white p-2" style="display:none; max-height:150px; overflow-y:auto;">' +
                                '<ul class="list-unstyled mb-0 small" style="word-break: break-all;">';
                            it.grouped_urls.forEach(function (url) {
                                pagesHtml += '<li class="mb-1 pb-1 border-bottom last-child-border-0">' +
                                    '<i class="fas fa-link text-muted me-1"></i>' +
                                    '<a href="' + escapeAttr(url) + '" target="_blank" class="text-decoration-none">' + escapeHtml(url) + '</a>' +
                                    '</li>';
                            });
                            pagesHtml += '</ul></div></div>';
                        }

                        return pagesHtml;
                    })() +
                (function () {
                    var metaHtml = '';
                    if (typeof issueMetadataFields !== 'undefined') {
                        issueMetadataFields.forEach(function (f) {
                            if (f.field_key === 'severity' || f.field_key === 'priority') return;
                            var value = (it.metadata && it.metadata[f.field_key]) || it[f.field_key];
                            if (value && value.length > 0) {
                                var displayValue = Array.isArray(value) ? value.join(', ') : value;
                                metaHtml += '<div class="mb-2 small"><strong>' + escapeHtml(f.field_label) + ':</strong> ' + escapeHtml(displayValue) + '</div>';
                            }
                        });
                    }
                    if (userRole !== 'client' && it.updated_at) {
                        metaHtml += '<div class="mt-3 pt-2 border-top small text-muted">Updated: ' + new Date(it.updated_at).toLocaleString() + '</div>';
                    }
                    return metaHtml;
                })() +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</td>' +
                '</tr>';

            return mainRow + detailsRow;
        }).join('');

        // Re-attach listeners for expandable behavior
        document.querySelectorAll('#commonIssuesBody .issue-expandable-row').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('button') || e.target.closest('input') || e.target.closest('.action-buttons-cell')) return;
                var targetId = this.getAttribute('data-collapse-target');
                var detailsRow = document.querySelector(targetId);
                var chevron = this.querySelector('.chevron-icon');
                if (!detailsRow) return;
                
                if (detailsRow.classList.contains('show')) {
                    detailsRow.classList.remove('show');
                    if (chevron) { chevron.classList.remove('fa-chevron-down'); chevron.classList.add('fa-chevron-right'); }
                } else {
                    detailsRow.classList.add('show');
                    if (chevron) { chevron.classList.remove('fa-chevron-right'); chevron.classList.add('fa-chevron-down'); }
                }
            });
        });

        // Attach action listeners
        document.querySelectorAll('.common-select').forEach(function(el) { el.addEventListener('click', function(e) { e.stopPropagation(); }); });

        document.querySelectorAll('.common-edit').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                if (window.editCommonIssue) {
                    window.editCommonIssue(this.dataset.id);
                }
            });
        });

        document.querySelectorAll('.common-delete').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                var id = this.dataset.id;
                if (!canEdit()) return;
                if (!confirm('Delete this common issue? This cannot be undone.')) return;
                var fd = new FormData();
                fd.append('action', 'delete');
                fd.append('project_id', projectId);
                fd.append('ids', id);
                fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.success) {
                            if (typeof showToast === 'function') showToast('Common issue deleted.', 'success');
                            if (window.loadCommonIssues) window.loadCommonIssues({ preserveFilters: true, keepPage: true });
                        } else {
                            if (typeof showToast === 'function') showToast((res && res.error) ? res.error : 'Failed to delete.', 'danger');
                        }
                    })
                    .catch(function() {
                        if (typeof showToast === 'function') showToast('Failed to delete.', 'danger');
                    });
            });
        });


        document.dispatchEvent(new CustomEvent('pms:issueTableUpdated'));
    }

    function renderAll() { renderFinalIssues(); renderCommonIssues(); updateSelectionButtons(); }

    function renderIssuePresence(users) {
        var el = document.getElementById('finalIssuePresenceIndicator');
        if (!el) return;
        if (!document.getElementById('issuePresenceAvatarStyle')) {
            var st = document.createElement('style');
            st.id = 'issuePresenceAvatarStyle';
            st.textContent =
                '.presence-user{position:relative;display:inline-flex;outline:none;}' +
                '.presence-user .presence-avatar{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:999px;color:#fff;font-size:10px;font-weight:700;border:2px solid #fff;box-shadow:0 0 0 1px rgba(13,110,253,.15);margin-left:-6px;}' +
                '.presence-user .presence-user-name{position:absolute;left:50%;transform:translateX(-50%);bottom:calc(100% + 6px);' +
                'white-space:nowrap;background:#212529;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;line-height:1.2;' +
                'box-shadow:0 6px 16px rgba(0,0,0,.2);opacity:0;pointer-events:none;transition:opacity .12s ease;z-index:20;}' +
                '.presence-user .presence-user-name:after{content:"";position:absolute;left:50%;transform:translateX(-50%);top:100%;' +
                'border:5px solid transparent;border-top-color:#212529;}' +
                '.presence-user:hover .presence-user-name,.presence-user:focus .presence-user-name,.presence-user:focus-visible .presence-user-name{opacity:1;}' +
                '.presence-user:focus-visible .presence-avatar{box-shadow:0 0 0 2px #0d6efd,0 0 0 4px #fff;}';
            document.head.appendChild(st);
        }

        var currentUserId = String(ProjectConfig.userId || '');
        var activeUsers = Array.isArray(users) ? users : [];

        if (!activeUsers.length) {
            issuePresenceRenderSignature = '';
            el.className = '';
            el.textContent = '';
            return;
        }

        var getInitials = function (name) {
            var parts = String(name || 'User').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) return 'U';
            if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
            return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        };

        var palette = ['#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#0dcaf0', '#dc3545', '#20c997', '#6610f2'];
        var colorForId = function (id) {
            var n = Math.abs(parseInt(String(id || '0'), 10) || 0);
            return palette[n % palette.length];
        };

        var displayUsers = activeUsers
            .map(function (u) {
                var uid = String(u.user_id || '');
                var name = (uid === currentUserId) ? 'You' : String(u.full_name || 'User');
                return { id: uid, name: name, initials: getInitials(name), color: colorForId(uid) };
            });

        var nextSignature = JSON.stringify(displayUsers.map(function (u) {
            return { id: u.id, name: u.name, initials: u.initials, color: u.color };
        }));
        if (issuePresenceRenderSignature === nextSignature) return;
        issuePresenceRenderSignature = nextSignature;

        var focusedUserId = null;
        var activeEl = document.activeElement;
        if (activeEl && el.contains(activeEl)) {
            var focusedUser = activeEl.closest('.presence-user');
            if (focusedUser) focusedUserId = String(focusedUser.getAttribute('data-user-id') || '');
        }

        var avatarHtml = displayUsers.map(function (u) {
            return '<span class="presence-user" tabindex="0" data-user-id="' + escapeAttr(u.id) + '" aria-label="' + escapeAttr(u.name) + '">' +
                '<span class="presence-avatar" style="background:' + u.color + ';">' +
                escapeHtml(u.initials) + '</span>' +
                '<span class="presence-user-name">' + escapeHtml(u.name) + '</span>' +
                '</span>';
        }).join('');

        el.className = 'small mt-1';
        el.innerHTML =
            '<div class="d-flex flex-wrap align-items-center gap-2">' +
            '<span class="text-muted">Active users:</span>' +
            '<span style="display:inline-flex;padding-left:6px;">' + avatarHtml + '</span>' +
            '</div>';

        if (focusedUserId) {
            var nextFocusEl = el.querySelector('.presence-user[data-user-id="' + escapeAttr(focusedUserId) + '"]');
            if (nextFocusEl) {
                try { nextFocusEl.focus(); } catch (e) { }
            }
        }
    }

    async function pingIssuePresence(issueId) {
        if (!issueId) return;
        try {
            var fd = new FormData();
            fd.append('action', 'presence_ping');
            fd.append('project_id', projectId);
            fd.append('issue_id', issueId);
            if (issuePresenceSessionToken) fd.append('session_token', issuePresenceSessionToken);
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            if (!res.ok) {
                renderIssuePresence([]);
                return;
            }
            var json = await res.json();
            if (json && json.success) {
                renderIssuePresence(json.users || []);
            } else {
                renderIssuePresence([]);
            }
        } catch (e) {
            renderIssuePresence([]);
        }
    }

    function stopIssuePresenceTracking() {
        if (issuePresenceTimer) {
            clearInterval(issuePresenceTimer);
            issuePresenceTimer = null;
        }
        var issueId = issuePresenceIssueId;
        issuePresenceIssueId = null;
        if (!issueId) {
            renderIssuePresence([]);
            return;
        }
        try {
            var fd = new FormData();
            fd.append('action', 'presence_leave');
            fd.append('project_id', projectId);
            fd.append('issue_id', issueId);
            if (issuePresenceSessionToken) fd.append('session_token', issuePresenceSessionToken);
            fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { }
        issuePresenceSessionToken = null;
        renderIssuePresence([]);
    }

    async function openIssuePresenceSession(issueId) {
        issuePresenceSessionToken = null;
        try {
            var fd = new FormData();
            fd.append('action', 'presence_open_session');
            fd.append('project_id', projectId);
            fd.append('issue_id', issueId);
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            if (!res.ok) return;
            var json = await res.json();
            if (json && json.success && json.session_token) {
                issuePresenceSessionToken = json.session_token;
            }
        } catch (e) { }
    }

    async function startIssuePresenceTracking(issueId) {
        stopIssuePresenceTracking();
        if (!issueId) {
            var el = document.getElementById('finalIssuePresenceIndicator');
            if (el) {
                el.className = 'small mt-1 text-muted';
                el.textContent = 'New issue mode (not yet shared).';
            }
            return;
        }
        var labelEl = document.getElementById('finalIssuePresenceIndicator');
        if (labelEl) {
            labelEl.className = 'small mt-1 text-muted';
            labelEl.textContent = 'Checking active users...';
        }
        issuePresenceIssueId = String(issueId);
        await openIssuePresenceSession(issuePresenceIssueId);
        pingIssuePresence(issuePresenceIssueId);
        issuePresenceTimer = setInterval(function () {
            if (document.hidden || !issuePresenceIssueId) return;
            pingIssuePresence(issuePresenceIssueId);
        }, ISSUE_PRESENCE_PING_MS);
    }

    function leaveIssuePresenceOnUnload() {
        if (!issuePresenceIssueId) return;
        try {
            var payload = new URLSearchParams();
            payload.append('action', 'presence_leave');
            payload.append('project_id', String(projectId));
            payload.append('issue_id', String(issuePresenceIssueId));
            if (issuePresenceSessionToken) payload.append('session_token', String(issuePresenceSessionToken));
            if (navigator.sendBeacon) {
                navigator.sendBeacon(issuesApiBase, payload);
            } else {
                fetch(issuesApiBase, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }
                });
            }
        } catch (e) { }
    }

    function isIssueEditorOpen() {
        var modal = document.getElementById('finalIssueModal');
        return !!(modal && modal.classList.contains('show'));
    }

    function updateSelectionButtons() {
        var finalChecked = document.querySelectorAll('.final-select:checked').length;
        var finalDelete = document.getElementById('finalDeleteSelected');
        if (finalDelete) finalDelete.disabled = !finalChecked || !canEdit();
        
        var finalMarkClientReady = document.getElementById('finalMarkClientReadyBtn');
        if (finalMarkClientReady) finalMarkClientReady.disabled = !finalChecked || !canEdit();
    }

    // Event delegation for grouped URLs toggle - instant, no setTimeout delay
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.grouped-urls-toggle');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        var targetId = btn.getAttribute('data-bs-target');
        if (targetId) {
            var el = document.querySelector(targetId);
            if (el) el.style.display = (el.style.display === 'none' || !el.style.display) ? 'block' : 'none';
        }
    });

    // Global image click delegation for image previews
    document.addEventListener('click', function(e) {
        var target = e.target;
        if (target.tagName === 'IMG' && (target.classList.contains('issue-image-thumb') || target.closest('#finalIssuesBody') || target.closest('.message-content'))) {
            var src = target.getAttribute('src') || target.src;
            var alt = target.getAttribute('alt') || target.alt || '';
            if (src) {
                e.preventDefault();
                e.stopPropagation();
                openIssueImageModal(src, alt);
            }
        }
    });

    document.addEventListener('error', function (e) {
        var target = e.target;
        if (!target || target.tagName !== 'IMG') return;
        if (!(target.classList.contains('issue-image-thumb') || target.classList.contains('editable-issue-image'))) return;
        if (tryRecoverIssueImageElement(target)) {
            e.preventDefault();
            return;
        }
    }, true);

    function getPageName(id) { var p = (pages || []).find(function (x) { return String(x.id) === String(id); }); return p ? p.page_name : id; }
    // escapeHtml defined at top of scope
    // stripHtml, getSeverityBadge, getPriorityBadge, getStatusBadge defined earlier in scope

    function extractAltText(html) { if (!html) return ''; var matches = []; var re = /<img[^>]*alt=['"]([^'"]*)['"][^>]*>/gi; var m; while ((m = re.exec(html)) !== null) { if (m[1] && matches.indexOf(m[1]) === -1) matches.push(m[1]); } return matches.join(' | '); }
    function decorateIssueImages(html) { 
        if (!html) return ''; 
        return String(html).replace(/<img\b([^>]*)>/gi, function (_, attrs) { 
            let newAttrs = attrs;

            newAttrs = newAttrs.replace(/\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i, function (match, dq, sq, bare) {
                var currentSrc = dq || sq || bare || '';
                return 'src="' + escapeAttr(normalizeIssueImageSrc(currentSrc)) + '"';
            });
            
            // Add issue-image-thumb class
            if (/class\s*=/.test(attrs)) { 
                newAttrs = attrs.replace(/class\s*=(["\'])([^"\']*)\1/, 'class="$2 issue-image-thumb"'); 
            } else {
                newAttrs = 'class="issue-image-thumb" ' + attrs;
            }
            
            // Add lazy loading if not present
            if (!/loading\s*=/.test(newAttrs)) {
                newAttrs += ' loading="lazy"';
            }
            
            return '<img ' + newAttrs + '>'; 
        }); 
    }
    function openIssueImageModal(src, alt) {
        var m = document.getElementById('issueImageModal');
        var i = document.getElementById('issueImagePreview');
        if (!m || !i) return;
        
        i.src = normalizeIssueImageSrc(src || '');
        i.alt = alt || '';
        
        var altTextDiv = document.getElementById('issueImageAltText');
        var altTextContent = document.getElementById('issueImageAltTextContent');
        if (altTextDiv && altTextContent) {
            if (alt && alt.trim()) {
                altTextContent.textContent = alt;
                altTextDiv.style.display = 'block';
            } else {
                altTextDiv.style.display = 'none';
            }
        }
        
        var modalInstance = bootstrap.Modal.getOrCreateInstance(m);
        
        // Add one-time listener to clean up any potential backdrop issues
        m.addEventListener('hidden.bs.modal', function () {
            // Bootstrap should handle this, but if multiple modals were stacked, 
            // ensure the backdrop is truly gone.
            if (!document.querySelector('.modal.show')) {
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        }, { once: true });
        
        modalInstance.show();
    }

    function renderIssueComments(issueId) {
        var listEl = document.getElementById('finalIssueCommentsList');
        if (!listEl) return;
        var items = issueData.comments[issueId || 'new'] || [];
        
        // Filter comments for client role - show only regression comments
        if (userRole === 'client') {
            items = items.filter(function(c) {
                return c.comment_type === 'regression';
            });
        }
        
        updateIssueCommentCount(items);

        if (!items.length) {
            listEl.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-comments fa-3x mb-3 opacity-25"></i><p>No comments yet. Start the conversation!</p></div>';
            return;
        }

        var currentUserId = String(ProjectConfig.userId || '');
        var displayItems = items.slice().reverse();

        listEl.innerHTML = displayItems.map(function (c, idx) {
            var isOwn = String(c.user_id) === currentUserId;
            var isRegression = (c.comment_type === 'regression');
            var isDeleted = !!c.deleted_at;
            var canEdit = !!c.can_edit;
            var canDelete = !!c.can_delete;
            var canViewHistory = !!c.can_view_history && isAdminUser;
            var userName = String(c.user_name || 'User');
            var userInitials = userName
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map(function (part) { return part.charAt(0); })
                .join('')
                .toUpperCase() || 'U';
            var userChipLabel = isOwn ? 'You' : userInitials;

            var commentText = decorateIssueImages(c.text || '');
            commentText = commentText.replace(/@([A-Za-z0-9._-]+)/g, '<span class="badge bg-warning text-dark">@$1</span>');

            var replyPreview = '';
            if (c.reply_to && c.reply_preview) {
                var rp = c.reply_preview;
                var replyText = (rp.text || '').replace(/<[^>]*>/g, '').substring(0, 80);
                if (rp.text && rp.text.length > 80) replyText += '...';
                replyPreview = '<div class="reply-preview mb-2 p-2 rounded" style="background: #f8f9fa; border-left: 3px solid #0d6efd;">' +
                    '<div class="d-flex align-items-center mb-1">' +
                    '<i class="fas fa-reply text-primary me-2" style="font-size: 0.75rem;"></i>' +
                    '<small class="text-muted fw-bold">Replying to ' + escapeHtml(rp.user_name || 'User') + '</small>' +
                    '</div>' +
                    '<small class="text-muted" style="font-style: italic;">' + escapeHtml(replyText) + '</small>' +
                    '</div>';
            }

            var bgClass = '';
            var borderStyle = '';
            var regressionHeading = '';
            var roleHeading = '';
            if (isRegression) {
                bgClass = '';
                borderStyle = 'background: #e7f3ff !important; border-left: 3px solid #0d6efd;';
                regressionHeading = '<div class="mb-2 pb-2 border-bottom" style="border-color: #b6d4fe !important;">' +
                    '<span class="badge" style="background: #0d6efd; font-size: 0.75rem;">' +
                    '<i class="fas fa-retweet me-1"></i>Regression Comment' +
                    '</span>' +
                    '</div>';
            } else if (isOwn) {
                bgClass = 'bg-primary-subtle';
            } else {
                bgClass = 'bg-light';
            }

            if (userRole === 'client') {
                roleHeading = '';
                regressionHeading = '';
                borderStyle += 'border-radius: 14px; box-shadow: 0 8px 20px rgba(13,110,253,0.08);';
            }

            var regressionBadge = (isRegression && userRole !== 'client') ? '<span class="badge bg-info ms-2" style="font-size: 0.65rem;"><i class="fas fa-retweet me-1"></i>Regression</span>' : '';
            var editedBadge = c.edited_at ? '<small class="text-muted">(edited)</small>' : '';
            var actionButtons = '';
            if (!isDeleted) {
                actionButtons += '<button type="button" class="message-action-btn issue-comment-reply" ' +
                    'title="Reply" aria-label="Reply" ' +
                    'data-comment-id="' + (c.id || idx) + '" ' +
                    'data-user-name="' + escapeAttr(userName) + '" ' +
                    'data-comment-text="' + escapeAttr((c.text || '').replace(/<[^>]*>/g, '').substring(0, 100)) + '">' +
                    '<i class="fas fa-reply"></i></button>';
            }
            if (canEdit) {
                actionButtons += '<button type="button" class="message-action-btn issue-comment-edit" title="Edit" aria-label="Edit" data-comment-id="' + (c.id || idx) + '" data-comment-html="' + escapeAttr(c.text || '') + '"><i class="fas fa-edit"></i></button>';
            }
            if (canDelete) {
                actionButtons += '<button type="button" class="message-action-btn text-danger issue-comment-delete" title="Delete" aria-label="Delete" data-comment-id="' + (c.id || idx) + '"><i class="fas fa-trash"></i></button>';
            }
            if (canViewHistory) {
                actionButtons += '<button type="button" class="message-action-btn issue-comment-history" title="History" aria-label="History" data-comment-id="' + (c.id || idx) + '"><i class="fas fa-history"></i></button>';
            }

            return '<div class="message ' + (isOwn ? 'own-message' : 'other-message') + ' mb-3" data-comment-id="' + (c.id || idx) + '">' +
                '<div class="message-main">' +
                '<div class="message-meta-row">' +
                '<div class="message-meta-left">' +
                regressionBadge +
                '</div>' +
                '<div class="message-meta-right">' +
                '<small class="text-muted">' + escapeHtml(c.time || '') + '</small>' + editedBadge +
                '<div class="message-action-row">' + actionButtons + '</div>' +
                '<span class="message-author-chip" title="' + escapeAttr(isOwn ? 'You' : userName) + '" aria-label="' + escapeAttr(isOwn ? 'You' : userName) + '">' + escapeHtml(userChipLabel) + '</span>' +
                '</div>' +
                '</div>' +
                replyPreview +
                '<div class="message-content p-2 rounded ' + bgClass + '" style="' + borderStyle + '">' +
                roleHeading +
                regressionHeading +
                commentText +
                '</div>' +
                '</div>' +
                '</div>';
        }).join('');

        requestAnimationFrame(function () {
            try {
                listEl.scrollTop = listEl.scrollHeight;
            } catch (e) { }
        });

        document.querySelectorAll('.issue-comment-reply').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var commentId = this.getAttribute('data-comment-id');
                var userName = this.getAttribute('data-user-name');
                var commentText = this.getAttribute('data-comment-text');
                showReplyPreview(commentId, userName, commentText);
            });
        });

        document.querySelectorAll('.issue-comment-edit').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var commentId = this.getAttribute('data-comment-id');
                editIssueComment(commentId);
            });
        });

        document.querySelectorAll('.issue-comment-delete').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var commentId = this.getAttribute('data-comment-id');
                deleteIssueComment(commentId);
            });
        });

        document.querySelectorAll('.issue-comment-history').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var commentId = this.getAttribute('data-comment-id');
                showIssueCommentHistory(commentId);
            });
        });
    }

    function showIssueCommentDeleteConfirmation(callback) {
        var modalHtml = `
            <div class="modal fade" id="issueCommentDeleteConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-danger-subtle">
                            <h5 class="modal-title">
                                <i class="fas fa-trash text-danger me-2"></i>
                                Delete Comment
                            </h5>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0">Delete this comment? This action cannot be undone.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" id="issueCommentDeleteCancel">Cancel</button>
                            <button type="button" class="btn btn-danger" id="issueCommentDeleteConfirm">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        var existing = document.getElementById('issueCommentDeleteConfirmModal');
        if (existing) {
            try {
                var existingInst = bootstrap.Modal.getInstance(existing);
                if (existingInst) existingInst.dispose();
            } catch (e) { }
            existing.remove();
            cleanupModalOverlayState();
        }

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var modalEl = document.getElementById('issueCommentDeleteConfirmModal');
        var modal = new bootstrap.Modal(modalEl);

        document.getElementById('issueCommentDeleteConfirm').addEventListener('click', function () {
            modal.hide();
            callback(true);
        });

        document.getElementById('issueCommentDeleteCancel').addEventListener('click', function () {
            modal.hide();
            callback(false);
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            modalEl.remove();
            cleanupModalOverlayState();
        });

        modal.show();
    }

    function updateIssueCommentCount(items) {
        var titleBadge = document.getElementById('finalIssueCommentCountInTitle');
        var tabBadge = document.getElementById('finalIssueCommentCountBadge');
        if (!titleBadge && !tabBadge) return;
        var list = Array.isArray(items) ? items : [];
        var visibleCount = list.filter(function (it) { return !it || !it.deleted_at; }).length;
        var countText = String(visibleCount);
        if (titleBadge) titleBadge.textContent = countText + (visibleCount === 1 ? ' Comment' : ' Comments');
        if (tabBadge) tabBadge.textContent = countText;
    }

    function extractMentionUserIdsFromHtml(html) {
        var mentions = [];
        var mentionRegex = /@([A-Za-z0-9._-]+)/g;
        var match;
        while ((match = mentionRegex.exec(String(html || ''))) !== null) {
            var username = match[1];
            var users = ProjectConfig.projectUsers || [];
            var user = users.find(function (u) {
                var uUsername = String(u.username || '').toLowerCase();
                var uFullNameAsUser = String(u.full_name || '').toLowerCase().replace(/\s+/g, '');
                var target = String(username || '').toLowerCase();
                return uUsername === target || uFullNameAsUser === target;
            });
            if (user && mentions.indexOf(user.id) === -1) {
                mentions.push(user.id);
            }
        }
        return mentions;
    }

    async function submitIssueComment(issueId, html, commentType, replyTo, options) {
        var opts = options || {};
        var key = String(issueId || '').trim();
        var rawHtml = String(html || '');
        var plain = rawHtml.replace(/<[^>]*>/g, '').trim();
        if (!key || !plain) return false;

        var mentions = extractMentionUserIdsFromHtml(rawHtml);
        var fd = new FormData();
        fd.append('action', 'create');
        fd.append('project_id', projectId);
        fd.append('issue_id', key);
        fd.append('comment_html', rawHtml);
        fd.append('comment_type', (commentType || 'normal'));
        fd.append('mentions', JSON.stringify(mentions));
        if (replyTo) fd.append('reply_to', String(replyTo));

        try {
            var response = await fetch(issueCommentsApi, { method: 'POST', body: fd, credentials: 'same-origin' });
            var res = await response.json();
            if (!res || res.error) {
                if (!opts.silent && typeof showToast === 'function') {
                    showToast((res && res.error) ? res.error : 'Failed to add comment', 'danger');
                }
                return false;
            }

            if (!issueData.comments[key]) issueData.comments[key] = [];
            if (res.comment) {
                upsertIssueComment(key, res.comment);
            } else {
                issueData.comments[key].unshift({
                    id: res.id || ('tmp_' + Date.now()),
                    user_id: ProjectConfig.userId,
                    user_name: 'You',
                    text: rawHtml,
                    time: new Date().toLocaleString(),
                    reply_to: replyTo || null,
                    comment_type: commentType || 'normal',
                    can_edit: true,
                    can_delete: true,
                    can_view_history: isAdminUser
                });
            }

            if (opts.clearEditor) {
                if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueCommentEditor').summernote('code', '');
                var commentTypeEl = document.getElementById('finalIssueCommentType');
                if (commentTypeEl) commentTypeEl.value = (userRole === 'client' ? 'regression' : 'normal');
                var previewEl = document.getElementById('issueCommentReplyPreview');
                if (previewEl) previewEl.style.display = 'none';
                var replyToEl = document.getElementById('replyToCommentId');
                if (replyToEl) replyToEl.value = '';
                updateFinalIssueCommentCharCount();
            }

            renderIssueComments(key);
            return true;
        } catch (err) {
            if (!opts.silent && typeof showToast === 'function') showToast('Failed to add comment', 'danger');
            return false;
        }
    }

    function upsertIssueComment(issueId, updatedComment) {
        var key = String(issueId || '');
        if (!key || !updatedComment || !updatedComment.id) return;
        if (!issueData.comments[key]) issueData.comments[key] = [];
        var list = issueData.comments[key];
        var idNum = Number(updatedComment.id);
        var idx = list.findIndex(function (it) { return Number(it.id) === idNum; });
        var mapped = {
            id: updatedComment.id,
            user_id: updatedComment.user_id,
            user_name: updatedComment.user_name,
            qa_status: updatedComment.qa_status_name || '',
            text: updatedComment.comment_html,
            time: updatedComment.created_at,
            reply_to: updatedComment.reply_to || null,
            reply_preview: updatedComment.reply_preview || null,
            comment_type: updatedComment.comment_type || 'normal',
            edited_at: updatedComment.edited_at || null,
            deleted_at: updatedComment.deleted_at || null,
            can_edit: !!updatedComment.can_edit,
            can_delete: !!updatedComment.can_delete,
            can_view_history: !!updatedComment.can_view_history
        };
        if (idx >= 0) list[idx] = mapped;
        else list.unshift(mapped);
    }

    function editIssueComment(commentId) {
        var issueId = document.getElementById('finalIssueEditId').value || 'new';
        if (!issueId || issueId === 'new') return;
        var key = String(issueId);
        var list = issueData.comments[key] || [];
        var item = list.find(function (it) { return Number(it.id) === Number(commentId); });
        if (!item) return;

        // Create a proper modal for editing instead of using window.prompt
        var modalHtml = `
            <div class="modal fade" id="editCommentModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Comment</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="editCommentText" class="form-label">Comment Text</label>
                                <textarea class="form-control" id="editCommentText" rows="4" placeholder="Enter your comment...">${escapeHtml(item.text || '')}</textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="saveEditCommentBtn">Save Changes</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if any
        var existingModal = document.getElementById('editCommentModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        var modalEl = document.getElementById('editCommentModal');
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
        
        // Focus on textarea
        setTimeout(function() {
            var textarea = document.getElementById('editCommentText');
            if (textarea) {
                textarea.focus();
                textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            }
        }, 300);
        
        // Handle save button
        document.getElementById('saveEditCommentBtn').addEventListener('click', function() {
            var editedText = document.getElementById('editCommentText').value.trim();
            
            if (!editedText) {
                if (typeof showToast === 'function') showToast('Comment cannot be empty', 'warning');
                return;
            }
            
            // Disable save button to prevent double-click
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            
            var fd = new FormData();
            fd.append('action', 'edit');
            fd.append('project_id', projectId);
            fd.append('issue_id', key);
            fd.append('comment_id', String(commentId));
            fd.append('comment_html', editedText);
            
            fetch(issueCommentsApi, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || res.error || !res.comment) {
                        if (typeof showToast === 'function') showToast((res && res.error) ? res.error : 'Failed to edit comment', 'danger');
                        return;
                    }
                    upsertIssueComment(key, res.comment);
                    renderIssueComments(key);
                    if (typeof showToast === 'function') showToast('Comment updated', 'success');
                    modal.hide();
                })
                .catch(function () {
                    if (typeof showToast === 'function') showToast('Failed to edit comment', 'danger');
                })
                .finally(function() {
                    // Re-enable save button
                    var saveBtn = document.getElementById('saveEditCommentBtn');
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = 'Save Changes';
                    }
                });
        });
        
        // Clean up modal after it's hidden
        modalEl.addEventListener('hidden.bs.modal', function() {
            modalEl.remove();
        });
        
        // Handle Enter key to save (Ctrl+Enter or Shift+Enter)
        document.getElementById('editCommentText').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.shiftKey)) {
                e.preventDefault();
                document.getElementById('saveEditCommentBtn').click();
            }
        });
    }

    function deleteIssueComment(commentId) {
        var issueId = document.getElementById('finalIssueEditId').value || 'new';
        if (!issueId || issueId === 'new') return;
        var key = String(issueId);
        showIssueCommentDeleteConfirmation(function (confirmed) {
            if (!confirmed) return;

            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('project_id', projectId);
            fd.append('issue_id', key);
            fd.append('comment_id', String(commentId));
            fetch(issueCommentsApi, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || res.error) {
                        if (typeof showToast === 'function') showToast((res && res.error) ? res.error : 'Failed to delete comment', 'danger');
                        return;
                    }
                    if (res.comment) {
                        upsertIssueComment(key, res.comment);
                    } else {
                        loadIssueComments(key);
                        return;
                    }
                    renderIssueComments(key);
                    if (typeof showToast === 'function') showToast('Comment deleted', 'success');
                })
                .catch(function () {
                    if (typeof showToast === 'function') showToast('Failed to delete comment', 'danger');
                });
        });
    }

    function showIssueCommentHistory(commentId) {
        if (!isAdminUser) return;
        var issueId = document.getElementById('finalIssueEditId').value || 'new';
        if (!issueId || issueId === 'new') return;
        var key = String(issueId);

        var modalId = 'issueCommentHistoryModal';
        var bodyId = 'issueCommentHistoryBody';
        var modalEl = document.getElementById(modalId);
        if (!modalEl) {
            var html = '<div class="modal fade" id="' + modalId + '" tabindex="-1" aria-hidden="true">' +
                '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
                '<div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title">Comment History</h5>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
                '<div class="modal-body" id="' + bodyId + '"><p class="text-muted mb-0">Loading...</p></div>' +
                '</div></div></div>';
            document.body.insertAdjacentHTML('beforeend', html);
            modalEl = document.getElementById(modalId);
        }
        var bodyEl = document.getElementById(bodyId);
        if (bodyEl) bodyEl.innerHTML = '<p class="text-muted mb-0">Loading...</p>';

        var url = issueCommentsApi + '?action=history&project_id=' + encodeURIComponent(projectId) +
            '&issue_id=' + encodeURIComponent(key) + '&comment_id=' + encodeURIComponent(commentId);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!bodyEl) return;
                if (!res || !res.success) {
                    bodyEl.innerHTML = '<p class="text-danger mb-0">Failed to load history.</p>';
                    return;
                }
                var rows = res.history || [];
                if (!rows.length) {
                    bodyEl.innerHTML = '<p class="text-muted mb-0">No history available.</p>';
                    return;
                }
                var out = '<div class="list-group">';
                rows.forEach(function (h) {
                    out += '<div class="list-group-item">';
                    out += '<div class="d-flex justify-content-between mb-2">';
                    out += '<strong>' + escapeHtml(String(h.action_type || '').toUpperCase()) + '</strong>';
                    out += '<small class="text-muted">' + escapeHtml(h.acted_at || '') + ' by ' + escapeHtml(h.acted_by_name || 'Unknown') + '</small>';
                    out += '</div>';
                    out += '<div class="small text-muted mb-1">Old</div><div class="border rounded p-2 mb-2">' + (h.old_comment_html || '') + '</div>';
                    out += '<div class="small text-muted mb-1">New</div><div class="border rounded p-2">' + (h.new_comment_html || '') + '</div>';
                    out += '</div>';
                });
                out += '</div>';
                bodyEl.innerHTML = out;
            })
            .catch(function () {
                if (bodyEl) bodyEl.innerHTML = '<p class="text-danger mb-0">Failed to load history.</p>';
            });

        if (window.bootstrap && modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    function showReplyPreview(commentId, userName, commentText) {
        setFinalIssueComposeExpanded(true, { focus: false });

        // Create or update reply preview
        var previewEl = document.getElementById('issueCommentReplyPreview');
        if (!previewEl) {
            var editorEl = document.querySelector('#finalIssueCommentEditor');
            var editorWrap = editorEl ? (editorEl.closest('.mb-3') || editorEl.closest('.client-chat-editor-wrap') || editorEl.parentElement) : null;
            if (!editorWrap) return;

            var previewHtml = '<div id="issueCommentReplyPreview" class="alert alert-info mb-3" style="display:none; background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%); border: 1px solid #b6d4fe; border-left: 4px solid #0d6efd;">' +
                '<div class="d-flex align-items-start">' +
                '<div class="flex-shrink-0">' +
                '<i class="fas fa-reply text-primary me-2" style="font-size: 1.1rem; margin-top: 2px;"></i>' +
                '</div>' +
                '<div class="flex-grow-1">' +
                '<div class="fw-bold text-primary mb-1">' +
                'Replying to <span id="replyUserName" class="text-decoration-underline"></span>' +
                '</div>' +
                '<div class="small text-muted" id="replyCommentText" style="font-style: italic; padding-left: 0.25rem; border-left: 2px solid #dee2e6;"></div>' +
                '</div>' +
                '<button type="button" class="btn-close ms-2" id="cancelReply" aria-label="Cancel" style="font-size: 0.75rem;"></button>' +
                '</div>' +
                '<input type="hidden" id="replyToCommentId" value="">' +
                '</div>';

            editorWrap.insertAdjacentHTML('afterbegin', previewHtml);
            previewEl = document.getElementById('issueCommentReplyPreview');

            // Add cancel handler
            document.getElementById('cancelReply').addEventListener('click', function () {
                previewEl.style.display = 'none';
                document.getElementById('replyToCommentId').value = '';
            });
        }

        // Update preview content
        document.getElementById('replyUserName').textContent = userName;
        document.getElementById('replyCommentText').textContent = commentText;
        document.getElementById('replyToCommentId').value = commentId;
        previewEl.style.display = 'block';

        // Smooth scroll to editor
        previewEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Focus editor after a short delay
        setTimeout(function () {
            focusFinalIssueCommentEditor();
        }, 300);
    }

    function addIssueComment(issueId) {
        var key = issueId || 'new';
        if (key === 'new') { issueNotify('Please save the issue before adding chat.', 'warning'); return; }
        var html = (window.jQuery && jQuery.fn.summernote) ? jQuery('#finalIssueCommentEditor').summernote('code') : document.getElementById('finalIssueCommentEditor').value;
        if (!String(html || '').replace(/<[^>]*>/g, '').trim()) return;

        // Get comment type
        var commentTypeEl = document.getElementById('finalIssueCommentType');
        var commentType = commentTypeEl ? commentTypeEl.value : 'normal';
        if (userRole === 'client') {
            commentType = 'regression';
        }

        // Get reply_to if exists
        var replyToEl = document.getElementById('replyToCommentId');
        var replyTo = replyToEl ? replyToEl.value : '';

        submitIssueComment(key, html, commentType, replyTo, { clearEditor: true });
    }

    function loadIssueComments(issueId) {
        if (!issueId) return;
        fetch(issueCommentsApi + '?action=list&project_id=' + encodeURIComponent(projectId) + '&issue_id=' + encodeURIComponent(issueId), { credentials: 'same-origin' }).then(r => r.json()).then(function (res) {
            if (res && res.comments) {
                issueData.comments[String(issueId)] = res.comments.map(function (c) {
                    return {
                        id: c.id,
                        user_id: c.user_id,
                        user_name: c.user_name,
                        qa_status: c.qa_status_name || '',
                        text: c.comment_html,
                        time: c.created_at,
                        reply_to: c.reply_to || null,
                        reply_preview: c.reply_preview || null,
                        comment_type: c.comment_type || 'normal',
                        edited_at: c.edited_at || null,
                        deleted_at: c.deleted_at || null,
                        can_edit: !!c.can_edit,
                        can_delete: !!c.can_delete,
                        can_view_history: !!c.can_view_history
                    };
                });
                renderIssueComments(String(issueId));
            }
        });
    }

    function applyPreset(preset) {
        if (!preset) return;
        jQuery('#finalIssueTitle').val(preset.name).trigger('change');
        if (window.jQuery && jQuery.fn.summernote) {
            jQuery('#finalIssueDetails').summernote('code', preset.description_html || preset.description || '');
        }

        var meta = {};
        if (preset.metadata_json) {
            if (typeof preset.metadata_json === 'string') {
                try { meta = JSON.parse(preset.metadata_json) || {}; } catch (e) { meta = {}; }
            } else if (typeof preset.metadata_json === 'object') {
                meta = preset.metadata_json || {};
            }
        } else if (preset.meta_json) {
            try { meta = JSON.parse(preset.meta_json) || {}; } catch (e) { meta = {}; }
        }

        var sev = (meta.severity || preset.severity || 'medium');
        var pri = (meta.priority || preset.priority || 'medium');
        toggleFinalIssueFields(true);
        var $s = jQuery('#finalIssueField_severity'); if ($s.length) $s.val(sev.toLowerCase()).trigger('change');
        var $p = jQuery('#finalIssueField_priority'); if ($p.length) $p.val(pri.toLowerCase()).trigger('change');

        Object.keys(meta).forEach(function (k) {
            if (['status', 'qa_status', 'pages', 'reporters', 'grouped_urls', 'common_title'].indexOf(k) !== -1) return;
            var dynId = 'finalIssueField_' + k;
            var field = document.getElementById(dynId);
            if (field) {
                var val = meta[k];
                if (Array.isArray(val)) {
                    jQuery(field).val(val).trigger('change');
                } else {
                    jQuery(field).val(val != null ? [String(val)] : []).trigger('change');
                }
            }
        });
        setTimeout(enableToolbarKeyboardA11y, 0);
        setTimeout(enableToolbarKeyboardA11y, 200);
    }

    function renderSectionButtons(sections) {
        var wrap = document.getElementById('finalIssueSectionButtons');
        if (!wrap) return;
        wrap.innerHTML = '';
        (sections || []).forEach(function (s) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-secondary'; btn.textContent = s;
            btn.addEventListener('click', function () {
                if (window.jQuery && jQuery.fn.summernote) jQuery('#finalIssueDetails').summernote('pasteHTML', '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p>');
            });
            wrap.appendChild(btn);
        });
    }

    function ensureDefaultSections() {
        if (!defaultSections.length) return;
        if (window.jQuery && jQuery.fn.summernote) {
            var cur = jQuery('#finalIssueDetails').summernote('code');
            var plain = String(cur || '').replace(/<[^>]*>/g, '').trim();
            if (plain) return;
            var html = defaultSections.map(function (s) { return '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p>'; }).join('');
            jQuery('#finalIssueDetails').summernote('code', html);
        }
    }

    function setDefaultSectionsInEditor() {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        if (!defaultSections.length) {
            issueNotify('No default template sections configured for this project type.', 'warning');
            return;
        }
        var html = defaultSections.map(function (s) {
            return '<p style="margin-bottom:0;"><strong>[' + escapeHtml(s) + ']</strong></p><p><br></p><p><br></p>';
        }).join('');
        jQuery('#finalIssueDetails').summernote('code', html);
    }

    function resetToTemplateWithConfirm() {
        if (!window.jQuery || !jQuery.fn.summernote) return;
        var cur = jQuery('#finalIssueDetails').summernote('code');
        var plain = String(cur || '').replace(/<[^>]*>/g, '').trim();
        if (plain && !window.confirm('This will replace the current content with the default template. Continue?')) {
            return;
        }
        clearIssueMetadataForTemplateReset();
        if (defaultSections.length) {
            setDefaultSectionsInEditor();
            return;
        }
        fetch(issueTemplatesApi + '?action=list&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                defaultSections = (res && res.default_sections) ? res.default_sections : [];
                setDefaultSectionsInEditor();
            })
            .catch(function () {
                issueNotify('Failed to load template sections. Please try again.', 'danger');
            });
    }

    function loadTemplates() {
        if (!issueTemplatesApi) return;
        fetch(issueTemplatesApi + '?action=list&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                issuePresets = res.templates || [];
                defaultSections = res.default_sections || [];
                var sel = document.getElementById('finalIssueTitle');
                if (sel) {
                    // Professional Select2 setup with custom template and fallback
                    if (window.jQuery && jQuery.fn.select2) {
                        // Rebuild Select2 cleanly after replacing the options.
                        try {
                            if (jQuery(sel).data('select2')) {
                                jQuery(sel).select2('destroy');
                            }
                            jQuery(sel).empty();
                            jQuery(sel).append('<option value="">Select preset or type title...</option>');
                            (issuePresets || []).forEach(function (t) {
                                jQuery(sel).append('<option value="PRESET:' + t.id + '">' + t.name + '</option>');
                            });
                        } catch (e) { }
                        jQuery(sel).select2({
                            tags: true,
                            theme: 'bootstrap-5',
                            placeholder: 'Select preset or type title...',
                            dropdownParent: jQuery('#finalIssueModal'),
                            width: '100%',
                            templateResult: function (data) {
                                if (data.loading) return data.text;
                                if (data.id && String(data.id).startsWith('PRESET:')) {
                                    return '<span class="text-primary fw-bold"><i class="fas fa-star me-1"></i>' + data.text + '</span>';
                                }
                                return '<span>' + data.text + '</span>';
                            },
                            templateSelection: function (data) {
                                return data.text;
                            },
                            escapeMarkup: function (m) { return m; }
                        }).on('change', function () {
                            var val = jQuery(this).val();
                            if (val && typeof val === 'string' && val.indexOf('PRESET:') === 0) {
                                var pid = val.split(':')[1];
                                var preset = issuePresets.find(function (p) { return String(p.id) === String(pid); });
                                if (preset) applyPreset(preset);
                            }
                        });
                        // Trigger change on modal open (no auto-focus)
                        jQuery('#finalIssueModal').on('shown.bs.modal', function () {
                            setTimeout(function () {
                                jQuery(sel).trigger('change.select2');
                            }, 300);
                        });
                    } else {
                        // Fallback: datalist input
                        try {
                            sel.innerHTML = '';
                            var container = sel.parentElement;
                            var input = document.createElement('input');
                            input.type = 'text'; input.id = 'finalIssueTitleInput'; input.className = 'form-control form-control-lg';
                            input.placeholder = 'Type issue title...';
                            var dl = document.createElement('datalist'); dl.id = 'finalIssueTitleList';
                            issuePresets.forEach(function (t) { var o = document.createElement('option'); o.value = t.name; dl.appendChild(o); });
                            container.replaceChild(input, sel);
                            container.appendChild(dl);
                            input.setAttribute('list', dl.id);
                        } catch (e) { }
                    }
                }
                renderSectionButtons(defaultSections);
            });
    }

    function applyMetadataOptions(fields) {
        if (!fields || !fields.length) return;
        var container = document.getElementById('finalIssueMetadataContainer');
        if (!container) return;
        container.innerHTML = '';
        fields.forEach(function (f) {
            var label = document.createElement('label'); label.className = 'form-label mt-2'; label.textContent = f.field_label; container.appendChild(label);
            var select = document.createElement('select'); select.className = 'form-select form-select-sm issue-dynamic-field issue-select2-tags';
            select.id = 'finalIssueField_' + f.field_key; select.multiple = true;
            
            // Handle both old format (array of strings) and new format (array of objects)
            var options = f.options || [];
            options.forEach(function (o) {
                if (typeof o === 'string') {
                    // Old format: just a string
                    select.appendChild(new Option(o, o));
                } else if (o && typeof o === 'object') {
                    // New format: object with option_label and option_value
                    select.appendChild(new Option(o.option_label, o.option_value));
                }
            });
            
            container.appendChild(select);
        });
        if (window.jQuery && jQuery.fn.select2) {
            jQuery('.issue-dynamic-field.issue-select2-tags').select2({ width: '100%', tags: true, tokenSeparators: [','], dropdownParent: jQuery('#finalIssueModal') });
        }
    }

    // Resolves when issueMetadataFields is populated; resolves immediately if already loaded.
    var _metadataReadyResolvers = [];
    var _metadataReady = false;
    function onMetadataReady(fn) {
        if (_metadataReady) { fn(); return; }
        _metadataReadyResolvers.push(fn);
    }
    function _resolveMetadataReady() {
        _metadataReady = true;
        var cbs = _metadataReadyResolvers.splice(0);
        cbs.forEach(function (fn) { try { fn(); } catch (e) {} });
        
        // NEW: Once metadata is ready, ensure fields are enabled correctly

        var modalEl = document.getElementById('finalIssueModal');
        if (modalEl && (modalEl.classList.contains('show') || modalEl.classList.contains('is-open'))) {
            // Only re-apply if the modal is currently open
            applyTesterRegressionReadonlyState();
        }
    }

    function loadMetadataOptions() {
        if (!issueTemplatesApi) return;
        fetch(issueTemplatesApi + '?action=metadata_options&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res && res.fields) { 
                    issueMetadataFields = res.fields; 
                    applyMetadataOptions(res.fields);
                    _resolveMetadataReady();
                    // If edit modal is currently open, re-apply metadata values
                    // because applyMetadataOptions resets the container innerHTML
                    var modalEl = document.getElementById('finalIssueModal');
                    if (modalEl && modalEl.classList.contains('show')) {
                        var editId = document.getElementById('finalIssueEditId') && document.getElementById('finalIssueEditId').value;
                        if (editId) {
                            // Find the issue in loaded data and re-populate metadata fields
                            var currentIssue = null;
                            Object.keys(issueData.pages || {}).forEach(function (pid) {
                                var pageStore = issueData.pages[pid];
                                if (pageStore && Array.isArray(pageStore.final)) {
                                    pageStore.final.forEach(function (iss) {
                                        if (String(iss.id) === String(editId)) currentIssue = iss;
                                    });
                                }
                            });
                            if (currentIssue) {
                                Promise.resolve().then(function () {
                                    res.fields.forEach(function (f) {
                                        var elId = 'finalIssueField_' + f.field_key;
                                        var val = null;
                                        if (currentIssue[f.field_key] !== undefined) {
                                            val = currentIssue[f.field_key];
                                        } else if (currentIssue.metadata && currentIssue.metadata[f.field_key] !== undefined) {
                                            val = currentIssue.metadata[f.field_key];
                                        }
                                        var $el = jQuery('#' + elId);
                                        if ($el.length && val !== null) {
                                            if ($el.prop('multiple') && val && !Array.isArray(val)) val = [val];
                                            if (!$el.prop('multiple') && Array.isArray(val)) val = val[0] || null;
                                            $el.val(val).trigger('change');
                                        }
                                    });
                                });
                            }
                        }
                    }
                }
            })
            .catch(function(err) {
                // Silently handle metadata loading errors
            });
    }

    async function addOrUpdateFinalIssue() {
        // Get selected pages from the select element
        var selectedPagesFallback = [];
        if (window.jQuery) {
            var $pagesEl = jQuery('#finalIssuePages');
            if ($pagesEl.length) {
                selectedPagesFallback = $pagesEl.val() || [];
                // Ensure it's an array
                if (!Array.isArray(selectedPagesFallback)) {
                    selectedPagesFallback = [selectedPagesFallback].filter(Boolean);
                }
            }
        }
        var normalizedSelectedPages = normalizeProjectPageIds(selectedPagesFallback);
        var selectedPageId = resolveValidSelectedPageId(issueData.selectedPageId, normalizedSelectedPages);
        if (selectedPageId) {
            issueData.selectedPageId = selectedPageId;
        }
        // Allow saving with no pages selected (user can remove all page associations)
        // if (!selectedPageId) {
        //     issueNotify('Please select at least one page before saving the issue.', 'warning');
        //     return;
        // }
        
        // Prevent multiple clicks and show loading state
        var saveBtn = document.getElementById('finalIssueSaveBtn');
        if (saveBtn && saveBtn.disabled) {
            return; // Already saving
        }
        
        var saveButtonLabel = (userRole === 'client') ? 'Update' : 'Save Issue';

        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
        }
        
        var editId = document.getElementById('finalIssueEditId').value;
        var expectedUpdatedAt = '';
        var expectedUpdatedAtEl = document.getElementById('finalIssueExpectedUpdatedAt');
        if (expectedUpdatedAtEl) expectedUpdatedAt = (expectedUpdatedAtEl.value || '').trim();
        var titleVal = '';
        var titleInput = document.getElementById('customIssueTitle');
        if (titleInput) {
            titleVal = titleInput.value.trim();
        }
        var qaStatusValue = jQuery('#finalIssueQaStatus').val() || [];
        var reporterQaMap = getReporterQaStatusMapFromUi();
        
        // If user doesn't have QA permission, don't send QA status data at all
        if (!canUpdateIssueQaStatus) {
            qaStatusValue = [];
            reporterQaMap = {};
        }
        
        var data = {
            title: titleVal,
            details: jQuery('#finalIssueDetails').summernote('code'),
            status: document.getElementById('finalIssueStatus').value,
            qa_status: qaStatusValue,
            priority: document.getElementById('finalIssueField_priority') ? document.getElementById('finalIssueField_priority').value : 'medium',
            pages: normalizedSelectedPages, // Allow empty array if user removes all pages
            grouped_urls: normalizeGroupedUrlsSelection(jQuery('#finalIssueGroupedUrls').val() || []),
            reporters: jQuery('#finalIssueReporters').val() || [],
            reporter_qa_status_map: reporterQaMap,
            common_title: document.getElementById('finalIssueCommonTitle').value.trim(),
            assignee_ids: jQuery('#finalIssueAssignee').val() || []
        };

        // Collect dynamic metadata fields directly into metadata object (single pass)
        var metadata = {};
        if (typeof issueMetadataFields !== 'undefined') {
            issueMetadataFields.forEach(function (f) {
                var el = document.getElementById('finalIssueField_' + f.field_key);
                if (el) metadata[f.field_key] = jQuery(el).val();
            });
        }

        if (!data.title) { 
            // Reset button state
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = saveButtonLabel;
            }
            issueNotify('Issue title is required.', 'warning'); 
            return; 
        }

        if (!validateCommonTitleRequirement()) {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = saveButtonLabel;
            }
            return;
        }

        // Get client_ready checkbox value
        var clientReadyCheckbox = document.getElementById('finalIssueClientReady');
        var clientReady = clientReadyCheckbox ? (clientReadyCheckbox.checked ? 1 : 0) : 0;
        
        // If editing an existing issue and client_ready checkbox is not explicitly checked,
        // automatically set it to 0 (issue needs review after edit)
        if (editId && clientReadyCheckbox && !clientReadyCheckbox.checked) {
            clientReady = 0;
        }

        var pendingCommentHtml = (window.jQuery && jQuery.fn.summernote)
            ? jQuery('#finalIssueCommentEditor').summernote('code')
            : ((document.getElementById('finalIssueCommentEditor') || {}).value || '');
        var pendingCommentPlain = String(pendingCommentHtml || '').replace(/<[^>]*>/g, '').trim();
        var pendingCommentType = ((document.getElementById('finalIssueCommentType') || {}).value || 'normal');
        var pendingReplyTo = ((document.getElementById('replyToCommentId') || {}).value || '');
        if (userRole === 'client') {
            pendingCommentType = 'regression';
        }

        try {
            var fd = new FormData();
            fd.append('action', editId ? 'update' : 'create');
            fd.append('project_id', projectId);
            if (editId) fd.append('id', editId);
            if (editId && expectedUpdatedAt) fd.append('expected_updated_at', expectedUpdatedAt);
            if (editId) fd.append('expected_history_id', (document.getElementById('finalIssueModal') || {}).dataset.expectedHistoryId || '0');
            fd.append('page_id', normalizedSelectedPages.length ? normalizedSelectedPages[0] : 0);
            fd.append('metadata', JSON.stringify(metadata));
            fd.append('client_ready', clientReady);

            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) { fd.append(k, JSON.stringify(v)); }
                else {
                    if (k === 'status') fd.append('issue_status', v);
                    else if (k === 'details') fd.append('description', v);
                    else if (v && typeof v === 'object') fd.append(k, JSON.stringify(v));
                    else fd.append(k, v);
                }
            });

            // Add session refresh header to prevent timeout during active use
            var headers = {
                'X-Session-Refresh': '1'
            };
            
            var res, controller, timeoutId;
            controller = new AbortController();
            timeoutId = setTimeout(function() { controller.abort(); }, 30000);
            try {
                res = await fetch(issuesApiBase, { 
                    method: 'POST', 
                    body: fd, 
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: headers
                });
            } finally {
                clearTimeout(timeoutId);
            }

            var json = null;
            try {
                var text = await res.text();
                json = text ? JSON.parse(text) : null;
            } catch (pE) {
                console.error('Failed to parse JSON response', pE);
                console.error('Raw text that failed to parse:', text);
            }

            if (res.status === 409 || (json && json.conflict)) {
                var conflictMsg = (json && json.error) ? json.error : 'This issue was modified by another user. Please review and save again.';
                await loadFinalIssues(selectedPageId);
                var freshList = (issueData.pages[selectedPageId] && issueData.pages[selectedPageId].final) ? issueData.pages[selectedPageId].final : [];
                var freshIssue = freshList.find(function (it) { return String(it.id) === String(editId); });
                
                // Reset button state
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = saveButtonLabel;
                }

                showIssueConflictDialog(conflictMsg, function () {
                    if (freshIssue) {
                        var modalEl = document.getElementById('finalIssueModal');
                        var isOpen = !!(modalEl && modalEl.classList.contains('show'));
                        openFinalEditor(freshIssue, { skipShow: isOpen });
                    }
                });
                return;
            }

            if (!res.ok) {
                throw new Error(json && json.error ? json.error : 'Server returned error ' + res.status);
            }

            if (!json || !json.success) {
                throw new Error(json && json.error ? json.error : 'Save failed');
            }

            var store = issueData.pages;
            ensurePageStore(store, selectedPageId);
            var pagesArr = (data.pages && data.pages.length) ? data.pages : [selectedPageId];

            var savedIssueId = String(editId || json.id || '');
            var pendingCommentSaved = true;
            if (pendingCommentPlain && savedIssueId) {
                pendingCommentSaved = await submitIssueComment(savedIssueId, pendingCommentHtml, pendingCommentType, pendingReplyTo, {
                    clearEditor: true,
                    silent: true
                });
                if (!pendingCommentSaved) {
                    // Reset button state
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = saveButtonLabel;
                    }
                    issueNotify('Issue saved, but comment could not be saved. Please click "Add Comment" and try again.', 'warning');
                    return;
                }
            }

            if (!editId) await deleteDraft();
            stopDraftAutosave();
            issueData.initialFormState = null;
            finalIssueBypassCloseConfirm = true;
            
            // Close modal immediately for better UX
            hideEditors();
            
            // Reset save button state
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = saveButtonLabel;
            }
            
            // Show success message after modal closes
            setTimeout(function() {
                issueNotify(editId ? 'Issue updated successfully' : 'Issue created successfully', 'success');
            }, 100);
            
            // Optimized update: update data in memory and re-render the table
            // Normalize API response to internal format (description -> details, etc.)
            function normalizeIssueFromApi(apiIssue) {
                if (!apiIssue) return apiIssue;
                var n = Object.assign({}, apiIssue);
                // API returns 'description', internal store uses 'details'
                if (n.details === undefined || n.details === '') {
                    n.details = n.description || '';
                }
                // Ensure id is string
                n.id = String(n.id || '');
                return n;
            }

            if (editId) {
                // Update the issue data in memory
                if (issueData.selectedPageId && issueData.pages[issueData.selectedPageId].final) {
                    var issueIndex = issueData.pages[issueData.selectedPageId].final.findIndex(function(i) {
                        return String(i.id) === String(savedIssueId);
                    });
                    if (issueIndex !== -1 && json.issue) {
                        issueData.pages[issueData.selectedPageId].final[issueIndex] = normalizeIssueFromApi(json.issue);
                    }
                }
            } else {
                // For new issues, use server response data
                if (json.issue) {
                    var list = issueData.pages[selectedPageId].final || [];
                    // Avoid duplicate: remove existing entry with same id if any
                    list = list.filter(function(it) { return String(it.id) !== String(json.issue.id); });
                    list.unshift(normalizeIssueFromApi(json.issue));
                    issueData.pages[selectedPageId].final = list;
                }
            }

            // Re-render the full table so all fields (status, priority, QA, etc.) reflect instantly.
            // On Common Issues page, we skip the immediate built-in render to prevent flickering
            // with older logic; the 'pms:issues-changed' event will trigger the correct refresh.
            if (!document.getElementById('commonIssuesBody') || document.getElementById('issues-list')) {
                renderAll();
            }
            showFinalIssuesTab();

            if (!editId && issueData.comments['new']) {
                issueData.comments[String(json.id)] = issueData.comments['new'];
                delete issueData.comments['new'];
            }
            
            // Dispatch issues changed event so pages like issues_all.php can reload their list
            dispatchIssuesChanged({
                action: editId ? 'update' : 'create',
                type: 'final',
                issue_id: String(editId || json.id || ''),
                page_id: String(selectedPageId || ''),
                issue: json.issue || null  // pass full updated issue for instant row update
            });

            // Refresh regression stats if the panel exists
            if (typeof window.loadRegressionStats === 'function') {
                window.loadRegressionStats();
            }
        } catch (e) {
            // Reset button state on error
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = saveButtonLabel;
            }
            
            // Handle timeout errors
            if (e.name === 'AbortError') {
                issueNotify('Save request timed out. Please check your connection and try again.', 'danger');
                return;
            }
            
            // Handle connection errors
            if (e.message && (e.message.includes('ERR_EMPTY_RESPONSE') || e.message.includes('ERR_CONNECTION_RESET') || e.message.includes('Failed to fetch'))) {
                issueNotify('Connection error. Please check your internet connection and try again.', 'danger');
                return;
            }
            
            // Handle authentication errors
            if (e.message && (e.message.includes('401') || e.message.includes('Unauthorized'))) {
                issueNotify('Your session has expired. Please refresh the page and login again.', 'warning');
                return;
            }
            
            if (String(e.message || '').toLowerCase().indexOf('modified by another user') !== -1) {
                var conflictMsg = 'Latest data has been loaded. Please review and save again.';
                await loadFinalIssues(selectedPageId);
                var freshList = (issueData.pages[selectedPageId] && issueData.pages[selectedPageId].final) ? issueData.pages[selectedPageId].final : [];
                var freshIssue = freshList.find(function (it) { return String(it.id) === String(editId); });
                showIssueConflictDialog('This issue was modified by another user. ' + conflictMsg, function () {
                    if (freshIssue) {
                        var modalEl = document.getElementById('finalIssueModal');
                        var isOpen = !!(modalEl && modalEl.classList.contains('show'));
                        openFinalEditor(freshIssue, { skipShow: isOpen });
                    }
                });
                return;
            }
            issueNotify('Unable to save issue: ' + (e.message || 'Unknown error'), 'danger');
        }
    }

    async function addOrUpdateReviewIssue() {
        if (!reviewFeaturesEnabled) return;
        if (!issueData.selectedPageId) return;
        var editId = document.getElementById('reviewIssueEditId').value;
        var editIds = normalizeIdList(editId);
        var detailsHtml = jQuery('#reviewIssueDetails').summernote('code');
        var meta = {
            rule_id: (document.getElementById('reviewIssueRuleId') || {}).value || '',
            impact: (document.getElementById('reviewIssueImpact') || {}).value || '',
            source_url: (document.getElementById('reviewIssuePrimarySourceUrl') || {}).value || ''
        };
        var data = {
            title: document.getElementById('reviewIssueTitle').value.trim(),
            instance: document.getElementById('reviewIssueInstance').value.trim(),
            wcag: document.getElementById('reviewIssueWcag').value.trim(),
            severity: document.getElementById('reviewIssueSeverity').value,
            details: wrapReviewDetailsWithMeta(detailsHtml, document.getElementById('reviewIssueTitle').value.trim(), meta)
        };
        if (!data.title) { issueNotify('Issue title is required.', 'warning'); return; }
        if (!data.details || data.details.trim() === '') data.details = data.title;
        try {
            var pageId = String(issueData.selectedPageId);
            var list = getLocalReviewItems(pageId);
            if (editIds.length) {
                list = list.map(function (it) {
                    if (editIds.indexOf(String(it.id)) === -1) return it;
                    return Object.assign({}, it, {
                        title: data.title,
                        instance: data.instance,
                        wcag: data.wcag,
                        details: data.details,
                        rule_id: meta.rule_id || it.rule_id || '',
                        impact: meta.impact || it.impact || '',
                        source_url: meta.source_url || it.source_url || '',
                        source_urls: (meta.source_url ? [meta.source_url] : (it.source_urls || []))
                    });
                });
            } else {
                list.push({
                    id: 'local-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7),
                    title: data.title,
                    instance: data.instance,
                    wcag: data.wcag,
                    rule_id: meta.rule_id || '',
                    impact: meta.impact || '',
                    source_url: meta.source_url || '',
                    source_urls: meta.source_url ? [meta.source_url] : [],
                    description_text: '',
                    failure_summary: '',
                    incorrect_code: '',
                    recommendation: '',
                    severity: data.severity || 'medium',
                    details: data.details,
                    page_id: pageId
                });
            }
            setLocalReviewItems(pageId, list);
            reviewIssueBypassCloseConfirm = true;
            reviewIssueInitialFormState = null;
            hideEditors();
            clearReviewDraftLocal();
            await loadReviewFindings(issueData.selectedPageId);
        } catch (e) { issueNotify('Unable to save tool finding.', 'danger'); }
    }

    async function moveCurrentReviewIssueToFinal() {
        if (!reviewFeaturesEnabled) return;
        issueNotify('JS-only mode: "Move to Final" is disabled. Create final issue manually.', 'warning');
    }

    async function addOrUpdateCommonIssue() {
        var editId = document.getElementById('commonIssueEditId').value;
        var data = {
            title: document.getElementById('commonIssueTitle').value.trim(),
            pages: jQuery('#commonIssuePages').val() || [],
            details: jQuery('#commonIssueDetails').summernote('code')
        };
        if (!data.title) { issueNotify('Common issue title is required.', 'warning'); return; }
        try {
            var fd = new FormData();
            fd.append('action', editId ? 'common_update' : 'common_create');
            fd.append('project_id', projectId);
            if (editId) fd.append('id', editId);
            fd.append('title', data.title);
            fd.append('description', data.details);
            fd.append('pages', JSON.stringify(data.pages || []));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Save failed');
            hideEditors();
            await loadCommonIssues();
            dispatchIssuesChanged({
                action: editId ? 'update' : 'create',
                type: 'common',
                issue_id: String(editId || json.id || ''),
                page_ids: (data.pages || []).map(function (v) { return String(v); })
            });
        } catch (e) { issueNotify('Unable to save common issue.', 'danger'); }
    }

    async function moveReviewToFinal() {
        if (!reviewFeaturesEnabled) return;
        issueNotify('JS-only mode: "Move to Final" is disabled. Create final issue manually.', 'warning');
    }

    async function deleteReviewIds(ids) {
        if (!reviewFeaturesEnabled) return;
        ids = Array.from(new Set(normalizeIdList(ids)));
        if (!ids.length) return;
        try {
            var pageId = String(issueData.selectedPageId || '');
            var list = getLocalReviewItems(pageId);
            var next = list.filter(function (it) { return ids.indexOf(String(it.id || '')) === -1; });
            setLocalReviewItems(pageId, next);
            await loadReviewFindings(issueData.selectedPageId);
        } catch (e) { issueNotify('Unable to delete tool findings.', 'danger'); }
    }

    async function deleteFinalIds(ids) {
        ids = Array.from(new Set(normalizeIdList(ids)));
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadFinalIssues(issueData.selectedPageId);
            await loadCommonIssues();
            dispatchIssuesChanged({
                action: 'delete',
                type: 'final',
                ids: ids.slice(),
                page_id: String(issueData.selectedPageId || '')
            });

            // Refresh regression stats if the panel exists
            if (typeof window.loadRegressionStats === 'function') {
                window.loadRegressionStats();
            }
        } catch (e) { issueNotify((e && e.message) ? e.message : 'Unable to delete issues.', 'danger'); }
    }

    async function deleteCommonIds(ids) {
        if (!ids.length) return;
        try {
            var fd = new FormData(); fd.append('action', 'common_delete'); fd.append('project_id', projectId); fd.append('ids', ids.join(','));
            var res = await fetch(issuesApiBase, { method: 'POST', body: fd, credentials: 'same-origin' });
            var json = await res.json();
            if (!json || json.error) throw new Error(json && json.error ? json.error : 'Delete failed');
            await loadCommonIssues();
            dispatchIssuesChanged({
                action: 'delete',
                type: 'common',
                ids: ids.slice()
            });
        } catch (e) { issueNotify((e && e.message) ? e.message : 'Unable to delete common issues.', 'danger'); }
    }

    async function deleteSelected(type) {
        if (!issueData.selectedPageId && type !== 'common') return;
        if (type === 'final') {
            var sel = Array.from(document.querySelectorAll('.' + type + '-select:checked')).map(function (el) {
                return el.getAttribute('data-id') || el.value || '';
            });
            sel = Array.from(new Set(normalizeIdList(sel)));
            if (!sel.length) return;
            await deleteFinalIds(sel);
        } else if (type === 'common') {
            var selC = Array.from(document.querySelectorAll('.common-select:checked')).map(function (el) { return el.getAttribute('data-id'); });
            if (!selC.length) return;
            await deleteCommonIds(selC);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        try { } catch (e) { }
        initSelect2();
        applyIssueQaPermissionState();
        initEditors();
        loadTemplates();
        loadMetadataOptions();

        // Only attach page click listeners if issues tab is active
        var issuesTab = document.querySelector('#issues');
        if (issuesTab && issuesTab.classList.contains('active')) {
            attachPageClickListeners();
        }

        // Auto-select first page if issues tab is active
        var firstPageBtn = document.querySelector('#issuesPageList .issues-page-row');
        if (firstPageBtn && issuesTab && issuesTab.classList.contains('active')) {
            var pageId = firstPageBtn.getAttribute('data-page-id');
            if (pageId && pageId !== '0') {
                setSelectedPage(firstPageBtn);
            } else {
                var uniqueId = firstPageBtn.getAttribute('data-unique-id');
                if (uniqueId) setSelectedUniquePage(firstPageBtn, uniqueId);
            }
        }
    });

    // Prevent page reload/navigation when there are unsaved changes
    window.addEventListener('beforeunload', function (e) {
        leaveIssuePresenceOnUnload();
        if (hasFormChanges()) {
            e.preventDefault();
            e.returnValue = ''; // Chrome requires returnValue to be set
            return ''; // For older browsers
        }
    });

    // New function for unique page selection
    window.setSelectedUniquePage = function (btn, uniqueId) {
        document.querySelectorAll('#issuesPageList .issues-page-row').forEach(function (b) { b.classList.remove('table-active'); });
        btn.classList.add('table-active');
        // Show details section
        var name = btn.getAttribute('data-page-name') || 'Page';
        var tester = btn.getAttribute('data-page-tester') || '-';
        var env = btn.getAttribute('data-page-env') || '-';
        var issues = btn.getAttribute('data-page-issues') || '0';
        var nameEl = document.getElementById('issueSelectedPageName');
        var metaEl = document.getElementById('issueSelectedPageMeta');
        if (nameEl) nameEl.textContent = name;
        if (metaEl) metaEl.textContent = 'Tester: ' + tester + ' | Env: ' + env + ' | Issues: ' + issues;
        // Show/hide columns
        var pagesCol = document.getElementById('issuesPagesCol');
        var detailCol = document.getElementById('issuesDetailCol');
        var backBtn = document.getElementById('issuesBackBtn');
        if (pagesCol) pagesCol.classList.add('d-none');
        if (detailCol) {
            detailCol.classList.remove('d-none');
            detailCol.classList.remove('col-lg-8');
            detailCol.classList.add('col-lg-12');
        }
        if (backBtn) backBtn.classList.remove('d-none');
        // If we have a mapped page id, load issues for it
        var pageId = btn.getAttribute('data-page-id');
        if (pageId && pageId !== '0') {
            issueData.selectedPageId = pageId;
            ensurePageStore(issueData.pages, issueData.selectedPageId);
            updateEditingState();
            populatePageUrls(issueData.selectedPageId);
            renderAll();
            loadFinalIssues(issueData.selectedPageId);
        }
    };

    var issuesTabBtn = document.querySelector('button[data-bs-target="#issues"]');
    if (issuesTabBtn) {
        issuesTabBtn.addEventListener('shown.bs.tab', function () {
            attachPageClickListeners();

            if (!issueData.selectedPageId) {
                var fp = document.querySelector('#issuesPageList .issues-page-row');
                if (fp) {
                    var pageId = fp.getAttribute('data-page-id');
                    if (pageId && pageId !== '0') {
                        setSelectedPage(fp);
                    } else {
                        var uniqueId = fp.getAttribute('data-unique-id');
                        if (uniqueId) setSelectedUniquePage(fp, uniqueId);
                    }
                }
            } else {
                updateModeUI();
                renderAll();
                showFinalIssuesTab();
            }
        });
    }

    function isVisibleAndEnabled(el) {
        if (!el) return false;
        if (el.disabled) return false;
        if (el.classList.contains('d-none')) return false;
        var style = window.getComputedStyle(el);
        if (!style) return true;
        if (style.display === 'none' || style.visibility === 'hidden') return false;
        return true;
    }

    var addF = document.getElementById('issueAddFinalBtn'); if (addF) addF.addEventListener('click', function () { openFinalEditor(null); });

    // Keyboard shortcuts: Alt+A (Add), Alt+S (Save), Alt+N (New/Add)
    document.addEventListener('keydown', function (e) {
        if (!e || !e.altKey) return;
        var key = String(e.key || '').toLowerCase();

        // 1. Alt+S: Save Issue (only if modal is open)
        if (key === 's') {
            var saveBtn = document.getElementById('finalIssueSaveBtn');
            if (saveBtn && isVisibleAndEnabled(saveBtn)) {
                e.preventDefault();
                e.stopPropagation();
                saveBtn.click();
                return;
            }
        }

        // 2. Alt+A or Alt+N: Add Issue
        if (key === 'a' || key === 'n') {
            // If any modal is open, skip global "add" shortcut (unless it's Alt+S)
            if (document.querySelector('.modal.show')) return;

            var finalBtn = document.getElementById('issueAddFinalBtn') || document.getElementById('addIssueBtn');
            var commonBtn = document.getElementById('commonAddBtn');

            // Prefer the visible/active action button.
            if (isVisibleAndEnabled(finalBtn)) {
                e.preventDefault();
                e.stopPropagation();
                finalBtn.click();
                return;
            }
            if (isVisibleAndEnabled(commonBtn)) {
                e.preventDefault();
                e.stopPropagation();
                commonBtn.click();
            }
        }
    });

    var finalIssueModalEl = document.getElementById('finalIssueModal');
    if (finalIssueModalEl) {
        finalIssueModalEl.addEventListener('shown.bs.modal', function () {
            normalizeClientFinalIssueOverlayState();
            syncClientSidebarExpandButton();
        });
        finalIssueModalEl.addEventListener('keydown', function (e) {
            if (!e || !e.altKey) return;
            if (String(e.key || '').toLowerCase() !== 's') return;
            if (!finalIssueModalEl.classList.contains('show') && !finalIssueModalEl.classList.contains('is-open')) return;
            var saveBtn = document.getElementById('finalIssueSaveBtn');
            if (!saveBtn || saveBtn.disabled || saveBtn.classList.contains('d-none')) return;
            e.preventDefault();
            e.stopPropagation();
            saveBtn.click();
        });

        if (userRole === 'client') {
            finalIssueModalEl.addEventListener('click', function (e) {
                var expandBtn = e.target && e.target.closest ? e.target.closest('.client-sidebar-expand') : null;
                if (expandBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleClientSidebarDialogExpanded();
                    return;
                }
                var dismissBtn = e.target && e.target.closest ? e.target.closest('.client-sidebar-close') : null;
                if (!dismissBtn) return;
                e.preventDefault();
                e.stopPropagation();
                requestClientFinalIssueOverlayClose();
            });
        }

        finalIssueModalEl.addEventListener('click', function (e) {
            if (userRole === 'client') return;
            var dismissBtn = e.target && e.target.closest ? e.target.closest('[data-bs-dismiss="modal"]') : null;
            if (dismissBtn) {
                // Try immediate leave when user explicitly closes modal.
                stopIssuePresenceTracking();
            }
        });
        finalIssueModalEl.addEventListener('hide.bs.modal', function (e) {
            if (userRole === 'client') return;
            if (finalIssueBypassCloseConfirm) {
                finalIssueBypassCloseConfirm = false;
                stopDraftAutosave();
                issueData.initialFormState = null;
                stopIssuePresenceTracking();
                return;
            }
            var editId = document.getElementById('finalIssueEditId').value;
            // Check for changes in both NEW and EDIT modes
            if (hasFormChanges()) {
                e.preventDefault();
                e.stopPropagation();

                // Show custom confirmation modal
                showDraftConfirmation(function (action) {
                    if (action === 'save') {
                        // For new issues, save as draft; for edit, save the issue
                        if (!editId) {
                            saveDraft().then(function () {
                                stopDraftAutosave();
                                issueData.initialFormState = null;
                                var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                finalIssueBypassCloseConfirm = true;
                                if (modal) modal.hide();
                            });
                        } else {
                            // For edit mode, trigger save button click
                            document.getElementById('finalIssueSaveBtn').click();
                            // Modal will close after successful save
                        }
                    } else if (action === 'discard') {
                        if (!editId) {
                            deleteDraft().then(function () {
                                stopDraftAutosave();
                                issueData.initialFormState = null;
                                var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                                if (modal) modal.hide();
                            });
                        } else {
                            // For edit mode, just close without saving
                            stopDraftAutosave();
                            issueData.initialFormState = null;
                            var modal = bootstrap.Modal.getInstance(finalIssueModalEl);
                            finalIssueBypassCloseConfirm = true;
                            if (modal) modal.hide();
                        }
                    }
                    // If action === 'keep', do nothing (modal stays open)
                }, editId);
            } else {
                stopDraftAutosave();
                issueData.initialFormState = null;
                stopIssuePresenceTracking();
            }
        });
        finalIssueModalEl.addEventListener('hidden.bs.modal', function () {
            stopIssuePresenceTracking();
            clearIssueConflictNotice();
            document.body.classList.remove('client-issue-sidebar-open');
            document.body.classList.remove('client-issue-sidebar-dialog-expanded');
            finalIssueModalEl.classList.remove('is-dialog-expanded');
            setFinalIssueComposeExpanded(false, { focus: false });
            cleanupModalOverlayState();
        });
    }
    document.addEventListener('hidden.bs.modal', function (e) {
        if (e && e.target && e.target.id === 'finalIssueModal') {
            stopIssuePresenceTracking();
            document.body.classList.remove('client-issue-sidebar-open');
            cleanupModalOverlayState();
        }
    });

    // Draft confirmation modal function
    function showDraftConfirmation(callback, editId) {
        var isEditMode = !!editId;
        var saveButtonText = isEditMode ? 'Save Changes' : 'Save Draft';
        var saveButtonIcon = isEditMode ? 'save' : 'file-alt';

        var modalHtml = `
                <div class="modal fade" id="draftConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-warning-subtle">
                                <h5 class="modal-title">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    Unsaved Changes
                                </h5>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0">You have unsaved changes in this issue. What would you like to do?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" id="draftKeepEditing">
                                    <i class="fas fa-edit me-1"></i> Keep Editing
                                </button>
                                <button type="button" class="btn btn-outline-danger" id="draftDiscard">
                                    <i class="fas fa-trash me-1"></i> Discard
                                </button>
                                <button type="button" class="btn btn-primary" id="draftSave">
                                    <i class="fas fa-${saveButtonIcon} me-1"></i> ${saveButtonText}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

        // Remove existing modal if any
        var existing = document.getElementById('draftConfirmModal');
        if (existing) {
            try {
                var existingInst = bootstrap.Modal.getInstance(existing);
                if (existingInst) existingInst.dispose();
            } catch (e) { }
            existing.remove();
            cleanupModalOverlayState();
        }

        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var confirmModal = document.getElementById('draftConfirmModal');
        var bsModal = new bootstrap.Modal(confirmModal);

        // Event listeners
        document.getElementById('draftSave').addEventListener('click', function () {
            bsModal.hide();
            callback('save');
        });

        document.getElementById('draftDiscard').addEventListener('click', function () {
            bsModal.hide();
            callback('discard');
        });

        document.getElementById('draftKeepEditing').addEventListener('click', function () {
            bsModal.hide();
            callback('keep');
        });

        // Cleanup after modal is hidden
        confirmModal.addEventListener('hidden.bs.modal', function () {
            confirmModal.remove();
            cleanupModalOverlayState();
        });

        bsModal.show();
    }

    var saveF = document.getElementById('finalIssueSaveBtn'); if (saveF) {
        saveF.addEventListener('click', addOrUpdateFinalIssue);
    }
    var pageSel = jQuery('#finalIssuePages');
    if (pageSel && pageSel.length) {
        pageSel.on('change', function () { updateGroupedUrls(); toggleCommonTitle(); });
    }

    var tplApply = document.getElementById('finalIssueApplyTemplateBtn');
    if (tplApply) {
        tplApply.addEventListener('click', function (e) {
            e.preventDefault();
            var sel = document.getElementById('finalIssueTemplate');
            var id = sel ? sel.value : '';
            if (!id) return;
            var tpl = itemTemplates.find(function (t) { return String(t.id) === String(id); });
            if (tpl) applyPreset(tpl);
        });
    }

    var addCBtn = document.getElementById('finalIssueAddCommentBtn');
    if (addCBtn) {
        addCBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var id = document.getElementById('finalIssueEditId').value || 'new';
            addIssueComment(String(id));
        });
    }
    var composeToggleBtn = document.getElementById('finalIssueComposeToggle');
    if (composeToggleBtn) {
        composeToggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            setFinalIssueComposeExpanded(!finalIssueComposeExpanded);
            setTimeout(function () {
                try { composeToggleBtn.focus(); } catch (err) { }
            }, 0);
        });
        composeToggleBtn.addEventListener('keydown', function (e) {
            if (!e) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                setFinalIssueComposeExpanded(!finalIssueComposeExpanded);
                return;
            }
            if (!finalIssueComposeExpanded) return;
            if (e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                focusFinalIssueCommentEditor();
            }
        });
    }
    syncFinalIssueComposeUi();

    ['clientIssueSearch', 'clientIssueStatusFilter'].forEach(function (id) {
        var field = document.getElementById(id);
        if (!field) return;
        var eventName = field.tagName === 'SELECT' ? 'change' : 'input';
        field.addEventListener(eventName, function () {
            renderFinalIssues();
        });
    });

    var resetBtn = document.getElementById('btnResetToTemplate');
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            e.preventDefault();
            resetToTemplateWithConfirm();
        });
    }

    // Fallback delegated handler: keeps reset working even if button is re-rendered/cloned later.
    if (!window.__issueResetTemplateDelegatedBound) {
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#btnResetToTemplate') : null;
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            resetToTemplateWithConfirm();
        }, true);
        window.__issueResetTemplateDelegatedBound = true;
    }

    var historyBtn = document.getElementById('btnShowHistory');
    if (historyBtn && userRole !== 'client') {
        historyBtn.addEventListener('shown.bs.tab', function () {
            var id = document.getElementById('finalIssueEditId').value;
            if (!id) {
                document.getElementById('historyEntries').innerHTML = '<div class="text-center py-4 text-muted">No history for new issues.</div>';
                return;
            }
            fetch(ProjectConfig.baseDir + '/api/issue_history.php?issue_id=' + id, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) {
                        return res.text().then(function (txt) {
                            var wrap = document.getElementById('historyEntries');
                            var msg = txt || ('Server error ' + res.status);
                            try { var j = JSON.parse(txt); msg = j.error || msg; } catch (e) {}
                            if (wrap) wrap.innerHTML = '<div class="alert alert-danger">Failed to load history: ' + escapeHtml(msg) + '</div>';
                            throw new Error(msg);
                        });
                    }
                    return res.json();
                })
                .then(function (res) {
                    var wrap = document.getElementById('historyEntries');
                    if (!wrap || !res || !res.history) return;
                    if (!res.history.length) { wrap.innerHTML = '<div class="text-center py-4 text-muted">No edits recorded yet.</div>'; return; }

                    // Helper function to strip HTML tags and preserve spacing
                    var stripHtml = function (html) {
                        if (!html) return '';
                        html = String(html);
                        // Preserve images in history diff (add/delete should be visible).
                        html = html.replace(/<img\b[^>]*>/gi, function (tag) {
                            var alt = '';
                            var src = '';
                            var altMatch = tag.match(/\balt\s*=\s*["']([^"']*)["']/i);
                            var srcMatch = tag.match(/\bsrc\s*=\s*["']([^"']*)["']/i);
                            if (altMatch && altMatch[1]) alt = altMatch[1].trim();
                            if (srcMatch && srcMatch[1]) src = srcMatch[1].trim();
                            var label = alt || (src ? src.split('/').pop() : 'image');
                            return ' [Image: ' + label + '] ';
                        });
                        var tmp = document.createElement('div');
                        // Preserve line breaks from rich text blocks
                        html = html.replace(/<br\s*\/?>/gi, '\n');
                        html = html.replace(/<\/(p|div|h[1-6]|li|tr|td|th)>/gi, '\n');
                        tmp.innerHTML = html;
                        var text = tmp.textContent || tmp.innerText || '';
                        text = text
                            .replace(/\r\n/g, '\n')
                            .replace(/\r/g, '\n')
                            .replace(/[ \t]+\n/g, '\n')
                            .replace(/\n[ \t]+/g, '\n')
                            .replace(/[ \t]{2,}/g, ' ')
                            .replace(/\n{3,}/g, '\n\n')
                            .trim();
                        return text;
                    };

                    wrap.innerHTML = res.history.map(function (h, idx) {
                        var oldVal = h.old_value || '';
                        var newVal = h.new_value || '';
                        var fieldName = h.field_name || 'field';
                        var uniqueId = 'history-' + idx;
                        var historyId = h.id;
                        var canRollback = !!h.can_rollback;

                        // Format field name: remove "meta:" prefix and format nicely
                        var displayFieldName = fieldName;
                        if (fieldName.startsWith('meta:')) {
                            displayFieldName = fieldName.substring(5); // Remove "meta:"
                        }
                        // Format: qa_status → QA Status, severity → Severity
                        displayFieldName = displayFieldName.split('_').map(function (word) {
                            return word.charAt(0).toUpperCase() + word.slice(1);
                        }).join(' ');

                        // Format QA status values if it's qa_status field
                        if (fieldName === 'meta:qa_status' || fieldName === 'qa_status') {
                            var formatQaStatusValue = function (raw) {
                                if (!raw) return '';
                                var values = [];
                                try {
                                    var parsed = JSON.parse(raw);
                                    if (Array.isArray(parsed)) {
                                        values = parsed;
                                    }
                                } catch (e) { }
                                if (!values.length) {
                                    values = String(raw).split(',').map(function (v) { return String(v).trim(); }).filter(Boolean);
                                }
                                return values.map(function (v) {
                                    return v.split('_').map(function (w) {
                                        if (!w) return '';
                                        return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                                    }).join(' ');
                                }).join(', ');
                            };
                            // Format old value
                            if (oldVal) {
                                oldVal = formatQaStatusValue(oldVal);
                            }
                            // Format new value
                            if (newVal) {
                                newVal = formatQaStatusValue(newVal);
                            }
                        }

                        var oldDisplay = stripHtml(oldVal || 'N/A');
                        var newDisplay = stripHtml(newVal || 'N/A');
                        var isLongTextChange = oldDisplay.length > 40 || newDisplay.length > 40 ||
                            oldDisplay.indexOf('\n') >= 0 || newDisplay.indexOf('\n') >= 0;

                        // For description and long text fields, create inline diff view
                        if (fieldName === 'description' || isLongTextChange) {
                            var oldText = stripHtml(oldVal);
                            var newText = stripHtml(newVal);

                            // If texts are identical, show a message
                            if (oldText.trim() === newText.trim()) {
                                return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                    '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                    '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                    '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                    '</small>' +
                                    '<div class="d-flex align-items-center gap-2">' +
                                    (canRollback ? '<button class="btn btn-xs btn-outline-warning py-0 px-1 issue-history-rollback" data-history-id="' + historyId + '" data-field="' + escapeAttr(displayFieldName) + '" title="Rollback this field to its previous value"><i class="fas fa-undo me-1"></i>Rollback</button>' : '') +
                                    '<small class="text-muted"><i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • <i class="fas fa-clock me-1"></i>' + h.created_at + '</small>' +
                                    '</div>' +
                                    '</div>' +
                                    '<div class="alert alert-info mb-0">' +
                                    '<i class="fas fa-info-circle me-2"></i>No visible changes detected (possibly formatting or whitespace changes)' +
                                    '</div>' +
                                    '</div>';
                            }

                            // Split by words and spaces, keeping delimiters
                            var oldWords = oldText.split(/(\s+)/);
                            var newWords = newText.split(/(\s+)/);

                            // LCS-based diff algorithm
                            var lcs = function (arr1, arr2) {
                                var m = arr1.length;
                                var n = arr2.length;
                                var dp = [];

                                // Initialize DP table
                                for (var i = 0; i <= m; i++) {
                                    dp[i] = [];
                                    for (var j = 0; j <= n; j++) {
                                        dp[i][j] = 0;
                                    }
                                }

                                // Fill DP table
                                for (var i = 1; i <= m; i++) {
                                    for (var j = 1; j <= n; j++) {
                                        if (arr1[i - 1] === arr2[j - 1]) {
                                            dp[i][j] = dp[i - 1][j - 1] + 1;
                                        } else {
                                            dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                                        }
                                    }
                                }

                                // Backtrack to find LCS
                                var result = [];
                                var i = m, j = n;
                                while (i > 0 && j > 0) {
                                    if (arr1[i - 1] === arr2[j - 1]) {
                                        result.unshift({ type: 'common', value: arr1[i - 1], oldIdx: i - 1, newIdx: j - 1 });
                                        i--;
                                        j--;
                                    } else if (dp[i - 1][j] > dp[i][j - 1]) {
                                        i--;
                                    } else {
                                        j--;
                                    }
                                }

                                return result;
                            };

                            // Get LCS
                            var common = lcs(oldWords, newWords);

                            // Build diff HTML
                            var diffHtml = '';
                            var oldIdx = 0;
                            var newIdx = 0;

                            for (var k = 0; k < common.length; k++) {
                                var item = common[k];

                                // Add removed words before this common word
                                if (oldIdx < item.oldIdx) {
                                    var removedText = '';
                                    while (oldIdx < item.oldIdx) {
                                        removedText += escapeHtml(oldWords[oldIdx]);
                                        oldIdx++;
                                    }
                                    diffHtml += '<span style="background-color:#ffd7d5;color:#d73a49;text-decoration:line-through;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + removedText + '</span>';
                                }

                                // Add added words before this common word
                                if (newIdx < item.newIdx) {
                                    var addedText = '';
                                    while (newIdx < item.newIdx) {
                                        addedText += escapeHtml(newWords[newIdx]);
                                        newIdx++;
                                    }
                                    diffHtml += '<span style="background-color:#d4edda;color:#28a745;font-weight:600;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + addedText + '</span>';
                                }

                                // Add the common word
                                diffHtml += escapeHtml(item.value);
                                oldIdx++;
                                newIdx++;
                            }

                            // Add remaining removed words
                            if (oldIdx < oldWords.length) {
                                var removedText = '';
                                while (oldIdx < oldWords.length) {
                                    removedText += escapeHtml(oldWords[oldIdx]);
                                    oldIdx++;
                                }
                                diffHtml += '<span style="background-color:#ffd7d5;color:#d73a49;text-decoration:line-through;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + removedText + '</span>';
                            }

                            // Add remaining added words
                            if (newIdx < newWords.length) {
                                var addedText = '';
                                while (newIdx < newWords.length) {
                                    addedText += escapeHtml(newWords[newIdx]);
                                    newIdx++;
                                }
                                diffHtml += '<span style="background-color:#d4edda;color:#28a745;font-weight:600;padding:2px 4px;border-radius:3px;margin:0 2px;display:inline;">' + addedText + '</span>';
                            }

                            // Ensure diffHtml is valid
                            if (!diffHtml || diffHtml.trim() === '') {
                                diffHtml = escapeHtml(newText); // Fallback
                            }

                            // Show highlighted diff by default (no truncation), so removed/added
                            // formatting is visible without clicking "Read More".
                            var preview = diffHtml;
                            var needsExpand = false;

                            var oldDisplay = stripHtml(oldVal || 'N/A');
                            var newDisplay = stripHtml(newVal || 'N/A');
                            return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                '</small>' +
                                '<div class="d-flex align-items-center gap-2">' +
                                (canRollback ? '<button class="btn btn-xs btn-outline-warning py-0 px-1 issue-history-rollback" data-history-id="' + historyId + '" data-field="' + escapeAttr(displayFieldName) + '" title="Rollback this field to its previous value"><i class="fas fa-undo me-1"></i>Rollback</button>' : '') +
                                '<small class="text-muted"><i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • <i class="fas fa-clock me-1"></i>' + h.created_at + '</small>' +
                                '</div>' +
                                '</div>' +
                                '<div class="diff-container bg-white p-3 rounded border" style="line-height: 1.8;">' +
                                '<div class="diff-preview" id="preview-' + uniqueId + '" style="white-space: pre-wrap; word-wrap: break-word;">' +
                                preview +
                                '</div>' +
                                (needsExpand ?
                                    '<div class="diff-full" id="full-' + uniqueId + '" style="display:none;white-space:pre-wrap;word-wrap:break-word;line-height:1.8;">' +
                                    diffHtml +
                                    '</div>' +
                                    '<button class="btn btn-sm btn-outline-primary mt-2" onclick="toggleHistoryDiff(\'' + uniqueId + '\', event)">' +
                                    '<i class="fas fa-chevron-down me-1"></i>' +
                                    '<span class="toggle-text">Read More</span>' +
                                    '</button>'
                                    : '') +
                                '</div>' +
                                '<div class="mt-2 small">' +
                                '<span class="badge bg-danger-subtle text-danger me-2">' +
                                '<i class="fas fa-minus me-1"></i>Removed' +
                                '</span>' +
                                '<span class="badge bg-success-subtle text-success">' +
                                '<i class="fas fa-plus me-1"></i>Added' +
                                '</span>' +
                                '</div>' +
                                '</div>';
                        } else {
                            // For other fields, simple before/after
                            return '<div class="issue-history-entry border rounded p-3 mb-3" style="background-color:#f8f9fa;">' +
                                '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">' +
                                '<small class="text-muted text-uppercase" style="font-weight:600;letter-spacing:0.5px;">' +
                                '<i class="fas fa-edit me-1"></i>' + escapeHtml(displayFieldName) +
                                '</small>' +
                                '<div class="d-flex align-items-center gap-2">' +
                                (canRollback ? '<button class="btn btn-xs btn-outline-warning py-0 px-1 issue-history-rollback" data-history-id="' + historyId + '" data-field="' + escapeAttr(displayFieldName) + '" title="Rollback this field to its previous value"><i class="fas fa-undo me-1"></i>Rollback</button>' : '') +
                                '<small class="text-muted"><i class="fas fa-user me-1"></i><strong>' + escapeHtml(h.user_name) + '</strong> • <i class="fas fa-clock me-1"></i>' + h.created_at + '</small>' +
                                '</div>' +
                                '</div>' +
                                '<div class="row g-2 bg-white p-3 rounded border">' +
                                '<div class="col-md-5">' +
                                '<div class="small text-muted mb-1 fw-bold">Before:</div>' +
                                '<div class="p-2 bg-danger-subtle text-danger rounded border border-danger" style="white-space:pre-wrap;">' +
                                '<small>' + escapeHtml(oldDisplay) + '</small>' +
                                '</div>' +
                                '</div>' +
                                '<div class="col-md-2 d-flex align-items-center justify-content-center">' +
                                '<i class="fas fa-arrow-right text-primary fs-4"></i>' +
                                '</div>' +
                                '<div class="col-md-5">' +
                                '<div class="small text-muted mb-1 fw-bold">After:</div>' +
                                '<div class="p-2 bg-success-subtle text-success rounded border border-success" style="white-space:pre-wrap;">' +
                                '<small>' + escapeHtml(newDisplay) + '</small>' +
                                '</div>' +
                                '</div>' +
                                '</div>' +
                                '</div>';
                        }
                    }).join('');

                    // Add toggle function to window scope
                    window.toggleHistoryDiff = function (id, event) {
                        var preview = document.getElementById('preview-' + id);
                        var full = document.getElementById('full-' + id);
                        var btn = event.target.closest('button');
                        var icon = btn.querySelector('i');
                        var text = btn.querySelector('.toggle-text');

                        if (full.style.display === 'none' || full.style.display === '') {
                            preview.style.display = 'none';
                            full.style.display = 'block';
                            icon.className = 'fas fa-chevron-up me-1';
                            text.textContent = 'Read Less';
                        } else {
                            preview.style.display = 'block';
                            full.style.display = 'none';
                            icon.className = 'fas fa-chevron-down me-1';
                            text.textContent = 'Read More';
                        }
                    };

                    // Rollback handlers
                    wrap.querySelectorAll('.issue-history-rollback').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var hid = this.getAttribute('data-history-id');
                            var fieldLabel = this.getAttribute('data-field');
                            var issueId = document.getElementById('finalIssueEditId').value;
                            if (!window.confirm('Rollback "' + fieldLabel + '" to its previous value?')) return;

                            var self = this;
                            self.disabled = true;
                            self.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Rolling back...';

                            var fd = new FormData();
                            fd.append('history_id', hid);
                            fd.append('issue_id', issueId);
                            fetch(ProjectConfig.baseDir + '/api/issue_history.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (res) {
                                    if (!res || res.error) {
                                        if (typeof showToast === 'function') showToast((res && res.error) ? res.error : 'Rollback failed', 'danger');
                                        self.disabled = false;
                                        self.innerHTML = '<i class="fas fa-undo me-1"></i>Rollback';
                                        return;
                                    }
                                    if (typeof showToast === 'function') showToast('"' + fieldLabel + '" rolled back successfully', 'success');
                                    // Reload history tab
                                    document.getElementById('btnShowHistory').dispatchEvent(new Event('shown.bs.tab'));
                                    // Reload issue data in background
                                    var currentIssueId = document.getElementById('finalIssueEditId').value;
                                    if (currentIssueId && issueData.issues) {
                                        var issueObj = issueData.issues.find(function (i) { return String(i.id) === String(currentIssueId); });
                                        if (issueObj) {
                                            fetch(ProjectConfig.baseDir + '/api/issues.php?action=list&project_id=' + encodeURIComponent(projectId) + '&id=' + encodeURIComponent(currentIssueId), { credentials: 'same-origin' })
                                                .then(function (r) { return r.json(); })
                                                .then(function (r) {
                                                    if (r && r.issues && r.issues.length) {
                                                        var updated = r.issues[0];
                                                        var idx = issueData.issues.findIndex(function (i) { return String(i.id) === String(currentIssueId); });
                                                        if (idx >= 0) issueData.issues[idx] = updated;
                                                    }
                                                }).catch(function () {});
                                        }
                                    }
                                })
                                .catch(function () {
                                    if (typeof showToast === 'function') showToast('Rollback failed', 'danger');
                                    self.disabled = false;
                                    self.innerHTML = '<i class="fas fa-undo me-1"></i>Rollback';
                                });
                        });
                    });
                })
                .catch(function (err) {
                    var wrap = document.getElementById('historyEntries');
                    if (wrap && !wrap.querySelector('.alert-danger')) {
                        wrap.innerHTML = '<div class="alert alert-danger">Failed to load history: ' + escapeHtml(String(err && err.message ? err.message : err)) + '</div>';
                    }
                });
        });
    }

    function formatVisitDuration(seconds, openedAt, closedAt) {
        if (!closedAt) return 'In progress';
        var sec = parseInt(seconds || 0, 10);
        if (!isFinite(sec) || sec < 0) sec = 0;
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        if (h > 0) return h + 'h ' + m + 'm ' + s + 's';
        if (m > 0) return m + 'm ' + s + 's';
        return s + 's';
    }

    function renderVisitHistory(entries) {
        var wrap = document.getElementById('visitHistoryEntries');
        if (!wrap) return;
        if (!Array.isArray(entries) || !entries.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">No visit history recorded yet.</div>';
            return;
        }
        wrap.innerHTML = entries.map(function (e) {
            var opened = e.opened_at ? new Date(e.opened_at).toLocaleString() : '-';
            var closed = e.closed_at ? new Date(e.closed_at).toLocaleString() : 'Still open';
            var duration = formatVisitDuration(e.duration_seconds, e.opened_at, e.closed_at);
            return '<div class="border rounded p-2 mb-2 bg-white">' +
                '<div class="fw-bold">' + escapeHtml(e.full_name || 'User') + '</div>' +
                '<div><span class="text-muted">Opened:</span> ' + escapeHtml(opened) + '</div>' +
                '<div><span class="text-muted">Closed:</span> ' + escapeHtml(closed) + '</div>' +
                '<div><span class="text-muted">Duration:</span> ' + escapeHtml(duration) + '</div>' +
                '</div>';
        }).join('');
        updateIssueTabCounts();
    }

    function loadVisitHistoryTab() {
        if (userRole === 'client') return;
        var wrap = document.getElementById('visitHistoryEntries');
        if (wrap) wrap.innerHTML = '<div class="text-center py-4 text-muted">Loading visit history...</div>';
        var id = (document.getElementById('finalIssueEditId') || {}).value;
        if (!id) {
            if (wrap) wrap.innerHTML = '<div class="text-center py-4 text-muted">No visit history for new issues.</div>';
            return;
        }
        var url = issuesApiBase + '?action=presence_session_list&project_id=' + encodeURIComponent(projectId) + '&issue_id=' + encodeURIComponent(id);
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    renderVisitHistory([]);
                    return;
                }
                renderVisitHistory(res.sessions || []);
            })
            .catch(function () { renderVisitHistory([]); });
    }

    document.addEventListener('shown.bs.tab', function (e) {
        if (userRole === 'client') return;
        var target = e && e.target ? e.target : null;
        if (!target) return;
        var targetPane = target.getAttribute('data-bs-target') || target.getAttribute('href') || '';
        if (target.id === 'btnShowVisitHistory' || targetPane === '#tabVisitHistory') {
            loadVisitHistoryTab();
        }
    });

    var chatBtn = document.getElementById('btnShowChat');
    if (chatBtn) {
        chatBtn.addEventListener('shown.bs.tab', function () {
            var id = document.getElementById('finalIssueEditId').value || 'new';
            renderIssueComments(String(id));
            if (window.jQuery && jQuery.fn.summernote) { jQuery('#finalIssueCommentEditor').summernote('code', jQuery('#finalIssueCommentEditor').summernote('code')); }
        });
    }

    var finalSubTabBtn = document.getElementById('final-issues-tab');
    if (finalSubTabBtn) { finalSubTabBtn.addEventListener('shown.bs.tab', function () { renderFinalIssues(); }); }

    document.addEventListener('click', function (e) {
        var target = e.target;
        if (target && target.classList && target.classList.contains('issue-image-thumb')) {
            e.preventDefault();
            var src = target.getAttribute('src');
            var alt = target.getAttribute('alt') || '';
            if (src) openIssueImageModal(src, alt);
        }
    });

    document.addEventListener('shown.bs.collapse', function (e) {
        var id = e.target && e.target.id ? e.target.id : '';
        if (!id) return;
        var btn = document.querySelector('[data-bs-target="#' + id + '"]');
        if (btn && btn.classList.contains('issue-url-toggle')) { btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
    });
    document.addEventListener('hidden.bs.collapse', function (e) {
        var id = e.target && e.target.id ? e.target.id : '';
        if (!id) return;
        var btn = document.querySelector('[data-bs-target="#' + id + '"]');
        if (btn && btn.classList.contains('issue-url-toggle')) { btn.innerHTML = '<i class="fas fa-globe"></i>'; }
    });

    // Use event delegation for commonAddBtn to handle dynamic loading
    document.addEventListener('click', function (e) {
        var target = e.target;
        var commonBtn = target.closest('#commonAddBtn');

        if (commonBtn || (target && target.id === 'commonAddBtn')) {
            e.preventDefault();
            e.stopPropagation();
            openCommonEditor(null);
        }
    });

    var saveCom = document.getElementById('commonIssueSaveBtn'); if (saveCom) saveCom.addEventListener('click', addOrUpdateCommonIssue);
    var backBtn = document.getElementById('issuesBackBtn'); if (backBtn) backBtn.addEventListener('click', showIssuesPages);

    var delF = document.getElementById('finalDeleteSelected'); if (delF) delF.addEventListener('click', function () {
        if (typeof confirmModal === 'function') {
            confirmModal('Delete selected issues? This action cannot be undone.', function () { deleteSelected('final'); });
        } else {
            if (confirm('Delete selected issues?')) deleteSelected('final');
        }
    });
    
    // Mark Client Ready button handler
    var markClientReadyBtn = document.getElementById('finalMarkClientReadyBtn');
    if (markClientReadyBtn) {
        markClientReadyBtn.addEventListener('click', async function () {
            var selectedCheckboxes = document.querySelectorAll('.final-select:checked');
            if (selectedCheckboxes.length === 0) return;
            
            var issueIds = Array.from(selectedCheckboxes).map(function(cb) { return cb.value; });
            
            var confirmMessage = 'Mark ' + issueIds.length + ' issue(s) as Client Ready?';
            
            var proceedWithUpdate = function() {
                (async function() {
                    try {
                        var formData = 'action=bulk_client_ready&issue_ids=' + encodeURIComponent(issueIds.join(',')) + '&client_ready=1&project_id=' + ProjectConfig.projectId;
                        
                        var response = await fetch(issuesApiBase, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData
                        });
                        
                        var data = await response.json();
                        
                        if (!data.success) {
                            throw new Error(data.message || data.error || 'Failed to update issues');
                        }
                        
                        showToast('Success', data.updated + ' issue(s) marked as Client Ready', 'success');
                        
                        // Force reload issues without cache
                        if (issueData.selectedPageId) {
                            // Clear the cache first
                            if (issueData.pages && issueData.pages[issueData.selectedPageId]) {
                                issueData.pages[issueData.selectedPageId].final = [];
                            }
                            await loadFinalIssues(issueData.selectedPageId, { silent: false, onlyIfChanged: false });
                        }
                        
                        // Uncheck all
                        document.querySelectorAll('.final-select:checked').forEach(function(cb) { cb.checked = false; });
                        var selectAll = document.getElementById('finalSelectAll');
                        if (selectAll) selectAll.checked = false;
                        updateSelectionButtons();
                        
                    } catch (error) {
                        showToast('Error', error.message || 'Failed to mark issues as Client Ready', 'error');
                    }
                })();
            };
            
            if (typeof confirmModal === 'function') {
                confirmModal(confirmMessage, proceedWithUpdate);
            } else {
                if (confirm(confirmMessage)) {
                    proceedWithUpdate();
                }
            }
        });
    }
    
    ['common', 'final'].forEach(function (t) {
        var c = document.getElementById(t + 'SelectAll');
        if (c) c.addEventListener('change', function (e) {
            document.querySelectorAll('.' + t + '-select:not([disabled])').forEach(function (cb) { cb.checked = e.target.checked; });
            updateSelectionButtons();
        });
        var body = document.getElementById(t + 'IssuesBody');
        if (body) {
            body.addEventListener('change', updateSelectionButtons);
            body.addEventListener('click', function (e) {
                var target = e.target.closest('.' + t + '-edit, .' + t + '-delete, .issue-open');
                if (!target) return;
                if (target.hasAttribute('disabled')) return;

                var id = target.getAttribute('data-id');
                    if (target.classList.contains(t + '-edit') || target.classList.contains('issue-open')) {
                        if (t === 'final') { 
                            var i = issueData.pages[issueData.selectedPageId].final.find(function (x) { return String(x.id) === id; }); 
                            openFinalEditor(i);
                        }
                    if (t === 'common') {
                        var i = issueData.common.find(function (x) { return String(x.id) === id; });

                        if (i) {
                            var actualIssueId = String(i.issue_id);

                            if (i.pages && i.pages.length > 0) {
                                var firstPageId = String(i.pages[0]);

                                ensurePageStore(issueData.pages, firstPageId);
                                issueData.selectedPageId = firstPageId;

                                var findAndOpen = function() {
                                    var finalIssue = issueData.pages[firstPageId].final.find(function (x) {
                                        return String(x.id) === actualIssueId;
                                    });
                                    if (finalIssue) {
                                        openFinalEditor(finalIssue);
                                    } else if (typeof issueNotify === 'function') {
                                        issueNotify('Could not find the underlying issue to edit.', 'warning');
                                    }
                                };

                                if (!issueData.pages[firstPageId].final || issueData.pages[firstPageId].final.length === 0) {
                                    loadFinalIssues(firstPageId).then(findAndOpen).catch(function () {
                                        if (typeof issueNotify === 'function') issueNotify('Failed to load issue data.', 'warning');
                                    });
                                } else {
                                    findAndOpen();
                                }
                            }
                        }
                    }
                } else if (target.classList.contains(t + '-delete')) {
                    if (typeof confirmModal === 'function') {
                        confirmModal('Delete this item? This action cannot be undone.', function () {
                            if (t === 'final') deleteFinalIds([id]);
                            if (t === 'common') deleteCommonIds([id]);
                        });
                    } else {
                        if (confirm('Delete this item?')) {
                            if (t === 'final') deleteFinalIds([id]);
                            if (t === 'common') deleteCommonIds([id]);
                        }
                    }
                }
            });
        }
    });

    if (finalIssueModalEl) {
        finalIssueModalEl.addEventListener('shown.bs.modal', async function () {
            // No auto-focus - let modal container handle focus
            applyIssueQaPermissionState();
            await refreshTesterRegressionLock();
            applyTesterRegressionReadonlyState();
            var currentIssueId = (document.getElementById('finalIssueEditId') || {}).value;
            if (currentIssueId) {
                startIssuePresenceTracking(currentIssueId);
            }

            // Legacy code for old select field (if it exists)
            var sel = document.getElementById('finalIssueTitle');
            if (sel) {
                sel.disabled = isTesterEditLockedByRegression();
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery('#finalIssueTitle').prop('disabled', isTesterEditLockedByRegression()).trigger('change.select2');
                }
            }
            
            // Track if this is an edit (has issue ID) and if client_ready was initially checked
            var editId = document.getElementById('finalIssueEditId').value;
            var clientReadyCheckbox = document.getElementById('finalIssueClientReady');
            if (editId && clientReadyCheckbox) {
                var initialClientReady = clientReadyCheckbox.checked;
                
                // Function to uncheck client_ready when any field changes
                var uncheckClientReady = function() {
                    if (clientReadyCheckbox && initialClientReady) {
                        clientReadyCheckbox.checked = false;
                    }
                };
                
                // Add change listeners to form fields
                var fieldsToWatch = [
                    'finalIssueTitle',
                    'finalIssueDetails',
                    'finalIssueStatus',
                    'finalIssueSeverity',
                    'finalIssuePriority',
                    'finalIssueCommonTitle'
                ];
                
                fieldsToWatch.forEach(function(fieldId) {
                    var field = document.getElementById(fieldId);
                    if (field) {
                        // Remove any existing listener first
                        field.removeEventListener('input', uncheckClientReady);
                        field.removeEventListener('change', uncheckClientReady);
                        // Add new listeners
                        field.addEventListener('input', uncheckClientReady);
                        field.addEventListener('change', uncheckClientReady);
                    }
                });
                
                // Watch Summernote editor if it exists
                if (window.jQuery && jQuery.fn.summernote) {
                    jQuery('#finalIssueDetails').off('summernote.change').on('summernote.change', uncheckClientReady);
                }
                
                // Watch Select2 fields
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery('#finalIssuePages, #finalIssueReporters, #finalIssueGroupedUrls, #finalIssueQaStatus').off('change.uncheckClientReady').on('change.uncheckClientReady', uncheckClientReady);
                }
            }
        });
    }

    initSelect2();
    applyIssueQaPermissionState();
    initUrlSelectionModal();
    updateUrlSelectionSummary();
    updateGroupedUrlsPreview();
    initSummernote();
    loadCommonIssues();

    // On issues_page_detail.php the page_id comes from the URL (set above at startup).
    // The DOMContentLoaded auto-select only fires when #issues tab exists on the page.
    // For the standalone detail page there is no tab, so we must load issues here.
    if (issueData.selectedPageId) {
        ensurePageStore(issueData.pages, issueData.selectedPageId);
        updateEditingState();
        renderAll();
        loadFinalIssues(issueData.selectedPageId);

    }

    // Define editFinalIssue for table edit buttons
    window.editFinalIssue = function (id) {
        var issue = (issueData.pages[issueData.selectedPageId] && issueData.pages[issueData.selectedPageId].final) 
            ? issueData.pages[issueData.selectedPageId].final.find(function (i) { return String(i.id) === String(id); })
            : null;
        if (issue) openFinalEditor(issue);
    };

    // Define editCommonIssue for Common Issues table
    window.editCommonIssue = function (id) {
        var issue = (issueData.common || []).find(function (i) { return String(i.id) === String(id); });
        if (issue) openFinalEditor(issue);
    };

    window.addCommonIssue = function () {
        openFinalEditor();
    };

    // Expose necessary functions globally for external pages
    window.loadFinalIssues = loadFinalIssues;
    window.updateEditingState = updateEditingState;
    window.loadCommonIssues = loadCommonIssues;
    window.openFinalEditor = openFinalEditor;
    window.updateIssueTabCounts = updateIssueTabCounts;
    window.renderFinalIssues = renderFinalIssues;
    window.renderCommonIssues = renderCommonIssues;
    window.renderAll = renderAll;

    // Expose toggleGroupedUrls globally (used by onclick in rendered HTML)
    window.toggleGroupedUrls = function(issueId, event) {
        if (event) event.stopPropagation();
        var content = document.getElementById('grouped-urls-content-' + issueId);
        if (content) {
            if (content.style.display === 'none' || !content.style.display) {
                content.style.display = 'block';
            } else {
                content.style.display = 'none';
            }
        }
    };
})();
