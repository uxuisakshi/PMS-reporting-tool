/**
 * manage-assignments.js
 * Extracted from modules/projects/manage_assignments.php inline scripts
 */

// ── First DOMContentLoaded block (flash notices + tab init + hours validation) ──
document.addEventListener('DOMContentLoaded', function () {
    var flashSuccess = window._manageAssignFlash ? window._manageAssignFlash.success : '';
    var flashError   = window._manageAssignFlash ? window._manageAssignFlash.error   : '';

    function ensureNoticeWrap() {
        var wrap = document.getElementById('pmsAssignNoticeWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'pmsAssignNoticeWrap';
            wrap.className = 'pms-assign-notice-wrap';
            document.body.appendChild(wrap);
        }
        return wrap;
    }

    function showAssignmentNotice(message, type) {
        if (!message) return;
        var wrap = ensureNoticeWrap();
        var notice = document.createElement('div');
        notice.className = 'pms-assign-notice ' + (type === 'error' ? 'error' : 'success');
        var icon = type === 'error' ? 'fa-exclamation-circle text-danger' : 'fa-check-circle text-success';
        notice.innerHTML =
            '<i class="fas ' + icon + ' mt-1"></i>' +
            '<div class="msg">' + message.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>' +
            '<button type="button" class="close-btn" aria-label="Close">&times;</button>';
        var removeNotice = function () {
            if (notice.parentNode) notice.parentNode.removeChild(notice);
        };
        notice.querySelector('.close-btn').addEventListener('click', removeNotice);
        wrap.appendChild(notice);
        setTimeout(removeNotice, 5500);
    }

    function notifyAssignmentResult(message, type) {
        if (!message) return;
        var variant = type === 'error' ? 'danger' : 'success';
        if (typeof window.showToast === 'function') {
            window.showToast(message, variant, 5500);
            return;
        }
        showAssignmentNotice(message, type);
    }

    if (flashSuccess) notifyAssignmentResult(flashSuccess, 'success');
    if (flashError)   notifyAssignmentResult(flashError,   'error');

    // Tab init
    var activeTab = window._manageAssignConfig ? window._manageAssignConfig.activeTab : '';
    if (activeTab === 'pages') {
        var tabEl = document.querySelector('#pills-pages-tab');
        if (tabEl) new bootstrap.Tab(tabEl).show();
    } else if (activeTab === 'bulk') {
        var tabEl = document.querySelector('#pills-bulk-tab');
        if (tabEl) new bootstrap.Tab(tabEl).show();
    }

    // Auto-open modal or restore focus after assign
    var params = new URLSearchParams(window.location.search);
    var openPageId = params.get('open_page_id');
    if (openPageId) {
        var modalEl = document.getElementById('pageModal' + openPageId);
        if (modalEl) new bootstrap.Modal(modalEl).show();
    }
    var focusPageId = params.get('focus_page_id');
    if (focusPageId) {
        var btn = document.querySelector('[data-page-edit-id="' + focusPageId + '"]');
        if (btn) {
            btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            btn.focus();
        }
    }

    // Hours validation for team assignment
    var hoursInput = document.getElementById('hoursInput');
    var hoursValidation = document.getElementById('hoursValidation');
    var availableForAllocation = window._manageAssignConfig ? (window._manageAssignConfig.availableForAllocation || 0) : 0;

    if (hoursInput && hoursValidation) {
        hoursInput.addEventListener('input', function () {
            var inputValue = parseFloat(this.value) || 0;
            var assignBtn = document.querySelector('button[name="assign_team"]');
            if (inputValue > availableForAllocation) {
                hoursValidation.innerHTML = '<small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Exceeds available allocation (' + availableForAllocation + ' hours)</small>';
                this.classList.add('is-invalid');
                if (assignBtn) assignBtn.disabled = true;
            } else if (inputValue < 0) {
                hoursValidation.innerHTML = '<small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Hours cannot be negative</small>';
                this.classList.add('is-invalid');
                if (assignBtn) assignBtn.disabled = true;
            } else {
                hoursValidation.innerHTML = '<small class="text-success"><i class="fas fa-check"></i> Valid allocation</small>';
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
                if (assignBtn) assignBtn.disabled = false;
            }
        });
    }

    // Team assignment submit validation
    var teamAssignForm = document.getElementById('teamAssignForm');
    if (teamAssignForm) {
        teamAssignForm.addEventListener('submit', function (e) {
            var selectedUsers = Array.from(teamAssignForm.querySelectorAll('select[name="user_ids[]"] option:checked'));
            if (selectedUsers.length === 0) {
                e.preventDefault();
                if (typeof window.showToast === 'function') showToast('Select at least one user to add.', 'warning');
            }
        });
    }

    // Edit team hours modal
    var editTeamHoursModalEl = document.getElementById('editTeamHoursModal');
    var editTeamHoursForm    = document.getElementById('editTeamHoursForm');
    var editHoursInputEl     = document.getElementById('editMemberNewHours');
    var saveTeamHoursBtn     = document.getElementById('saveTeamHoursBtn');
    var memberNameEl         = document.getElementById('editMemberName');
    var memberCurrentEl      = document.getElementById('editMemberCurrentHours');
    var memberUtilizedEl     = document.getElementById('editMemberUtilizedHours');
    var memberMaxEl          = document.getElementById('editMemberMaxHours');
    var memberHintEl         = document.getElementById('editMemberHoursHint');
    var memberAssignmentIdEl = document.getElementById('editMemberAssignmentId');

    function validateEditHoursInput() {
        if (!editHoursInputEl) return true;
        var minHours      = parseFloat(editHoursInputEl.dataset.min || '0') || 0;
        var maxHours      = parseFloat(editHoursInputEl.dataset.max || '0') || 0;
        var current       = parseFloat(editHoursInputEl.dataset.current || '0') || 0;
        var overAllocated = String(editHoursInputEl.dataset.overAllocated || '0') === '1';
        var value         = parseFloat(editHoursInputEl.value);

        if (!Number.isFinite(value)) {
            editHoursInputEl.setCustomValidity('Please enter valid hours.');
            if (saveTeamHoursBtn) saveTeamHoursBtn.disabled = true;
            if (memberHintEl) memberHintEl.textContent = 'Enter hours to continue.';
            return false;
        }
        if (value < minHours) {
            editHoursInputEl.setCustomValidity('Hours cannot be lower than utilized hours.');
            if (saveTeamHoursBtn) saveTeamHoursBtn.disabled = true;
            if (memberHintEl) memberHintEl.textContent = 'Minimum allowed: ' + minHours.toFixed(1) + 'h';
            return false;
        }
        if (value > maxHours) {
            editHoursInputEl.setCustomValidity('Hours exceed allowed maximum.');
            if (saveTeamHoursBtn) saveTeamHoursBtn.disabled = true;
            if (memberHintEl) {
                memberHintEl.textContent = overAllocated
                    ? 'Project over-allocated. Only decrease allowed. Max: ' + maxHours.toFixed(1) + 'h'
                    : 'Maximum allowed: ' + maxHours.toFixed(1) + 'h';
            }
            return false;
        }
        editHoursInputEl.setCustomValidity('');
        if (saveTeamHoursBtn) saveTeamHoursBtn.disabled = false;
        if (memberHintEl) {
            if (overAllocated && maxHours <= current + 0.0001) {
                memberHintEl.textContent = 'Project is over-allocated; you can decrease or keep current hours.';
            } else {
                memberHintEl.textContent = 'Allowed range: ' + minHours.toFixed(1) + 'h to ' + maxHours.toFixed(1) + 'h';
            }
        }
        return true;
    }

    document.querySelectorAll('.edit-team-hours-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!editTeamHoursModalEl || !window.bootstrap) return;
            var assignmentId  = btn.getAttribute('data-assignment-id') || '';
            var memberName    = btn.getAttribute('data-user-name') || 'Member';
            var currentHours  = parseFloat(btn.getAttribute('data-current-hours') || '0') || 0;
            var utilizedHours = parseFloat(btn.getAttribute('data-utilized-hours') || '0') || 0;
            var maxHours      = parseFloat(btn.getAttribute('data-max-hours') || '0') || 0;
            var overAllocated = btn.getAttribute('data-over-allocated') === '1';

            if (memberAssignmentIdEl) memberAssignmentIdEl.value = assignmentId;
            if (memberNameEl)    memberNameEl.textContent    = memberName;
            if (memberCurrentEl) memberCurrentEl.textContent = 'Current: ' + currentHours.toFixed(1) + 'h';
            if (memberUtilizedEl) memberUtilizedEl.textContent = 'Utilized: ' + utilizedHours.toFixed(1) + 'h';
            if (memberMaxEl)     memberMaxEl.textContent     = 'Max: ' + maxHours.toFixed(1) + 'h';

            if (editHoursInputEl) {
                editHoursInputEl.value = currentHours.toFixed(1);
                editHoursInputEl.min   = utilizedHours.toFixed(1);
                editHoursInputEl.max   = maxHours.toFixed(1);
                editHoursInputEl.dataset.current      = currentHours.toFixed(1);
                editHoursInputEl.dataset.min          = utilizedHours.toFixed(1);
                editHoursInputEl.dataset.max          = maxHours.toFixed(1);
                editHoursInputEl.dataset.overAllocated = overAllocated ? '1' : '0';
            }
            validateEditHoursInput();
            bootstrap.Modal.getOrCreateInstance(editTeamHoursModalEl).show();
        });
    });

    if (editHoursInputEl) editHoursInputEl.addEventListener('input', validateEditHoursInput);
    if (editTeamHoursForm) {
        editTeamHoursForm.addEventListener('submit', function (e) {
            if (!validateEditHoursInput()) e.preventDefault();
        });
    }
});

