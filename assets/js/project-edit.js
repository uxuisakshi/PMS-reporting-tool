/**
 * project-edit.js - Project edit page form validation
 */
$(document).ready(function () {
    $('#editProjectForm').on('submit', function (e) {
        var valid = true;
        $('.required').each(function () {
            var field = $(this).find('input, select, textarea').first();
            if (field.length && !field.val().trim()) {
                field.addClass('is-invalid');
                valid = false;
            } else {
                field.removeClass('is-invalid');
            }
        });
        if (!valid) {
            e.preventDefault();
            showToast('Please fill in all required fields.', 'warning');
        }
    });

    $('#deleteModal').on('show.bs.modal', function () {
        $('#confirmDelete').prop('checked', false);
    });
});
