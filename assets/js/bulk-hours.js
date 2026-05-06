/**
 * bulk-hours.js - Admin bulk hours management page
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.BulkHoursConfig || {};
        if (cfg.flashSuccess) showBulkToast(cfg.flashSuccess, 'success', 5500);
        if (cfg.flashError) showBulkToast(cfg.flashError, 'danger', 5500);

        document.querySelectorAll('.hours-input').forEach(function (input) {
            validateHours(input);
        });
    });

    function showBulkToast(message, variant, ttl) {
        if (!message) return;
        ttl = ttl || 5500;
        if (typeof window.showToast === 'function') { window.showToast(message, variant, ttl); return; }
        var wrap = document.getElementById('bulkHoursInlineToastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'bulkHoursInlineToastWrap';
            wrap.style.cssText = 'position:fixed;top:76px;right:16px;z-index:1080;max-width:420px;';
            document.body.appendChild(wrap);
        }
        var toast = document.createElement('div');
        var cls = variant === 'success' ? 'alert-success' : (variant === 'danger' ? 'alert-danger' : (variant === 'warning' ? 'alert-warning' : 'alert-secondary'));
        toast.className = 'alert ' + cls + ' shadow-sm mb-2';
        toast.textContent = String(message);
        wrap.appendChild(toast);
        setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, ttl);
    }

    window.validateHours = function (input) {
        var minAllowed = parseFloat(input.dataset.min || '0') || 0;
        var maxAllowed = parseFloat(input.dataset.maxAllowed || input.max || '0') || 0;
        var isOverAllocated = String(input.dataset.overAllocated || '0') === '1';
        var infoElement = input.nextElementSibling;
        var projectTotal = parseFloat(input.dataset.projectTotal || '0') || 0;
        var projectAllocated = parseFloat(input.dataset.projectAllocated || '0') || 0;
        var originalHours = parseFloat(input.dataset.original || '0') || 0;

        if (input.value === '') {
            input.style.borderColor = '';
            input.style.backgroundColor = '';
            if (infoElement) { infoElement.textContent = 'Min: ' + minAllowed.toFixed(1) + 'h, Max: ' + maxAllowed.toFixed(1) + 'h'; infoElement.className = 'text-muted hours-info'; }
            input.setCustomValidity('');
            return;
        }

        var newHours = parseFloat(input.value) || 0;

        // Calculate total allocation considering all pending changes for this project
        var currentRow = input.closest('tr');
        var projectName = currentRow.querySelector('td:nth-child(2) strong').textContent;
        var allRows = document.querySelectorAll('#bulkUpdateForm tbody tr');
        var totalPendingForProject = 0;
        
        allRows.forEach(function(row) {
            var rowProjectName = row.querySelector('td:nth-child(2) strong').textContent;
            if (rowProjectName === projectName) {
                var rowInput = row.querySelector('.hours-input');
                var rowOriginal = parseFloat(rowInput.dataset.original || '0') || 0;
                var rowNew = rowInput.value !== '' ? (parseFloat(rowInput.value) || 0) : rowOriginal;
                totalPendingForProject += rowNew;
            }
        });

        var willExceedBudget = totalPendingForProject > projectTotal;

        if (newHours < minAllowed) {
            input.style.borderColor = '#dc3545'; input.style.backgroundColor = '#f8d7da';
            if (infoElement) { 
                infoElement.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Cannot be lower than utilized hours (' + minAllowed.toFixed(1) + 'h)'; 
                infoElement.className = 'text-danger hours-info'; 
            }
            input.setCustomValidity('Cannot be lower than ' + minAllowed.toFixed(1) + ' hours');
        } else if (willExceedBudget) {
            input.style.borderColor = '#dc3545'; input.style.backgroundColor = '#f8d7da';
            if (infoElement) { 
                var overBy = totalPendingForProject - projectTotal;
                infoElement.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Project will be over-allocated by ' + overBy.toFixed(1) + 'h (Total: ' + totalPendingForProject.toFixed(1) + 'h / Budget: ' + projectTotal.toFixed(1) + 'h)';
                infoElement.className = 'text-danger hours-info'; 
            }
            input.setCustomValidity('Total allocation will exceed project budget');
        } else if (newHours > maxAllowed) {
            input.style.borderColor = '#dc3545'; input.style.backgroundColor = '#f8d7da';
            if (infoElement) { 
                var msg = isOverAllocated 
                    ? '<i class="fas fa-exclamation-triangle"></i> Project is over-allocated. Cannot exceed ' + maxAllowed.toFixed(1) + 'h' 
                    : '<i class="fas fa-exclamation-triangle"></i> Exceeds project budget. Max allowed: ' + maxAllowed.toFixed(1) + 'h';
                infoElement.innerHTML = msg;
                infoElement.className = 'text-danger hours-info'; 
            }
            input.setCustomValidity('Cannot exceed ' + maxAllowed.toFixed(1) + ' hours');
        } else if (newHours > 0 && newHours !== originalHours) {
            input.style.borderColor = '#198754'; input.style.backgroundColor = '#d1e7dd';
            if (infoElement) { 
                var remaining = projectTotal - totalPendingForProject;
                infoElement.innerHTML = '<i class="fas fa-check-circle"></i> Valid. Project total: ' + totalPendingForProject.toFixed(1) + 'h / ' + projectTotal.toFixed(1) + 'h (' + remaining.toFixed(1) + 'h remaining)'; 
                infoElement.className = 'text-success hours-info'; 
            }
            input.setCustomValidity('');
        } else {
            input.style.borderColor = ''; input.style.backgroundColor = '';
            if (infoElement) infoElement.textContent = '';
            input.setCustomValidity('');
        }
        
        // Re-validate all other inputs for the same project
        allRows.forEach(function(row) {
            var rowProjectName = row.querySelector('td:nth-child(2) strong').textContent;
            if (rowProjectName === projectName) {
                var rowInput = row.querySelector('.hours-input');
                if (rowInput !== input && rowInput.value !== '') {
                    // Trigger validation on other inputs without recursion
                    var event = new Event('change', { bubbles: true });
                    setTimeout(function() { rowInput.dispatchEvent(event); }, 10);
                }
            }
        });
    };

    window.applyBulkUpdate = function () {
        var form = document.getElementById('bulkUpdateForm');
        var inputs = form.querySelectorAll('.hours-input');
        var hasChanges = false, hasErrors = false;
        var errorDetails = [];
        
        inputs.forEach(function (input) {
            if (input.value !== '' && parseFloat(input.value) !== parseFloat(input.dataset.original)) {
                hasChanges = true;
                
                // Check for validation errors
                if (!input.checkValidity()) {
                    hasErrors = true;
                    var userName = input.closest('tr').querySelector('td:first-child strong').textContent;
                    var projectName = input.closest('tr').querySelector('td:nth-child(2) strong').textContent;
                    var newHours = parseFloat(input.value);
                    var minAllowed = parseFloat(input.dataset.min);
                    var maxAllowed = parseFloat(input.dataset.maxAllowed);
                    
                    if (newHours < minAllowed) {
                        errorDetails.push('• ' + userName + ' (' + projectName + '): Cannot allocate ' + newHours.toFixed(1) + 'h - must be at least ' + minAllowed.toFixed(1) + 'h (utilized hours)');
                    } else if (newHours > maxAllowed) {
                        errorDetails.push('• ' + userName + ' (' + projectName + '): Cannot allocate ' + newHours.toFixed(1) + 'h - exceeds maximum ' + maxAllowed.toFixed(1) + 'h (project budget limit)');
                    }
                }
            }
        });
        
        if (!hasChanges) { 
            showBulkToast('No changes detected. Please modify some hours before saving.', 'warning'); 
            return; 
        }
        
        if (hasErrors) { 
            var errorMsg = 'Cannot save due to validation errors:\n\n' + errorDetails.join('\n') + '\n\nPlease adjust the hours to be within the allowed range.';
            if (typeof confirmModal === 'function') {
                confirmModal(errorMsg, function(){}, { 
                    title: 'Validation Errors', 
                    confirmText: 'OK', 
                    confirmClass: 'btn-primary',
                    showCancel: false
                });
            } else {
                alert(errorMsg);
            }
            return; 
        }
        
        var doSubmit = function () { form.submit(); };
        if (typeof confirmModal === 'function') confirmModal('Are you sure you want to apply these changes?', doSubmit);
        else if (window.confirm('Are you sure you want to apply these changes?')) doSubmit();
    };

    window.resetChanges = function () {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            input.value = ''; input.style.borderColor = ''; input.style.backgroundColor = '';
            if (input.nextElementSibling) input.nextElementSibling.textContent = '';
            input.setCustomValidity('');
        });
        var ta = document.querySelector('textarea[name="bulk_reason"]');
        if (ta) ta.value = '';
    };

    window.increaseAll = function (amount) {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            var current = parseFloat(input.dataset.original) || 0;
            var maxAllowed = parseFloat(input.dataset.maxAllowed || input.max || current) || current;
            input.value = Math.min(maxAllowed, current + amount).toFixed(1);
            window.validateHours(input);
        });
    };

    window.decreaseAll = function (amount) {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            var current = parseFloat(input.dataset.original) || 0;
            var minAllowed = parseFloat(input.dataset.min || '0') || 0;
            input.value = Math.max(minAllowed, current - amount).toFixed(1);
            window.validateHours(input);
        });
    };

    window.clearAll = function () {
        document.querySelectorAll('.hours-input').forEach(function (input) {
            input.value = ''; input.style.borderColor = ''; input.style.backgroundColor = '';
            if (input.nextElementSibling) input.nextElementSibling.textContent = '';
            input.setCustomValidity('');
        });
    };
})();
