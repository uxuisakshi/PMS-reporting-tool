/**
 * uploads-manager.js
 */
(function () {

    // ── Bulk select / delete ─────────────────────────────────────────────────
    var selectAll   = document.getElementById('umSelectAll');
    var bulkBar     = document.getElementById('umBulkBar');
    var bulkCount   = document.getElementById('umBulkCount');
    var bulkInputs  = document.getElementById('umBulkInputs');
    var bulkForm    = document.getElementById('umBulkForm');
    var bulkDelBtn  = document.getElementById('umBulkDeleteBtn');
    var bulkClrBtn  = document.getElementById('umBulkClearBtn');

    function getChecked() {
        return Array.from(document.querySelectorAll('.um-row-check:checked'));
    }

    function updateBulkBar() {
        var checked = getChecked();
        if (checked.length > 0) {
            bulkBar.classList.remove('d-none');
            bulkBar.classList.add('d-flex');
            bulkCount.textContent = checked.length + ' file' + (checked.length !== 1 ? 's' : '') + ' selected';
        } else {
            bulkBar.classList.add('d-none');
            bulkBar.classList.remove('d-flex');
        }
        // Sync select-all checkbox state
        var all = document.querySelectorAll('.um-row-check');
        if (selectAll) {
            selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
            selectAll.checked = all.length > 0 && checked.length === all.length;
        }
    }

    // Select all toggle
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.um-row-check').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateBulkBar();
        });
    }

    // Individual checkbox change
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('um-row-check')) {
            updateBulkBar();
        }
    });

    // Clear selection
    if (bulkClrBtn) {
        bulkClrBtn.addEventListener('click', function () {
            document.querySelectorAll('.um-row-check').forEach(function (cb) { cb.checked = false; });
            if (selectAll) selectAll.checked = false;
            updateBulkBar();
        });
    }

    // Bulk delete button
    if (bulkDelBtn) {
        bulkDelBtn.addEventListener('click', function () {
            var checked = getChecked();
            if (checked.length === 0) return;

            var names = checked.slice(0, 5).map(function (cb) {
                return '<li>' + escHtml(cb.getAttribute('data-name') || cb.value) + '</li>';
            }).join('');
            var moreText = checked.length > 5 ? '<li class="text-muted">… and ' + (checked.length - 5) + ' more</li>' : '';

            var bodyHtml = [
                '<div class="alert alert-danger mb-3">',
                  '<i class="fas fa-exclamation-triangle me-2"></i>',
                  '<strong>This action is permanent and cannot be undone.</strong>',
                '</div>',
                '<p>You are about to delete <strong>' + checked.length + ' file' + (checked.length !== 1 ? 's' : '') + '</strong>:</p>',
                '<ul class="small mb-0">' + names + moreText + '</ul>'
            ].join('');

            showConfirmModal(
                '<i class="fas fa-trash-alt text-danger me-2"></i>Bulk Delete Files',
                bodyHtml,
                function () {
                    // Build hidden inputs
                    bulkInputs.innerHTML = '';
                    checked.forEach(function (cb) {
                        var inp = document.createElement('input');
                        inp.type  = 'hidden';
                        inp.name  = 'selected_files[]';
                        inp.value = cb.value;
                        bulkInputs.appendChild(inp);
                    });
                    bulkForm.submit();
                },
                'btn-danger',
                'Yes, Delete ' + checked.length + ' File' + (checked.length !== 1 ? 's' : '')
            );
        });
    }

    // ── Scope toggle (Project / User) ────────────────────────────────────────
    var scopeType   = document.getElementById('uploadScopeType');
    var projectWrap = document.getElementById('uploadScopeProjectWrap');
    var userWrap    = document.getElementById('uploadScopeUserWrap');

    function syncScope() {
        if (!scopeType) return;
        if (scopeType.value === 'user') {
            projectWrap && projectWrap.classList.add('d-none');
            userWrap    && userWrap.classList.remove('d-none');
        } else {
            userWrap    && userWrap.classList.add('d-none');
            projectWrap && projectWrap.classList.remove('d-none');
        }
    }
    if (scopeType) {
        scopeType.addEventListener('change', syncScope);
        syncScope();
    }

    // ── Helper: show a Bootstrap confirmation modal ──────────────────────────
    function showConfirmModal(title, bodyHtml, onConfirm, confirmBtnClass, confirmBtnText) {
        confirmBtnClass = confirmBtnClass || 'btn-danger';
        confirmBtnText  = confirmBtnText  || 'Confirm';

        // Remove any existing instance
        var old = document.getElementById('umConfirmModal');
        if (old) old.remove();

        var modal = document.createElement('div');
        modal.id = 'umConfirmModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = [
            '<div class="modal-dialog modal-dialog-centered">',
              '<div class="modal-content">',
                '<div class="modal-header">',
                  '<h5 class="modal-title">' + title + '</h5>',
                  '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>',
                '</div>',
                '<div class="modal-body">' + bodyHtml + '</div>',
                '<div class="modal-footer">',
                  '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>',
                  '<button type="button" class="btn ' + confirmBtnClass + '" id="umConfirmBtn">' + confirmBtnText + '</button>',
                '</div>',
              '</div>',
            '</div>'
        ].join('');

        document.body.appendChild(modal);

        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        document.getElementById('umConfirmBtn').addEventListener('click', function () {
            bsModal.hide();
            onConfirm();
        });

        modal.addEventListener('hidden.bs.modal', function () {
            modal.remove();
        });
    }

    // ── Cleanup form — preview before delete ─────────────────────────────────
    var cleanupForm = document.getElementById('cleanupForm');
    if (cleanupForm) {
        cleanupForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var scope     = document.getElementById('uploadScopeType').value;
            var projectId = (document.querySelector('[name="project_id"]') || {}).value || '';
            var userId    = (document.querySelector('[name="user_id"]') || {}).value || '';

            if (scope === 'project' && !projectId) {
                alert('Please select a project first.');
                return;
            }
            if (scope === 'user' && !userId) {
                alert('Please select a user first.');
                return;
            }

            // Fetch preview count via AJAX
            var params = new URLSearchParams({
                action:     'preview_cleanup',
                scope_type: scope,
                project_id: projectId,
                user_id:    userId
            });

            var btn = cleanupForm.querySelector('button[type="submit"]') ||
                      cleanupForm.querySelector('button');
            var origText = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...'; }

            fetch(window.location.pathname + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btn) { btn.disabled = false; btn.innerHTML = origText; }

                if (!data.success) {
                    alert(data.message || 'Could not fetch preview.');
                    return;
                }

                if (data.count === 0) {
                    showConfirmModal(
                        '<i class="fas fa-info-circle text-info me-2"></i>No Files Found',
                        '<p class="mb-0">No matching upload records found for <strong>' + escHtml(data.label) + '</strong>. Nothing will be deleted.</p>',
                        function () {},
                        'btn-secondary',
                        'OK'
                    );
                    return;
                }

                var bodyHtml = [
                    '<div class="alert alert-warning mb-3">',
                      '<i class="fas fa-exclamation-triangle me-2"></i>',
                      '<strong>This action is irreversible.</strong> Physical files will be permanently deleted from the server.',
                    '</div>',
                    '<table class="table table-sm mb-0">',
                      '<tr><th>Scope</th><td>' + escHtml(data.scope_type === 'project' ? 'Project' : 'User') + '</td></tr>',
                      '<tr><th>Target</th><td>' + escHtml(data.label) + '</td></tr>',
                      '<tr><th>Files to delete</th><td><span class="badge bg-danger fs-6">' + data.count + '</span></td></tr>',
                    '</table>'
                ].join('');

                showConfirmModal(
                    '<i class="fas fa-trash-alt text-danger me-2"></i>Confirm Bulk Delete',
                    bodyHtml,
                    function () { cleanupForm.submit(); },
                    'btn-danger',
                    'Yes, Delete ' + data.count + ' File' + (data.count !== 1 ? 's' : '')
                );
            })
            .catch(function () {
                if (btn) { btn.disabled = false; btn.innerHTML = origText; }
                alert('Failed to fetch preview. Please try again.');
            });
        });
    }

    // ── Individual file delete — confirmation with filename ──────────────────
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList.contains('um-delete-form')) return;
        e.preventDefault();

        var filename = form.getAttribute('data-filename') || 'this file';

        var bodyHtml = [
            '<div class="alert alert-warning mb-3">',
              '<i class="fas fa-exclamation-triangle me-2"></i>',
              '<strong>This action cannot be undone.</strong>',
            '</div>',
            '<p>Are you sure you want to permanently delete:</p>',
            '<p class="fw-semibold text-danger mb-0"><i class="fas fa-file me-1"></i>' + escHtml(filename) + '</p>'
        ].join('');

        showConfirmModal(
            '<i class="fas fa-trash-alt text-danger me-2"></i>Delete File',
            bodyHtml,
            function () { form.submit(); },
            'btn-danger',
            'Yes, Delete File'
        );
    });

    // ── HTML escape helper ───────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
