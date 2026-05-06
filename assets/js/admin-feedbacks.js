/**
 * admin-feedbacks.js
 * Extracted from modules/admin/feedbacks.php inline script
 * Requires window._feedbacksConfig.baseDir
 */
(function () {
    var baseDir = (window._feedbacksConfig && window._feedbacksConfig.baseDir) ? window._feedbacksConfig.baseDir : '';

    // Update feedback status
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('feedback-status-update')) {
            var feedbackId = e.target.dataset.feedbackId;
            var newStatus = e.target.value;
            var originalValue = e.target.dataset.originalValue || 'open';

            fetch(baseDir + '/api/feedback.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_status&feedback_id=' + encodeURIComponent(feedbackId) + '&status=' + encodeURIComponent(newStatus)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var row = e.target.closest('tr');
                    row.classList.add('table-success');
                    setTimeout(function () { row.classList.remove('table-success'); }, 2000);
                    e.target.dataset.originalValue = newStatus;
                } else {
                    showToast('Failed to update status: ' + (data.message || 'Unknown error'), 'danger');
                    e.target.value = originalValue;
                }
            })
            .catch(function (error) {
                console.error('Error:', error);
                showToast('Error updating status', 'danger');
                e.target.value = originalValue;
            });
        }
    });

    // View feedback details
    window.viewFeedback = function (feedbackId) {
        fetch(baseDir + '/api/feedback.php?action=get_feedback&feedback_id=' + encodeURIComponent(feedbackId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var feedback = data.feedback;
                    var html = '<div class="row">'
                        + '<div class="col-md-6"><h6>Sender Information</h6>'
                        + '<p><strong>Name:</strong> ' + (feedback.sender_name || 'Unknown') + '</p>'
                        + '<p><strong>Username:</strong> @' + (feedback.sender_username || 'unknown') + '</p></div>'
                        + '<div class="col-md-6"><h6>Feedback Information</h6>'
                        + '<p><strong>Date:</strong> ' + new Date(feedback.created_at).toLocaleString() + '</p>'
                        + '<p><strong>Status:</strong> <span class="badge bg-primary">' + feedback.status + '</span></p></div></div>';

                    if (feedback.project_title) {
                        html += '<div class="mt-3"><h6>Project</h6>'
                            + '<p><strong>' + feedback.project_title + '</strong> (' + feedback.project_code + ')</p></div>';
                    }
                    if (feedback.recipients) {
                        html += '<div class="mt-3"><h6>Recipients</h6><p>' + feedback.recipients + '</p></div>';
                    }
                    html += '<div class="mt-3"><h6>Content</h6>'
                        + '<div class="border p-3 rounded bg-light">' + feedback.content + '</div></div>';

                    document.getElementById('feedbackDetails').innerHTML = html;
                    var modal = new bootstrap.Modal(document.getElementById('viewFeedbackModal'));
                    modal.show();
                } else {
                    showToast('Failed to load feedback details', 'danger');
                }
            })
            .catch(function (error) {
                console.error('Error:', error);
                showToast('Error loading feedback details', 'danger');
            });
    };

    // Delete feedback
    window.deleteFeedback = function (feedbackId) {
        var doDelete = function() {
            fetch(baseDir + '/api/feedback.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_feedback&feedback_id=' + encodeURIComponent(feedbackId) + '&csrf_token=' + encodeURIComponent(window._csrfToken || '')
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var row = document.querySelector('[data-feedback-id="' + feedbackId + '"]').closest('tr');
                    if (row) row.remove();
                    showToast('Feedback deleted successfully', 'success');
                } else {
                    showToast('Failed to delete feedback: ' + (data.message || 'Unknown error'), 'danger');
                }
            })
            .catch(function () {
                showToast('Error deleting feedback', 'danger');
            });
        };
        if (typeof confirmModal === 'function') {
            confirmModal('Are you sure you want to delete this feedback? This action cannot be undone.', doDelete);
        } else if (confirm('Are you sure you want to delete this feedback?')) {
            doDelete();
        }
    };

    // Export feedbacks
    window.exportFeedbacks = function () {
        var form = document.getElementById('exportForm');
        var formData = new FormData(form);
        var urlParams = new URLSearchParams(window.location.search);
        urlParams.forEach(function (value, key) { formData.append(key, value); });
        formData.append('action', 'export');
        formData.append('csrf_token', window._csrfToken || '');

        var tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = baseDir + '/api/feedback.php';
        tempForm.style.display = 'none';
        formData.forEach(function (value, key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            tempForm.appendChild(input);
        });
        document.body.appendChild(tempForm);
        tempForm.submit();
        document.body.removeChild(tempForm);

        var modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
        if (modal) modal.hide();
    };

    // Store original values for status selects
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.feedback-status-update').forEach(function (select) {
            select.dataset.originalValue = select.value;
        });
    });
})();