// ── Bulk assignment helper functions ──
function selectAllPages() {
    document.querySelectorAll('.page-checkbox').forEach(function (cb) { cb.checked = true; });
    updateBulkPreview();
}
function clearAllPages() {
    document.querySelectorAll('.page-checkbox').forEach(function (cb) { cb.checked = false; });
    updateBulkPreview();
}
function selectAllEnvs() {
    document.querySelectorAll('.env-checkbox').forEach(function (cb) { cb.checked = true; });
    updateBulkPreview();
}
function clearAllEnvs() {
    document.querySelectorAll('.env-checkbox').forEach(function (cb) { cb.checked = false; });
    updateBulkPreview();
}

function updateBulkPreview() {
    var selectedPages = document.querySelectorAll('.page-checkbox:checked');
    var selectedEnvs  = document.querySelectorAll('.env-checkbox:checked');
    var atTester = document.querySelector('select[name="bulk_at_tester"]').selectedOptions[0]?.text || 'None';
    var ftTester = document.querySelector('select[name="bulk_ft_tester"]').selectedOptions[0]?.text || 'None';
    var qa       = document.querySelector('select[name="bulk_qa"]').selectedOptions[0]?.text || 'None';
    var previewDiv = document.getElementById('bulk-preview');

    if (selectedPages.length === 0 || selectedEnvs.length === 0) {
        previewDiv.innerHTML = '<span class="text-muted">Select pages, testers/QA, and environments to see preview...</span>';
        return;
    }

    var html = '<div class="row">';
    html += '<div class="col-md-6"><h6>Selected Pages (' + selectedPages.length + '):</h6><ul class="list-unstyled small">';
    selectedPages.forEach(function (page) {
        var label = page.nextElementSibling.querySelector('strong').textContent;
        html += '<li>• ' + label + '</li>';
    });
    html += '</ul></div>';
    html += '<div class="col-md-6"><h6>Assignment Details:</h6>';
    html += '<p class="small mb-1"><strong>AT Tester:</strong> ' + atTester + '</p>';
    html += '<p class="small mb-1"><strong>FT Tester:</strong> ' + ftTester + '</p>';
    html += '<p class="small mb-1"><strong>QA:</strong> ' + qa + '</p>';
    html += '<p class="small mb-1"><strong>Environments (' + selectedEnvs.length + '):</strong></p><ul class="list-unstyled small">';
    selectedEnvs.forEach(function (env) {
        html += '<li>• ' + env.nextElementSibling.textContent + '</li>';
    });
    html += '</ul></div></div>';
    previewDiv.innerHTML = html;
}

