/**
 * project-modals.js
 * Extracted from modules/projects/partials/modals.php inline script (first block)
 * Handles custom phase name toggle and duration calculation
 */
document.addEventListener('DOMContentLoaded', function () {
    var phaseSelect    = document.getElementById('phaseNameSelect');
    var phaseHidden    = document.getElementById('phaseNameHidden');
    var customDiv      = document.getElementById('customPhaseNameDiv');
    var customInput    = document.getElementById('customPhaseName');
    var startDateInput = document.getElementById('phaseStartDate');
    var endDateInput   = document.getElementById('phaseEndDate');
    var durationHint   = document.getElementById('durationHint');
    var addPhaseForm   = document.getElementById('addPhaseForm');

    if (!phaseSelect) return;

    phaseSelect.addEventListener('change', function () {
        if (this.value === 'custom') {
            customDiv.style.display = 'block';
            customInput.required = true;
            durationHint.textContent = '';
        } else {
            customDiv.style.display = 'none';
            customInput.required = false;
            customInput.value = '';

            var selectedOption = this.options[this.selectedIndex];
            var duration = selectedOption.getAttribute('data-duration');

            if (duration && startDateInput.value) {
                var startDate = new Date(startDateInput.value);
                var endDate   = new Date(startDate);
                endDate.setDate(endDate.getDate() + parseInt(duration));
                endDateInput.value = endDate.toISOString().split('T')[0];
                durationHint.textContent = 'Typical: ' + duration + ' days';
            } else if (duration) {
                durationHint.textContent = 'Typical: ' + duration + ' days';
            } else {
                durationHint.textContent = '';
            }
        }
    });

    startDateInput.addEventListener('change', function () {
        var selectedOption = phaseSelect.options[phaseSelect.selectedIndex];
        var duration = selectedOption.getAttribute('data-duration');

        if (duration && this.value && phaseSelect.value !== 'custom') {
            var startDate = new Date(this.value);
            var endDate   = new Date(startDate);
            endDate.setDate(endDate.getDate() + parseInt(duration));
            endDateInput.value = endDate.toISOString().split('T')[0];
        }

        if (this.value) {
            endDateInput.setAttribute('min', this.value);
        } else {
            endDateInput.removeAttribute('min');
        }
    });

    endDateInput.addEventListener('change', function () {
        var startDate = startDateInput.value;
        var endDate   = this.value;
        if (startDate && endDate) {
            if (new Date(endDate) < new Date(startDate)) {
                alert('End date cannot be before start date');
                this.value = '';
            }
        }
    });

    if (addPhaseForm) {
        addPhaseForm.addEventListener('submit', function (e) {
            if (phaseSelect.value === 'custom') {
                var customName = customInput.value.trim();
                if (!customName) {
                    e.preventDefault();
                    alert('Please enter a custom phase name');
                    customInput.focus();
                    return false;
                }
                phaseHidden.value = customName;
            } else {
                phaseHidden.value = phaseSelect.value;
            }
        });
    }
});
