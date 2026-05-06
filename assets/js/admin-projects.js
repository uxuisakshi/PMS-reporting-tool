/**
 * admin-projects.js - Admin projects page: sub-project mode toggle + table filtering
 */
(function () {
    // Sub-project mode toggle
    var cfg = window.AdminProjectsConfig || {};
    var projectLeads = cfg.projectLeads || [];

    var modeRadios = document.querySelectorAll('input[name="project_mode"]');
    var subContainer = document.getElementById('subprojectsContainer');
    var subList = document.getElementById('subprojectList');
    var addBtn = document.getElementById('addSubprojectBtn');
    var singleFields = document.querySelectorAll('.single-project-fields');
    var singleFieldInputs = document.querySelectorAll('.single-project-fields input, .single-project-fields select, .single-project-fields textarea');

    singleFieldInputs.forEach(function (el) {
        if (el.required) el.dataset.wasRequired = '1';
    });

    function toggleMode() {
        var showSubs = Array.from(modeRadios).some(function (r) { return r.checked && r.value === 'parent'; });
        if (subContainer) subContainer.classList.toggle('d-none', !showSubs);
        singleFields.forEach(function (el) {
            var wrapper = el.closest('.mb-3') || el;
            wrapper.classList.toggle('d-none', showSubs);
        });
        singleFieldInputs.forEach(function (el) {
            el.required = showSubs ? false : (el.dataset.wasRequired === '1');
        });
    }

    function buildLeadOptions() {
        var opts = '<option value="">Select Project Lead</option>';
        projectLeads.forEach(function (lead) {
            opts += '<option value="' + lead.id + '">' + escapeHtml(lead.full_name) + '</option>';
        });
        return opts;
    }

    function addSubRow() {
        var row = document.createElement('div');
        row.className = 'border rounded p-3 position-relative';
        row.innerHTML =
            '<button type="button" class="btn-close position-absolute top-0 end-0 mt-2 me-2" aria-label="Remove"></button>' +
            '<div class="row g-3">' +
            '<div class="col-md-6"><label class="form-label">Sub-Project Title *</label><input type="text" name="child_title[]" class="form-control" required></div>' +
            '<div class="col-md-6"><label class="form-label">Project Type *</label><select name="child_type[]" class="form-select" required>' +
            '<option value="web">Web Project</option><option value="app">App Project</option><option value="pdf">PDF Remediation</option>' +
            '</select></div>' +
            '<div class="col-md-4"><label class="form-label">Priority</label><select name="child_priority[]" class="form-select">' +
            '<option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option>' +
            '</select></div>' +
            '<div class="col-md-4"><label class="form-label">Project Lead</label><select name="child_lead_id[]" class="form-select">' + buildLeadOptions() + '</select></div>' +
            '<div class="col-md-4"><label class="form-label">Total Hours (optional)</label><input type="number" name="child_total_hours[]" class="form-control" step="0.01" min="0"></div>' +
            '</div>';
        row.querySelector('.btn-close').addEventListener('click', function () { row.remove(); });
        if (subList) subList.appendChild(row);
    }

    if (modeRadios.length) {
        modeRadios.forEach(function (radio) { radio.addEventListener('change', toggleMode); });
        toggleMode();
    }
    if (addBtn) addBtn.addEventListener('click', addSubRow);

    var projectsTable = null;

    $(document).ready(function () {
        if ($.fn.DataTable) {
            // Register a custom search function that uses data-* attributes on the TR
            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex, rowData, counter) {
                if (settings.nTable.id !== 'projectsTable') return true;
                var rowNode = settings.aoData[dataIndex].nTr;
                var $tr = $(rowNode);
                var status    = $tr.data('status')    || '';
                var type      = $tr.data('type')      || '';
                var priority  = $tr.data('priority')  || '';
                var clientId  = $tr.data('client-id') || '';
                var createdAt = $tr.data('created-at')|| '';

                var fStatus   = $('#statusFilter').val();
                var fType     = $('#typeFilter').val();
                var fPriority = $('#priorityFilter').val();
                var fClient   = $('#clientFilter').val();
                var fStart    = $('#startDate').val();
                var fEnd      = $('#endDate').val();

                if (fStatus   && status   !== fStatus)   return false;
                if (fType     && type     !== fType)     return false;
                if (fPriority && priority !== fPriority) return false;
                if (fClient   && String(clientId) !== String(fClient)) return false;
                
                if (fStart && createdAt < fStart) return false;
                if (fEnd   && createdAt > fEnd)   return false;
                
                return true;
            });

            projectsTable = $('#projectsTable').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                order: [[7, 'desc']], // Default sort by Created At descending
                columnDefs: [
                    { targets: [0, 8], orderable: false, searchable: false }
                ],
                language: {
                    search: 'Global Filter:',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ entries'
                }
            });

            // Status / Type / Priority / Client / Date filters trigger DataTables redraw
            $('#statusFilter, #typeFilter, #priorityFilter, #clientFilter, #startDate, #endDate').on('change', function () {
                projectsTable.draw();
            });

            // Search box wired to DataTables global search
            $('#searchProject').on('keyup', function () {
                projectsTable.search(this.value).draw();
            });
        }
    });

    function escapeSearch(val) {
        return val.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, "\\$&"); // Simple regex escape
    }

    window.toggleSubprojects = function (projectId, btn) {
        var tr = $(btn).closest('tr');
        var row = projectsTable.row(tr);
        var subDataStr = tr.attr('data-subprojects');
        
        if (!subDataStr) return;
        var subs = JSON.parse(subDataStr);

        if (row.child.isShown()) {
            row.child.hide();
            $(btn).removeClass('expanded');
        } else {
            var html = renderSubprojectsHtml(subs);
            row.child(html, 'sub-projects-row').show();
            $(btn).addClass('expanded');
        }
    };

    function renderSubprojectsHtml(subs) {
        var baseDir = window.location.pathname.split('/modules/')[0];
        var html = '<div class="sub-projects-wrapper"><table class="table table-sm table-borderless mb-0"><thead>' +
            '<tr><th>Code</th><th>Title</th><th>Type</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        
        subs.forEach(function (sub) {
            var priorityClass = sub.priority === 'critical' ? 'danger' : (sub.priority === 'high' ? 'warning' : 'secondary');
            var statusClass = sub.status === 'completed' ? 'success' : (sub.status === 'in_progress' ? 'primary' : 'secondary');
            
            html += '<tr>' +
                '<td>' + escapeHtml(sub.project_code || sub.po_number) + '</td>' +
                '<td>' + escapeHtml(sub.title) + '</td>' +
                '<td><span class="badge bg-info">' + escapeHtml(sub.project_type || 'N/A') + '</span></td>' +
                '<td><span class="badge bg-' + priorityClass + '">' + escapeHtml(sub.priority) + '</span></td>' +
                '<td><span class="badge bg-' + statusClass + '">' + escapeHtml(sub.status.replace('_', ' ')) + '</span></td>' +
                '<td><a href="' + baseDir + '/modules/projects/view.php?id=' + sub.id + '" class="btn btn-xs btn-info"><i class="fas fa-eye"></i></a></td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({ '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;' })[s];
        });
    }
})();