// ── Second DOMContentLoaded block (modal mount + bulk preview listeners) ──
document.addEventListener('DOMContentLoaded', function () {
    // Ensure modals are mounted under body
    document.querySelectorAll('.modal').forEach(function (modalEl) {
        if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);
    });

    document.querySelectorAll('.page-checkbox').forEach(function (cb) {
        cb.addEventListener('change', updateBulkPreview);
    });
    document.querySelectorAll('.env-checkbox').forEach(function (cb) {
        cb.addEventListener('change', updateBulkPreview);
    });
    document.querySelectorAll('select[name^="bulk_"]').forEach(function (sel) {
        sel.addEventListener('change', updateBulkPreview);
    });
});

function showQuickAssignModal() {
    var modal = new bootstrap.Modal(document.getElementById('quickAssignModal'));
    modal.show();
}
function selectAllQuickEnvs() {
    document.querySelectorAll('.quick-env-checkbox').forEach(function (cb) { cb.checked = true; });
}
function clearAllQuickEnvs() {
    document.querySelectorAll('.quick-env-checkbox').forEach(function (cb) { cb.checked = false; });
}

function toggleRowDetails(pageId, context) {
    context = context || 'main';
    var detailsRowId = context === 'nested' ? 'details-nested-' + pageId : 'details-' + pageId;
    var detailsRow   = document.getElementById(detailsRowId);
    var eyeIcon      = document.getElementById('eye-' + (context === 'nested' ? 'nested-' : '') + pageId);

    if (!detailsRow) { console.error('Details row not found:', detailsRowId); return; }

    if (detailsRow.classList.contains('show')) {
        detailsRow.classList.remove('show');
        var mainEye   = document.getElementById('eye-' + pageId);
        var nestedEye = document.getElementById('eye-nested-' + pageId);
        if (mainEye)   { mainEye.classList.remove('fa-eye-slash');   mainEye.classList.add('fa-eye'); }
        if (nestedEye) { nestedEye.classList.remove('fa-eye-slash'); nestedEye.classList.add('fa-eye'); }
    } else {
        document.querySelectorAll('[id^="details-"]').forEach(function (row) { row.classList.remove('show'); });
        document.querySelectorAll('[id^="eye-"]').forEach(function (icon) {
            icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
        });
        detailsRow.classList.add('show');
        if (eyeIcon) { eyeIcon.classList.remove('fa-eye'); eyeIcon.classList.add('fa-eye-slash'); }
    }
}

