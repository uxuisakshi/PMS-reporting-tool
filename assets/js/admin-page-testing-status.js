/* Admin Page Testing Status JS - extracted from modules/admin/page_testing_status_master.php */
function editStatus(status) {
    document.getElementById('edit_status_id').value = status.id;
    document.getElementById('edit_status_key').value = status.status_key;
    document.getElementById('edit_status_label').value = status.status_label;
    document.getElementById('edit_status_description').value = status.status_description || '';
    document.getElementById('edit_badge_color').value = status.badge_color;
    document.getElementById('edit_display_order').value = status.display_order;
    document.getElementById('edit_is_active').checked = status.is_active == 1;
    new bootstrap.Modal(document.getElementById('editStatusModal')).show();
}
