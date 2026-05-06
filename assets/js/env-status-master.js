/**
 * env-status-master.js
 * Extracted from modules/admin/env_status_master.php inline script
 */
function editStatus(status) {
    document.getElementById('edit_id').value = status.id;
    document.getElementById('edit_status_key').value = status.status_key;
    document.getElementById('edit_status_label').value = status.status_label;
    document.getElementById('edit_badge_color').value = status.badge_color;
    document.getElementById('edit_description').value = status.description || '';
    document.getElementById('edit_display_order').value = status.display_order;
    document.getElementById('edit_is_active').checked = status.is_active == 1;

    var modal = new bootstrap.Modal(document.getElementById('editStatusModal'));
    modal.show();
}

function deleteStatus(id, label, usageCount) {
    if (usageCount > 0) {
        alert('Cannot delete this status: It is currently in use by ' + usageCount + ' environment(s).');
        return;
    }

    document.getElementById('delete_id').value = id;
    document.getElementById('deleteEnvStatusConfirmText').textContent =
        'Are you sure you want to delete the status "' + label + '"? This action cannot be undone.';

    var confirmBtn = document.getElementById('deleteEnvStatusConfirmBtn');
    confirmBtn.onclick = function () {
        document.getElementById('deleteForm').submit();
    };

    new bootstrap.Modal(document.getElementById('deleteEnvStatusConfirmModal')).show();
}
