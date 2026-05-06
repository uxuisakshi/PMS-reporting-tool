    </main>
    </div>
    <div class="mt-auto py-3 border-top text-center text-white small" style="background-color: #0755C6 !important; background-color: var(--primary) !important;">
        <div class="container-fluid">
            &copy; <?php echo date('Y'); ?> PMS. All rights reserved.
        </div>
    </div>
    
    <?php
    if (!isset($baseDir)) {
        require_once __DIR__ . '/helpers.php';
        $baseDir = getBaseDir();
    }
    ?>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/script.js?v=20260225v2"></script>
    <script nonce="<?php echo $cspNonce ?? ''; ?>">
    $(document).ready(function() {
        $(document).on('click', '.status-update-link', function(e) {
            e.preventDefault();
            const link = $(this);
            const pageId = link.data('page-id');
            const envId = link.data('environment-id');
            const status = link.data('status');
            const action = link.data('action');
            const badge = link.closest('.status-dropdown-group').find('.dropdown-toggle');
            
            // Show loading state
            badge.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            
            $.ajax({
                url: '<?php echo $baseDir; ?>/api/status.php',
                method: 'POST',
                data: {
                    action: action,
                    page_id: pageId,
                    environment_id: envId,
                    status: status
                },
                success: function(response) {
                    if (response.success) {
                        // Update the badge text and class
                        badge.text(link.text());
                        
                        // Reset classes
                        badge.removeClass('btn-outline-success btn-outline-danger btn-outline-primary btn-outline-warning btn-outline-secondary btn-outline-info');
                        
                        let newClass = 'btn-outline-secondary';
                        const s = status.toLowerCase();
                        if (s === 'pass' || s === 'completed') newClass = 'btn-outline-success';
                        else if (s === 'fail' || s === 'in_fixing') newClass = 'btn-outline-danger';
                        else if (s === 'in_progress') newClass = 'btn-outline-primary';
                        else if (s === 'on_hold' || s === 'qa_in_progress') newClass = 'btn-outline-warning';
                        else if (s === 'needs_review') newClass = 'btn-outline-info';
                        
                        badge.addClass(newClass);
                        
                        // If it was an environment update, we might need to update global status badge if it exists on page
                        if (action === 'update_env_status' && response.global_status) {
                            const globalBadge = $('#page-status-' + pageId);
                            if (globalBadge.length) {
                                globalBadge.text(response.global_status_label);
                                globalBadge.removeClass('btn-outline-success btn-outline-danger btn-outline-primary btn-outline-warning btn-outline-secondary btn-outline-info');
                                
                                let gClass = 'btn-outline-secondary';
                                const gs = response.global_status;
                                if (gs === 'completed') gClass = 'btn-outline-success';
                                else if (gs === 'in_fixing') gClass = 'btn-outline-danger';
                                else if (gs === 'in_progress') gClass = 'btn-outline-primary';
                                else if (gs === 'qa_in_progress') gClass = 'btn-outline-warning';
                                
                                globalBadge.addClass(gClass);
                            }
                        }
                        
                        // Update active state in dropdown
                        link.closest('.dropdown-menu').find('.dropdown-item').removeClass('active');
                        link.addClass('active');
                    } else {
                        showToast('Error: ' + (response.message || 'Unknown error'), 'danger');
                    }
                },
                error: function() {
                    showToast('An error occurred while updating status.', 'danger');
                },
                complete: function() {
                    badge.prop('disabled', false);
                }
            });
        });

        // Notifications are handled from includes/header.php via /api/status.php.
        // Keep footer free from duplicate notification polling to avoid badge conflicts.
        // Fix: ensure modals render fully on first show (force reflow + focus)
        $(document).on('show.bs.modal', '.modal', function () {
            const el = this;
            // Force a reflow to ensure CSS transitions/rendering complete
            // reading offsetHeight forces reflow
            void el.offsetHeight;
        });

        $(document).on('shown.bs.modal', '.modal', function () {
            const modal = $(this);
            // Defer focus to next tick to avoid interfering with Bootstrap's focus management
            setTimeout(function() {
                const focusable = modal.find('button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible').first();
                if (focusable.length) focusable.focus();
                else modal.focus();
            }, 0);
        });
    });
    </script>
    <!-- Global Custom Confirmation Modal -->
    <div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-labelledby="globalConfirmModalLabel" aria-hidden="true" style="z-index: 10700;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="globalConfirmModalLabel"><i class="fas fa-exclamation-triangle text-warning me-2"></i> Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3" id="globalConfirmModalMessage">
                    Are you sure you want to proceed?
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary px-3 d-none" id="globalConfirmDenyBtn">Discard</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" id="globalConfirmCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-danger px-4" id="globalConfirmModalBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?php echo $cspNonce ?? ''; ?>">
    // Global Confirm Modal Function
    window.confirmModal = function(message, callback, options = {}) {
        const modalEl = document.getElementById('globalConfirmModal');
        if (!modalEl) {
            if (typeof callback === 'function') callback(true);
            return;
        }
        const btnConfirmEl = document.getElementById('globalConfirmModalBtn');
        const btnDenyEl = document.getElementById('globalConfirmDenyBtn');
        const btnCancelEl = document.getElementById('globalConfirmCancelBtn');
        const msgEl = document.getElementById('globalConfirmModalMessage');
        const titleEl = document.getElementById('globalConfirmModalLabel');

        if (msgEl) msgEl.innerHTML = message || 'Are you sure you want to proceed?';
        if (titleEl && options.title) {
            titleEl.innerHTML = (options.icon || '<i class="fas fa-exclamation-triangle text-warning me-2"></i>') + options.title;
        }

        if (btnConfirmEl) {
            btnConfirmEl.textContent = options.confirmText || 'Confirm';
            btnConfirmEl.classList.remove('btn-danger', 'btn-primary', 'btn-success', 'btn-warning');
            btnConfirmEl.classList.add(options.confirmClass || 'btn-danger');
        }
        if (btnDenyEl) {
            btnDenyEl.textContent = options.denyText || 'Discard';
            btnDenyEl.classList.toggle('d-none', !options.showDeny);
        }
        if (btnCancelEl) btnCancelEl.textContent = options.cancelText || 'Cancel';

        const modalApi = (window.bootstrap && bootstrap.Modal) ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        const $modal = window.jQuery ? window.jQuery(modalEl) : null;
        const showModal = function() {
            if (modalApi) modalApi.show();
            else if ($modal && typeof $modal.modal === 'function') $modal.modal('show');
        };
        const hideModal = function() {
            if (modalApi) modalApi.hide();
            else if ($modal && typeof $modal.modal === 'function') $modal.modal('hide');
        };

        if (btnConfirmEl) {
            btnConfirmEl.onclick = function() {
                hideModal();
                if (typeof callback === 'function') callback(true);
            };
        }
        if (btnDenyEl) {
            btnDenyEl.onclick = function() {
                hideModal();
                if (typeof callback === 'function') callback('deny');
            };
        }

        showModal();
    };
    </script>
</body>
</html>
