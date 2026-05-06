/* Admin Availability Status JS - extracted from modules/admin/availability_status_master.php */
function editStatus(status) {
    document.getElementById('edit_id').value = status.id || '';
    document.getElementById('edit_status_key').value = status.status_key || '';
    document.getElementById('edit_status_label').value = status.status_label || '';
    document.getElementById('edit_badge_color').value = status.badge_color || 'secondary';
    document.getElementById('edit_display_order').value = status.display_order || 0;
    document.getElementById('edit_description').value = status.description || '';

    var activeInput = document.getElementById('edit_is_active');
    activeInput.checked = String(status.is_active) === '1';
    activeInput.disabled = (status.status_key === 'not_updated');

    bootstrap.Modal.getOrCreateInstance(document.getElementById('editStatusModal')).show();
}

function deleteStatus(id, label) {
    if (!id) return;
    confirmModal('Delete availability status "' + label + '"?', function () {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteStatusForm').submit();
    }, {
        title: 'Delete Availability Status',
        confirmText: 'Delete',
        confirmClass: 'btn-danger'
    });
}
