$(document).ready(function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#usersTable')) {
        $('#usersTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false }
            ],
            language: {
                search: 'Filter:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries'
            }
        });
    }

    function initCredentialsLogsTable() {
        if (!$.fn.DataTable || $.fn.DataTable.isDataTable('#credentialsMailLogsTable')) return;
        $('#credentialsMailLogsTable').DataTable({
            pageLength: 10,
            lengthMenu: [[10, 25, 50], [10, 25, 50]],
            paging: true,
            searching: true,
            info: true,
            autoWidth: false,
            order: [[0, 'desc']],
            columnDefs: [
                { targets: [2, 3], orderable: false }
            ],
            language: {
                search: 'Filter logs:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries'
            }
        });
    }

    $('#mailLogsCollapse').on('shown.bs.collapse', function() {
        initCredentialsLogsTable();
        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#credentialsMailLogsTable')) {
            $('#credentialsMailLogsTable').DataTable().columns.adjust().draw(false);
        }
    });

    if ($('#mailLogsCollapse').hasClass('show')) {
        initCredentialsLogsTable();
    }

    $('form').on('submit', function() {
        var password = $('input[name="password"]').val();
        var confirmPassword = $('input[name="confirm_password"]').val();
        if (password && confirmPassword && password !== confirmPassword) {
            showToast('Passwords do not match!', 'warning');
            return false;
        }
        return true;
    });

    $(document).on('submit', 'form[data-reset-password-form="1"]', function(e) {
        e.preventDefault();
        const form = this;
        const $form = $(form);
        const $submitBtn = $form.find('button[type="submit"][name="reset_password"]');
        const newPassword = String($form.find('input[name="new_password"]').val() || '');
        const confirmPassword = String($form.find('input[name="confirm_password"]').val() || '');

        if (newPassword.length < 6) {
            showToast('Password must be at least 6 characters.', 'warning');
            return false;
        }
        if (newPassword !== confirmPassword) {
            showToast('Passwords do not match!', 'warning');
            return false;
        }

        const oldBtnHtml = $submitBtn.html();
        $submitBtn.prop('disabled', true).html('Resetting...');

        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            dataType: 'json',
            timeout: 60000,
            data: $form.serialize() + '&reset_password=1',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function(resp) {
            if (resp && resp.success) {
                showToast(resp.message || 'Password reset successfully.', 'success');
                try {
                    const modalEl = form.closest('.modal');
                    if (modalEl) {
                        const instance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
                        instance.hide();
                    }
                } catch (err) {}
                form.reset();
            } else {
                showToast((resp && resp.error) ? resp.error : 'Failed to reset password.', 'danger');
            }
        }).fail(function(xhr) {
            let msg = 'Failed to reset password.';
            try {
                const j = xhr.responseJSON;
                if (j && (j.error || j.message)) msg = j.error || j.message;
            } catch (err) {}
            showToast(msg, 'danger');
        }).always(function() {
            $submitBtn.prop('disabled', false).html(oldBtnHtml);
        });

        return false;
    });

    const selectedUserIds = new Set();

    function getVisibleUserIds() {
        return $('.user-select').map(function() {
            return String($(this).val());
        }).get();
    }

    function getSelectedUserIds() {
        return Array.from(selectedUserIds);
    }

    function syncVisibleSelectionState() {
        $('.user-select').each(function() {
            const uid = String($(this).val());
            $(this).prop('checked', selectedUserIds.has(uid));
        });
        const visibleIds = getVisibleUserIds();
        const total = visibleIds.length;
        let checked = 0;
        visibleIds.forEach(function(uid) {
            if (selectedUserIds.has(uid)) checked++;
        });
        const allChecked = total > 0 && checked === total;
        const partial = checked > 0 && checked < total;
        $('#selectAllUsers').prop('checked', allChecked).prop('indeterminate', partial);
    }

    function refreshBulkMailUi() {
        const selected = getSelectedUserIds();
        const count = selected.length;
        $('#bulkMailBtn').prop('disabled', count === 0);
        $('#bulk2FAReminderBtn').prop('disabled', count === 0);
        $('#selectedUsersHint').text(count + ' users selected');
        $('#selectedUsersCount').text(count);
        $('#selectedUserIdsInput').val(selected.join(','));
        syncVisibleSelectionState();
    }

    $(document).on('click', '#selectAllUsers', function(e) {
        e.stopPropagation();
        e.preventDefault();
        const visibleIds = getVisibleUserIds();
        if (!visibleIds.length) {
            refreshBulkMailUi();
            return;
        }
        const allVisibleSelected = visibleIds.every(function(uid) {
            return selectedUserIds.has(uid);
        });
        if (allVisibleSelected) {
            visibleIds.forEach(function(uid) { selectedUserIds.delete(uid); });
        } else {
            visibleIds.forEach(function(uid) { selectedUserIds.add(uid); });
        }
        refreshBulkMailUi();
    });

    $(document).on('change', '.user-select', function() {
        const uid = String($(this).val());
        if ($(this).is(':checked')) {
            selectedUserIds.add(uid);
        } else {
            selectedUserIds.delete(uid);
        }
        refreshBulkMailUi();
    });

    $('#usersTable').on('draw.dt', function() {
        syncVisibleSelectionState();
    });

    $(document).on('click', '.view-user-btn', function() {
        var uid = $(this).data('user-id');
        $('#viewUserContent').html('<p><strong>Loading...</strong></p>');
        var modal = new bootstrap.Modal(document.getElementById('viewUserModal'));
        modal.show();

        $.ajax({
            url: window._adminUsersConfig.baseDir + '/modules/admin/users.php',
            method: 'GET',
            data: { action: 'get_user_details', user_id: uid },
            success: function(resp) {
                try {
                    var data = typeof resp === 'object' ? resp : JSON.parse(resp);
                    if (data.error) {
                        $('#viewUserContent').html('<p class="text-danger">Error: ' + $('<div>').text(data.error).html() + '</p>');
                        return;
                    }
                    if (!data.user) {
                        $('#viewUserContent').html('<p class="text-danger">User not found.</p>');
                        return;
                    }

                    var html = [];
                    html.push('<h5>' + $('<div>').text(data.user.full_name).html() + ' <small class="text-muted">(' + $('<div>').text(data.user.username).html() + ')</small></h5>');
                    html.push('<p><strong>Email:</strong> ' + $('<div>').text(data.user.email).html() + ' &nbsp; <strong>Role:</strong> ' + $('<div>').text(data.user.role).html() + '</p>');
                    if (data.user.can_manage_issue_config == 1) {
                        html.push('<p><span class="badge bg-primary me-2">Has Issue Config Access</span></p>');
                    }
                    if (data.user.can_manage_devices == 1) {
                        html.push('<p><span class="badge bg-success">Can Manage Devices</span></p>');
                    }

                    html.push('<h6>Projects (' + (data.projects ? data.projects.length : 0) + ')</h6>');
                    if (data.projects && data.projects.length) {
                        html.push('<ul>');
                        data.projects.forEach(function(p) {
                            html.push('<li>' + $('<div>').text(p.title).html() + ' <small class="text-muted">(' + $('<div>').text(p.po_number||'').html() + ')</small></li>');
                        });
                        html.push('</ul>');
                    } else {
                        html.push('<p class="text-muted">No projects.</p>');
                    }

                    html.push('<h6>Pages (' + (data.pages ? data.pages.length : 0) + ')</h6>');
                    if (data.pages && data.pages.length) {
                        html.push('<ul>');
                        data.pages.forEach(function(pg) {
                            html.push('<li>' + $('<div>').text(pg.title).html() + ' <small class="text-muted">(ID ' + pg.id + ')</small></li>');
                        });
                        html.push('</ul>');
                    } else {
                        html.push('<p class="text-muted">No pages.</p>');
                    }

                    html.push('<h6>Assignments (' + (data.assignments ? data.assignments.length : 0) + ')</h6>');
                    if (data.assignments && data.assignments.length) {
                        html.push('<table class="table table-sm"><thead><tr><th>Project</th><th>Role</th><th>Assigned By</th><th>At</th></tr></thead><tbody>');
                        data.assignments.forEach(function(a) {
                            var proj = a.project_title ? $('<div>').text(a.project_title).html() : (a.project_id || 'N/A');
                            var by = a.assigned_by_name ? $('<div>').text(a.assigned_by_name).html() : (a.assigned_by || 'System');
                            html.push('<tr><td>' + proj + '</td><td>' + (a.role||'') + '</td><td>' + by + '</td><td>' + (a.assigned_at||'') + '</td></tr>');
                        });
                        html.push('</tbody></table>');
                    } else {
                        html.push('<p class="text-muted">No assignments.</p>');
                    }

                    html.push('<h6>Recent Activity (' + (data.activity ? data.activity.length : 0) + ')</h6>');
                    if (data.activity && data.activity.length) {
                        html.push('<ul>');
                        data.activity.forEach(function(a) {
                            var entity = '';
                            if (a.entity_type === 'project' && a.project_title) {
                                entity = ' - Project: <strong>' + $('<div>').text(a.project_title).html() + '</strong>';
                            } else if (a.entity_type && a.entity_id) {
                                entity = ' - ' + a.entity_type + ' ' + a.entity_id;
                            }
                            if (a.details) {
                                try {
                                    var details = JSON.parse(a.details);
                                    if (details.title) entity += ' ("' + $('<div>').text(details.title).html() + '")';
                                    else if (details.page_name) entity += ' (Page: "' + $('<div>').text(details.page_name).html() + '")';
                                    else if (details.asset_name) entity += ' (Asset: "' + $('<div>').text(details.asset_name).html() + '")';
                                } catch(e) {}
                            }
                            html.push('<li><small class="text-muted">[' + (a.created_at||'') + ']</small> ' + $('<div>').text(a.action).html() + entity + '</li>');
                        });
                        html.push('</ul>');
                    } else {
                        html.push('<p class="text-muted">No recent activity.</p>');
                    }

                    $('#viewUserContent').html(html.join(''));
                } catch (e) {
                    $('#viewUserContent').html('<p class="text-danger">Failed to load details. Server response:<pre>' + $('<div>').text(resp).html() + '</pre></p>');
                    console.error('Failed parsing user-details response', resp, e);
                }
            },
            error: function(xhr) {
                var body = xhr.responseText || xhr.statusText || '';
                $('#viewUserContent').html('<p class="text-danger">Request failed: ' + xhr.status + ' ' + $('<div>').text(body).html() + '</p>');
                console.error('User details request failed', xhr.status, body);
            }
        });
    });

    // Handle send reset email button click
    $('.send-reset-email-btn').on('click', function() {
        const userId = $(this).data('user-id');
        const username = $(this).data('username');
        const btn = $(this);

        const modalHtml = `
            <div class="modal fade" id="confirmResetEmailModal" tabindex="-1" aria-labelledby="confirmResetEmailModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmResetEmailModalLabel">
                                <i class="fas fa-paper-plane text-primary"></i> Send Password Reset Email
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to send a password reset email to <strong>${username}</strong>?</p>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle"></i> A new temporary password will be generated and sent to the user's email address.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirmSendResetEmail">
                                <i class="fas fa-paper-plane"></i> Send Email
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#confirmResetEmailModal').remove();
        $('body').append(modalHtml);

        const modal = new bootstrap.Modal(document.getElementById('confirmResetEmailModal'));
        modal.show();

        $('#confirmSendResetEmail').off('click').on('click', function() {
            const confirmBtn = $(this);
            confirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

            const requestId = 'reset_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: {
                    send_credentials_email_single: 1,
                    user_id: userId,
                    mail_mode: 'reset',
                    request_id: requestId,
                    csrf_token: window._csrfToken || (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || ''
                },
                dataType: 'json',
                success: function(response) {
                    modal.hide();
                    if (response.success) {
                        if (typeof showToast === 'function') {
                            showToast(`Password reset email sent to ${response.username || username}`, 'success');
                        } else {
                            alert(`Password reset email sent to ${response.username || username}`);
                        }
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        const errorMsg = response.error || 'Failed to send reset email';
                        if (typeof showToast === 'function') {
                            showToast(errorMsg, 'warning');
                        } else {
                            alert(errorMsg);
                        }
                        setTimeout(function() { location.reload(); }, 2000);
                    }
                },
                error: function(xhr) {
                    modal.hide();
                    const errorMsg = 'Error sending reset email: ' + (xhr.responseText || xhr.statusText);
                    if (typeof showToast === 'function') {
                        showToast(errorMsg, 'danger');
                    } else {
                        alert(errorMsg);
                    }
                    btn.prop('disabled', false).html('<i class="fas fa-key"></i>');
                }
            });
        });

        $('#confirmResetEmailModal').on('hidden.bs.modal', function() {
            $(this).remove();
        });
    });

    // Handle Manual Reset Button
    $(document).on('click', '.manual-reset-password-btn', function() {
        const uid = $(this).data('user-id');
        const username = $(this).data('username');
        
        $('#manualResetUserId').val(uid);
        $('#manualResetUsername').text(username);
        $('#manualResetPasswordInput, #manualResetConfirmInput').val('');
        
        const modal = new bootstrap.Modal(document.getElementById('manualResetPasswordModal'));
        modal.show();
    });

    // Random password generator helper
    $('#generateRandomPass').on('click', function() {
        const chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#$%&*";
        let pass = "";
        for (let i = 0; i < 12; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        $('#manualResetPasswordInput, #manualResetConfirmInput').val(pass);
        showToast('Temporary password generated. Keep it safe!', 'info');
    });

    // Handle 2FA Reminder button click
    $(document).on('click', '.send-2fa-reminder-btn', function() {
        const userId = $(this).data('user-id');
        const fullName = $(this).data('fullname');
        const btn = $(this);

        const modalHtml = `
            <div class="modal fade" id="confirm2FAReminderModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-shield-alt text-primary"></i> Send 2FA Reminder
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Send a 2FA configuration reminder email to <strong>${fullName}</strong>?</p>
                            <div class="alert alert-primary py-2 small mb-0">
                                <i class="fas fa-info-circle"></i> This will send a professional security instruction email to help them set up their account.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirmSend2FA">
                                <i class="fas fa-paper-plane"></i> Send Reminder
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#confirm2FAReminderModal').remove();
        $('body').append(modalHtml);
        const modal = new bootstrap.Modal(document.getElementById('confirm2FAReminderModal'));
        modal.show();

        $('#confirmSend2FA').on('click', function() {
            const confirmBtn = $(this);
            confirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

            $.ajax({
                url: window._adminUsersConfig.baseDir + '/api/admin_2fa_reminder.php',
                method: 'POST',
                data: {
                    user_id: userId,
                    csrf_token: window._csrfToken || (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || ''
                },
                dataType: 'json',
                success: function(response) {
                    modal.hide();
                    if (response.success) {
                        showToast(response.message || 'Reminder sent successfully.', 'success');
                    } else {
                        showToast(response.error || 'Failed to send reminder.', 'warning');
                    }
                },
                error: function(xhr) {
                    modal.hide();
                    showToast('Error: ' + (xhr.responseText || xhr.statusText), 'danger');
                },
                complete: function() {
                    btn.prop('disabled', false).html('<i class="fas fa-shield-alt"></i>');
                }
            });
        });
    });

    // Handle Bulk 2FA Reminder button click
    $(document).on('click', '#bulk2FAReminderBtn', function() {
        const selected = getSelectedUserIds();
        if (!selected.length) return;

        const modalHtml = `
            <div class="modal fade" id="confirmBulk2FAReminderModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-shield-alt text-primary"></i> Bulk 2FA Reminder
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Send 2FA configuration reminder emails to <strong>${selected.length}</strong> selected users?</p>
                            <div class="alert alert-info py-2 small mb-0">
                                <i class="fas fa-paper-plane"></i> Emails will be sent individually to all selected accounts.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirmSendBulk2FA">
                                <i class="fas fa-paper-plane"></i> Send to ${selected.length} Users
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#confirmBulk2FAReminderModal').remove();
        $('body').append(modalHtml);
        const modal = new bootstrap.Modal(document.getElementById('confirmBulk2FAReminderModal'));
        modal.show();

        $('#confirmSendBulk2FA').on('click', function() {
            const confirmBtn = $(this);
            confirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
            const bulkBtn = $('#bulk2FAReminderBtn');
            const oldBulkHtml = bulkBtn.html();
            bulkBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');

            $.ajax({
                url: window._adminUsersConfig.baseDir + '/api/admin_2fa_reminder.php',
                method: 'POST',
                data: {
                    user_ids: selected,
                    csrf_token: window._csrfToken || (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute('content') || ''
                },
                dataType: 'json',
                success: function(response) {
                    modal.hide();
                    if (response.success) {
                        showToast(response.message || 'Reminders sent successfully.', 'success');
                    } else {
                        showToast(response.error || 'Failed to send reminders.', 'danger');
                    }
                },
                error: function(xhr) {
                    modal.hide();
                    showToast('Error: ' + (xhr.responseText || xhr.statusText), 'danger');
                },
                complete: function() {
                    bulkBtn.prop('disabled', false).html(oldBulkHtml);
                }
            });
        });
    });
});
