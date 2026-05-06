/**
 * Hours Validation JavaScript
 * Provides real-time validation for project hours allocation
 */

class HoursValidator {
    constructor(baseUrl = '') {
        this.baseUrl = baseUrl;
        this.cache = new Map();
        this.cacheTimeout = 30000; // 30 seconds
    }

    /**
     * Get project hours summary
     */
    async getProjectSummary(projectId) {
        const cacheKey = `summary_${projectId}`;
        const cached = this.cache.get(cacheKey);
        
        if (cached && Date.now() - cached.timestamp < this.cacheTimeout) {
            return cached.data;
        }

        try {
            const response = await fetch(`${this.baseUrl}/api/project_hours.php?action=summary&project_id=${projectId}`);
            const result = await response.json();
            
            if (result.success) {
                this.cache.set(cacheKey, {
                    data: result.data,
                    timestamp: Date.now()
                });
                return result.data;
            } else {
                throw new Error(result.error || 'Failed to get project summary');
            }
        } catch (error) {
            console.error('Error getting project summary:', error);
            throw error;
        }
    }

    /**
     * Validate hours allocation
     */
    async validateAllocation(projectId, hours, excludeAssignmentId = null) {
        try {
            let url = `${this.baseUrl}/api/project_hours.php?action=validate&project_id=${projectId}&hours=${hours}`;
            if (excludeAssignmentId) {
                url += `&exclude_assignment_id=${excludeAssignmentId}`;
            }

            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                return result.validation;
            } else {
                throw new Error(result.error || 'Validation failed');
            }
        } catch (error) {
            console.error('Error validating hours:', error);
            throw error;
        }
    }

    /**
     * Get available projects for assignment
     */
    async getAvailableProjects() {
        const cacheKey = 'available_projects';
        const cached = this.cache.get(cacheKey);
        
        if (cached && Date.now() - cached.timestamp < this.cacheTimeout) {
            return cached.data;
        }

        try {
            const response = await fetch(`${this.baseUrl}/api/project_hours.php?action=available_projects`);
            const result = await response.json();
            
            if (result.success) {
                this.cache.set(cacheKey, {
                    data: result.data,
                    timestamp: Date.now()
                });
                return result.data;
            } else {
                throw new Error(result.error || 'Failed to get available projects');
            }
        } catch (error) {
            console.error('Error getting available projects:', error);
            throw error;
        }
    }

    /**
     * Update project hours display
     */
    updateProjectHoursDisplay(containerId, summary) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const totalHours = summary.total_hours || 0;
        const allocatedHours = summary.allocated_hours || 0;
        const utilizedHours = summary.utilized_hours || 0;
        const availableHours = summary.available_hours || 0;

        container.innerHTML = `
            <div class="row text-center">
                <div class="col-3">
                    <h6 class="text-primary">${totalHours.toFixed(2)}h</h6>
                    <small class="text-muted">Total</small>
                </div>
                <div class="col-3">
                    <h6 class="text-info">${allocatedHours.toFixed(2)}h</h6>
                    <small class="text-muted">Allocated</small>
                </div>
                <div class="col-3">
                    <h6 class="text-success">${utilizedHours.toFixed(2)}h</h6>
                    <small class="text-muted">Utilized</small>
                </div>
                <div class="col-3">
                    <h6 class="text-warning">${availableHours.toFixed(2)}h</h6>
                    <small class="text-muted">Available</small>
                </div>
            </div>
            ${totalHours > 0 ? `
            <div class="mt-2">
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar bg-success" style="width: ${Math.min(100, (utilizedHours / totalHours) * 100)}%" title="Utilized"></div>
                    <div class="progress-bar bg-info" style="width: ${Math.min(100 - (utilizedHours / totalHours) * 100, ((allocatedHours - utilizedHours) / totalHours) * 100)}%" title="Allocated"></div>
                </div>
            </div>
            ` : ''}
        `;
    }

    /**
     * Setup real-time validation for hours input
     */
    setupHoursInput(inputId, projectSelectId, validationMessageId, excludeAssignmentId = null) {
        const hoursInput = document.getElementById(inputId);
        const projectSelect = document.getElementById(projectSelectId);
        const validationMessage = document.getElementById(validationMessageId);

        if (!hoursInput || !projectSelect || !validationMessage) {
            console.warn('Hours validation setup: Required elements not found');
            return;
        }

        const validateHours = async () => {
            const projectId = projectSelect.value;
            const hours = parseFloat(hoursInput.value) || 0;

            if (!projectId || hours <= 0) {
                validationMessage.textContent = 'Select a project and enter hours';
                validationMessage.className = 'text-muted';
                hoursInput.setCustomValidity('');
                return;
            }

            try {
                const validation = await this.validateAllocation(projectId, hours, excludeAssignmentId);
                
                if (validation.valid) {
                    const remaining = validation.available_hours - hours;
                    validationMessage.textContent = `Valid. ${remaining.toFixed(2)}h will remain available`;
                    validationMessage.className = 'text-success';
                    hoursInput.setCustomValidity('');
                } else {
                    validationMessage.textContent = validation.message;
                    validationMessage.className = 'text-danger';
                    hoursInput.setCustomValidity(validation.message);
                }
            } catch (error) {
                validationMessage.textContent = 'Error validating hours';
                validationMessage.className = 'text-danger';
                hoursInput.setCustomValidity('Validation error');
            }
        };

        // Debounce validation
        let validationTimeout;
        const debouncedValidate = () => {
            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(validateHours, 500);
        };

        hoursInput.addEventListener('input', debouncedValidate);
        projectSelect.addEventListener('change', debouncedValidate);
    }

    /**
     * Clear cache
     */
    clearCache() {
        this.cache.clear();
    }
}

// Global instance — use baseDir from page config if available
window.hoursValidator = new HoursValidator(
    (window._manageHoursConfig && window._manageHoursConfig.baseDir) ? window._manageHoursConfig.baseDir : ''
);

// Helper functions for backward compatibility
window.validateProjectHours = async function(projectId, hours, excludeAssignmentId = null) {
    return await window.hoursValidator.validateAllocation(projectId, hours, excludeAssignmentId);
};

window.updateProjectHoursInfo = function(projectId, containerId) {
    window.hoursValidator.getProjectSummary(projectId)
        .then(summary => window.hoursValidator.updateProjectHoursDisplay(containerId, summary))
        .catch(error => console.error('Error updating project hours info:', error));
};