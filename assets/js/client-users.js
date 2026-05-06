/**
 * client-users.js
 * Extracted from modules/admin/client_users.php inline script
 */
$(document).ready(function () {
    $('#clientUsersTable').DataTable({
        order: [[6, 'desc']],
        pageLength: 25
    });
});

function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username || '';
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_client_id').value = user.client_id || '';
    document.getElementById('edit_is_active').checked = user.is_active == 1;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function resetPassword(userId, userName) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_user_name').textContent = userName;
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function deleteClientUser(userId, userName) {
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('delete_user_name').textContent = userName;
    new bootstrap.Modal(document.getElementById('deleteClientUserModal')).show();
}
