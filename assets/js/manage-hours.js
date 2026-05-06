/**
 * manage-hours.js - Admin manage hours page
 */
window.editHours = function (assignmentId, currentHours, projectTitle) {
    document.getElementById('edit_assignment_id').value = assignmentId;
    document.getElementById('edit_project_title').value = projectTitle;
    document.getElementById('edit_current_hours').value = currentHours + ' hours';
    document.getElementById('edit_new_hours').value = currentHours;
    new bootstrap.Modal(document.getElementById('editHoursModal')).show();
};

window.removeAssignment = function (assignmentId, projectTitle) {
    document.getElementById('remove_assignment_id').value = assignmentId;
    document.getElementById('remove_project_title').textContent = projectTitle;
    new bootstrap.Modal(document.getElementById('removeAssignmentModal')).show();
};

window.updateAvailableHours = function (selectElement) {
    var selectedOption = selectElement.options[selectElement.selectedIndex];
    var hoursInput = document.getElementById('hours-input');
    var hoursValidation = document.getElementById('hours-validation');
    var projectInfo = document.getElementById('project-hours-info');

    if (selectedOption.value) {
        window.hoursValidator.getProjectSummary(selectedOption.value)
            .then(function (summary) {
                var totalHours = summary.total_hours || 0;
                var allocatedHours = summary.allocated_hours || 0;
                var availableHours = summary.available_hours || 0;
                document.getElementById('total-hours').textContent = totalHours.toFixed(1);
                document.getElementById('allocated-hours').textContent = allocatedHours.toFixed(1);
                document.getElementById('available-hours').textContent = availableHours.toFixed(1);
                projectInfo.style.display = 'block';
                hoursInput.max = availableHours;
                hoursInput.disabled = availableHours <= 0;
                if (availableHours <= 0) {
                    hoursValidation.textContent = 'No hours available in this project';
                    hoursValidation.className = 'text-danger';
                } else {
                    hoursValidation.textContent = 'Maximum ' + availableHours.toFixed(2) + ' hours available';
                    hoursValidation.className = 'text-success';
                }
            })
            .catch(function () {
                hoursValidation.textContent = 'Error loading project information';
                hoursValidation.className = 'text-danger';
            });
    } else {
        projectInfo.style.display = 'none';
        hoursInput.max = 0;
        hoursInput.disabled = true;
        hoursValidation.textContent = 'Select a project first';
        hoursValidation.className = 'text-muted';
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.hoursValidator.setupHoursInput('hours-input', 'project_id', 'hours-validation');
    var hoursInput = document.getElementById('hours-input');
    if (hoursInput) {
        hoursInput.addEventListener('input', function () {
            var maxHours = parseFloat(this.max);
            var currentValue = parseFloat(this.value);
            var validation = document.getElementById('hours-validation');
            if (currentValue > maxHours && maxHours > 0) {
                this.setCustomValidity('Cannot exceed ' + maxHours + ' hours');
                validation.textContent = 'Cannot exceed ' + maxHours.toFixed(2) + ' hours';
                validation.className = 'text-danger';
            } else if (maxHours > 0) {
                this.setCustomValidity('');
                validation.textContent = (maxHours - currentValue).toFixed(2) + ' hours will remain available';
                validation.className = 'text-info';
            }
        });
    }
});
