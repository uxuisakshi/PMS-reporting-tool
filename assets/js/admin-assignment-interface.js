/* Admin Assignment Interface JS - extracted from includes/templates/admin/assignment_interface.php */

// Assignment form handling
document.getElementById('assignmentForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const selectedProjects = Array.from(document.getElementById('projects').selectedOptions)
        .map(option => option.value);

    formData.set('project_ids', selectedProjects.join(','));

    fetch('/admin/assignments/assign', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess(data.message);
            this.reset();
            refreshAssignments();
        } else {
            showError(data.error);
        }
    })
    .catch(error => {
        showError('An error occurred while assigning projects');
    });
});

// Revocation form handling
document.getElementById('revocationForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const selectedProjects = Array.from(document.getElementById('revokeProjects').selectedOptions)
        .map(option => option.value);

    formData.set('project_ids', selectedProjects.join(','));

    fetch('/admin/assignments/revoke', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess(data.message);
            this.reset();
            refreshAssignments();
        } else {
            showError(data.error);
        }
    })
    .catch(error => {
        showError('An error occurred while revoking access');
    });
});

// Update revoke projects when client user changes
document.getElementById('revokeClientUser').addEventListener('change', function() {
    const clientUserId = this.value;
    const revokeProjectsSelect = document.getElementById('revokeProjects');

    revokeProjectsSelect.innerHTML = '<option value="">Loading...</option>';

    if (clientUserId) {
        fetch(`/admin/assignments/user-projects?client_user_id=${clientUserId}`)
            .then(response => response.json())
            .then(data => {
                revokeProjectsSelect.innerHTML = '';
                if (data.projects && data.projects.length > 0) {
                    data.projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        revokeProjectsSelect.appendChild(option);
                    });
                } else {
                    revokeProjectsSelect.innerHTML = '<option value="">No projects assigned</option>';
                }
            })
            .catch(error => {
                revokeProjectsSelect.innerHTML = '<option value="">Error loading projects</option>';
            });
    } else {
        revokeProjectsSelect.innerHTML = '<option value="">Select a client user first</option>';
    }
});

// Quick revoke function
function quickRevoke(clientUserId, projectId) {
    if (!confirm('Are you sure you want to revoke access to this project?')) {
        return;
    }

    var cfg = window._assignmentInterfaceConfig || {};
    const formData = new FormData();
    formData.append('csrf_token', cfg.csrfToken || '');
    formData.append('client_user_id', clientUserId);
    formData.append('project_ids', projectId);
    formData.append('notify_client', 'no');

    fetch('/admin/assignments/revoke', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccess('Project access revoked successfully');
            refreshAssignments();
        } else {
            showError(data.error);
        }
    })
    .catch(error => {
        showError('An error occurred while revoking access');
    });
}

// Refresh assignments table
function refreshAssignments() {
    location.reload();
}

// Load assignment history
function loadAssignmentHistory() {
    fetch('/admin/assignments/history')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('historyContainer');
            if (data.history && data.history.length > 0) {
                let html = '<div class="table-responsive"><table class="table table-sm">';
                html += '<thead><tr><th>Date</th><th>Admin</th><th>Action</th><th>Client</th><th>Details</th></tr></thead><tbody>';

                data.history.forEach(record => {
                    html += `<tr>
                        <td>${new Date(record.created_at).toLocaleString()}</td>
                        <td>${record.admin_username || 'Unknown'}</td>
                        <td><span class="badge bg-info">${record.action_type}</span></td>
                        <td>${record.client_username || 'N/A'}</td>
                        <td>${record.details}</td>
                    </tr>`;
                });

                html += '</tbody></table></div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p class="text-muted">No assignment history found.</p>';
            }
        })
        .catch(error => {
            document.getElementById('historyContainer').innerHTML = '<p class="text-danger">Error loading assignment history.</p>';
        });
}

// Toast functions
function showSuccess(message) {
    document.getElementById('successMessage').textContent = message;
    const toast = new bootstrap.Toast(document.getElementById('successToast'));
    toast.show();
}

function showError(message) {
    document.getElementById('errorMessage').textContent = message;
    const toast = new bootstrap.Toast(document.getElementById('errorToast'));
    toast.show();
}

// Load assignment history on page load
document.addEventListener('DOMContentLoaded', function() {
    loadAssignmentHistory();
});