// ── Multiselect delete (jQuery) ──
$(document).ready(function () {
    $('#selectAllPages').on('change', function () {
        $('.page-select').prop('checked', $(this).prop('checked'));
        updateSelectedPagesCount();
    });
    $('.page-select').on('change', function () {
        updateSelectedPagesCount();
        var total   = $('.page-select').length;
        var checked = $('.page-select:checked').length;
        $('#selectAllPages').prop('checked', total === checked);
    });

    function updateSelectedPagesCount() {
        var count = $('.page-select:checked').length;
        $('#selectedPagesCount').text(count);
        $('#bulkDeletePagesBtn').prop('disabled', count === 0);
    }

    $('#bulkDeletePagesBtn').on('click', function () {
        var selectedIds = [];
        $('.page-select:checked').each(function () { selectedIds.push($(this).val()); });
        if (selectedIds.length === 0) return;

        var message = 'Are you sure you want to delete ' + selectedIds.length + ' page(s)? This will also delete all environment assignments and cannot be undone.';
        var projectId = window._manageAssignConfig ? window._manageAssignConfig.projectId : 0;

        function submitBulkDelete() {
            var form = $('<form>', { method: 'POST', action: window.location.href });
            form.append($('<input>', { type: 'hidden', name: 'bulk_delete_pages', value: '1' }));
            form.append($('<input>', { type: 'hidden', name: 'project_id', value: projectId }));
            form.append($('<input>', { type: 'hidden', name: 'page_ids', value: selectedIds.join(',') }));
            $('body').append(form);
            form.submit();
        }

        if (typeof confirmModal === 'function') {
            confirmModal(message, submitBulkDelete);
        } else if (confirm(message)) {
            submitBulkDelete();
        }
    });
});
