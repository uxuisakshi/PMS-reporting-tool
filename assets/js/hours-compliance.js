/**
 * hours-compliance.js - Hours compliance report page
 */
(function () {
    var currentReport = null;
    var settings = null;
    var apiUrl = (window.HoursComplianceConfig || {}).apiUrl || '../../api/hours_reminder.php';

    $(document).ready(function () {
        loadSettings();
        loadReport();
    });

    var complianceTable = null;

    function initComplianceTable() {
        if (!$.fn.DataTable) return;
        
        if ($.fn.DataTable.isDataTable('#complianceTable')) {
            return complianceTable;
        }

        complianceTable = $('#complianceTable').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            paging: true,
            searching: true,
            info: true,
            autoWidth: false,
            order: [[5, 'asc'], [1, 'asc']], // Sort by gap/status then name
            language: {
                search: 'Filter:',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries'
            },
            columnDefs: [
                { targets: [0, 6, 7], orderable: false, searchable: false }
            ]
        });

        // Add custom filtering for status
        $('input[name="statusFilter"]').on('change', function() {
            var val = $(this).val();
            if (val === 'all') {
                complianceTable.column(5).search('').draw();
            } else if (val === 'compliant') {
                complianceTable.column(5).search('Compliant').draw();
            } else if (val === 'non-compliant') {
                // Search for anything EXCEPT 'Compliant' or specifically the badge 'hrs'
                complianceTable.column(5).search('hrs').draw();
            }
        });

        return complianceTable;
    }

    function loadSettings() {
        $.get(apiUrl + '?action=get_settings', function (response) {
            if (response.success) { settings = response.settings; $('#minHoursDisplay').text(settings.minimum_hours); }
        });
    }

    function loadReport() {
        var date = $('#reportDate').val();
        $.get(apiUrl, { action: 'get_compliance_report', date: date }, function (response) {
            if (response.success) { currentReport = response; renderReport(); }
            else showToast('Error: ' + response.message, 'danger');
        });
    }

    function renderReport() {
        $('#totalUsers').text(currentReport.summary.total_users);
        $('#compliantUsers').text(currentReport.summary.compliant_count);
        $('#nonCompliantUsers').text(currentReport.summary.non_compliant_count);
        $('#complianceRate').text(currentReport.summary.compliance_rate + '%');
        $('#minHoursDisplay').text(currentReport.minimum_hours);

        var table = initComplianceTable();
        table.clear();

        // Combine all users for the unified table
        var allUsers = currentReport.non_compliant.concat(currentReport.compliant);
        
        allUsers.forEach(function (user) {
            var isCompliant = user.total_hours >= currentReport.minimum_hours;
            var hoursShort = (currentReport.minimum_hours - user.total_hours).toFixed(2);
            
            var statusHtml = isCompliant ? 
                '<span class="badge bg-success"><i class="fas fa-check"></i> Compliant</span>' :
                '<span class="badge bg-danger">-' + hoursShort + ' hrs</span>';
            
            var reminderStatus = isCompliant ? '-' : (user.reminder_sent ?
                '<span class="badge bg-success">Sent ' + new Date(user.reminder_sent_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) + '</span>' :
                '<span class="badge bg-secondary">Not Sent</span>');

            table.row.add([
                '<i class="fas fa-chevron-right expand-btn" onclick="toggleUserDetails(' + user.id + ', this)"></i>',
                '<strong>' + escapeHtml(user.full_name || user.username) + '</strong>',
                '<span class="badge bg-secondary">' + escapeHtml(user.role) + '</span>',
                escapeHtml(user.email),
                '<span class="badge ' + (isCompliant ? 'bg-success' : 'bg-warning') + '">' + user.total_hours + ' hrs</span>',
                statusHtml,
                reminderStatus,
                '<a href="mailto:' + escapeHtml(user.email) + '" class="btn btn-sm btn-primary" title="Send Email"><i class="fas fa-envelope"></i></a>'
            ]).node().id = 'row-' + user.id;
        });

        table.draw();
        
        // Trigger filter if one is selected
        $('input[name="statusFilter"]:checked').trigger('change');
    }

    window.showSettingsModal = function () {
        $.get(apiUrl + '?action=get_settings', function (response) {
            if (response.success) {
                var s = response.settings;
                $('#reminderTime').val(s.reminder_time);
                $('#minimumHours').val(s.minimum_hours);
                $('#loginCutoffTime').val(s.login_cutoff_time || '10:30');
                $('#statusCutoffTime').val(s.status_cutoff_time || '11:00');
                $('#notificationMessage').val(s.notification_message);
                $('#excludeWeekends').prop('checked', Number(s.exclude_weekends) === 1);
                $('#excludeLeaveDays').prop('checked', Number(s.exclude_leave_days) === 1);
                $('#enabled').prop('checked', s.enabled == 1);
                $('#settingsModal').modal('show');
            }
        });
    };

    window.saveSettings = function () {
        var formData = new FormData($('#settingsForm')[0]);
        formData.append('action', 'update_settings');
        $.ajax({
            url: apiUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    showToast('Settings updated successfully', 'success');
                    $('#settingsModal').modal('hide');
                    loadSettings();
                    loadReport();
                } else {
                    showToast('Error: ' + response.message, 'danger');
                }
            },
            error: function () {
                showToast('Error: Failed to save settings', 'danger');
            }
        });
    };

    window.loadReport = loadReport;

    window.toggleUserDetails = function (userId, btn) {
        var table = $('#complianceTable').DataTable();
        var tr = $(btn).closest('tr');
        var row = table.row(tr);
        var expandBtn = $(btn);

        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('shown');
            expandBtn.removeClass('expanded');
        } else {
            expandBtn.addClass('expanded');
            var date = $('#reportDate').val();
            
            // Show loading
            row.child('<div class="details-content"><div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading time logs...</div></div>', 'details-row').show();
            tr.addClass('shown');

            $.get(apiUrl, { action: 'get_user_time_logs', user_id: userId, date: date }, function (response) {
                if (response.success) {
                    var html = renderTimeLogDetailsHtml(response.logs, response.total_hours);
                    row.child(html, 'details-row').show();
                } else {
                    row.child('<div class="details-content"><div class="alert alert-danger mb-0">Error: ' + escapeHtml(response.message) + '</div></div>', 'details-row').show();
                }
            });
        }
    };

    function renderTimeLogDetailsHtml(logs, totalHours) {
        if (logs.length === 0) {
            return '<div class="details-content"><div class="alert alert-info mb-0">No time logs found</div></div>';
        }
        var html = '<div class="details-content"><div class="mb-2"><strong>Total Hours: ' + totalHours + '</strong></div>';
        logs.forEach(function (log) {
            var taskInfo = '';
            if (log.task_type === 'page_testing' || log.task_type === 'page_qa' || log.task_type === 'regression') {
                taskInfo = '<div class="time-log-details"><span class="badge bg-info">' + escapeHtml(log.task_type.replace('_', ' ').toUpperCase()) + '</span>' +
                    (log.page_name ? ' <strong>Page:</strong> ' + escapeHtml((log.page_number ? log.page_number + ' - ' : '') + log.page_name) : '') + '</div>';
            } else if (log.task_type === 'project_phase') {
                taskInfo = '<div class="time-log-details"><span class="badge bg-info">PROJECT PHASE</span>' + (log.phase_name ? ' <strong>Phase:</strong> ' + escapeHtml(log.phase_name) : '') + '</div>';
            }
            html += '<div class="time-log-entry">' +
                '<div class="time-log-header"><span class="time-log-project">' + escapeHtml(log.project_name || 'No Project') + '</span><span class="time-log-hours">' + log.hours_spent + ' hrs</span></div>' +
                taskInfo +
                (log.description ? '<div class="time-log-details mt-2"><strong>Description:</strong> ' + escapeHtml(log.description) + '</div>' : '') +
                '</div>';
        });
        html += '</div>';
        return html;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({ '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' })[s];
        });
    }

    setInterval(loadReport, 300000);
})();
