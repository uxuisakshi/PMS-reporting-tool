/* Admin Issue Statuses JS - extracted from modules/admin/issue_statuses.php */
function editStatus(id, name, category, color, points, isQa, visibleToClient, visibleToInternal) {
    document.getElementById('edit_status_id').value = id;
    document.getElementById('edit_status_name').value = name;
    document.getElementById('edit_status_category').value = category || '';
    document.getElementById('edit_status_color').value = color;
    document.getElementById('edit_status_points').value = points;
    document.getElementById('edit_is_qa').checked = isQa == 1;
    document.getElementById('edit_visible_to_client').checked = visibleToClient == 1;
    document.getElementById('edit_visible_to_internal').checked = visibleToInternal == 1;
    new bootstrap.Modal(document.getElementById('editStatusModal')).show();
}

function confirmDeleteIssueStatus(id, name, usageCount) {
    if (usageCount > 0) {
        alert('Cannot delete this status: it is currently used by ' + usageCount + ' issue(s).');
        return;
    }
    document.getElementById('delete_issue_status_id').value = id;
    document.getElementById('deleteIssueStatusText').textContent =
        'Are you sure you want to delete issue status "' + name + '"? This action cannot be undone.';
    var confirmBtn = document.getElementById('deleteIssueStatusConfirmBtn');
    confirmBtn.onclick = function () {
        document.getElementById('deleteIssueStatusForm').submit();
    };
    new bootstrap.Modal(document.getElementById('deleteIssueStatusModal')).show();
}
