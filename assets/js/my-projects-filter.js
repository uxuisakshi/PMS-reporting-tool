/**
 * Shared project table filtering for my_projects pages
 * Used by: qa, project_lead, ft_tester, at_tester
 */
$(document).ready(function () {
    function filterProjects() {
        var statusFilter = $('#statusFilter').val().toLowerCase();
        var typeFilter   = $('#typeFilter').val().toLowerCase();
        var priorityFilter = $('#priorityFilter').val() ? $('#priorityFilter').val().toLowerCase() : '';
        var searchText   = $('#searchProject').val().toLowerCase();

        $('#projectsTable tbody tr').each(function () {
            var row     = $(this);
            var status  = row.data('status');
            var type    = row.data('type');
            var priority = row.data('priority') ? String(row.data('priority')).toLowerCase() : '';
            var title   = row.data('title');

            var show = true;
            if (statusFilter   && status   !== statusFilter)   show = false;
            if (typeFilter     && type     !== typeFilter)     show = false;
            if (priorityFilter && priority !== priorityFilter) show = false;
            if (searchText     && String(title).indexOf(searchText) === -1) show = false;

            row.toggle(show);
        });
    }

    $('#statusFilter, #typeFilter, #priorityFilter').on('change', filterProjects);
    $('#searchProject').on('keyup', filterProjects);
});
