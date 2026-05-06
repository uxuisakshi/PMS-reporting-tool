<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$page_title = 'Hours Compliance Report';
include __DIR__ . '/../../includes/header.php';
?>
<style>
#complianceTable_wrapper .dataTables_length select {
    min-width: 100px;
    padding-right: 2.5rem !important;
    background-position: right 0.8rem center;
    text-overflow: clip;
}

.expand-btn {
    cursor: pointer;
    transition: transform 0.2s;
}

.expand-btn.expanded {
    transform: rotate(90deg);
}

.details-row {
    background-color: #f8f9fa;
}

.details-content {
    padding: 15px;
}

.time-log-entry {
    border-left: 3px solid #007bff;
    padding: 10px;
    margin-bottom: 10px;
    background: white;
    border-radius: 4px;
}

.time-log-entry:last-child {
    margin-bottom: 0;
}

.time-log-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}

.time-log-hours {
    font-weight: bold;
    color: #007bff;
}

.time-log-project {
    font-weight: 500;
    color: #333;
}

.time-log-details {
    font-size: 0.9em;
    color: #666;
}
</style>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-clock"></i> Daily Hours Compliance Report</h2>
            <p class="text-muted">Track which users have not met the minimum daily hours requirement</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" onclick="showSettingsModal()">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>
    </div>

    <!-- Date Selector -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <label class="form-label">Select Date</label>
                    <input type="date" class="form-control" id="reportDate" value="<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary d-block w-100" onclick="loadReport()">
                        <i class="fas fa-search"></i> Load Report
                    </button>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i> <strong>Minimum Hours:</strong> <span id="minHoursDisplay">8</span> hours per day
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Users</h5>
                    <h2 id="totalUsers">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Compliant</h5>
                    <h2 id="compliantUsers">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Non-Compliant</h5>
                    <h2 id="nonCompliantUsers">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Compliance Rate</h5>
                    <h2 id="complianceRate">0%</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Compliance Report Table -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list"></i> Compliance Report</h5>
            <div class="btn-group btn-group-sm" role="group">
                <input type="radio" class="btn-check" name="statusFilter" id="filterAll" value="all" checked>
                <label class="btn btn-outline-light" for="filterAll">All</label>

                <input type="radio" class="btn-check" name="statusFilter" id="filterCompliant" value="compliant">
                <label class="btn btn-outline-light" for="filterCompliant">Compliant</label>

                <input type="radio" class="btn-check" name="statusFilter" id="filterNonCompliant" value="non-compliant">
                <label class="btn btn-outline-light" for="filterNonCompliant">Non-Compliant</label>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="complianceTable">
                    <thead>
                        <tr>
                            <th style="width: 30px;"></th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Hours Logged</th>
                            <th>Status / Gap</th>
                            <th>Reminder</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Hours Reminder Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="settingsForm">
                    <div class="mb-3">
                        <label class="form-label">Reminder Time</label>
                        <input type="time" class="form-control" id="reminderTime" name="reminder_time" required>
                        <small class="text-muted">Time when daily reminder will be sent to users</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Minimum Hours Required</label>
                        <input type="number" step="0.01" class="form-control" id="minimumHours" name="minimum_hours" required>
                        <small class="text-muted">Minimum hours users must log per day</small>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">On-Time Login Cutoff</label>
                            <input type="time" class="form-control" id="loginCutoffTime" name="login_cutoff_time" required>
                            <small class="text-muted">Login before this time counts as on-time</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status Update Cutoff</label>
                            <input type="time" class="form-control" id="statusCutoffTime" name="status_cutoff_time" required>
                            <small class="text-muted">Availability update before this time counts as on-time</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notification Message</label>
                        <textarea class="form-control" id="notificationMessage" name="notification_message" rows="3" required></textarea>
                        <small class="text-muted">Message shown to users in reminder notification</small>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="excludeWeekends" name="exclude_weekends">
                            <label class="form-check-label" for="excludeWeekends">
                                Exclude Weekends From Compliance
                            </label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="excludeLeaveDays" name="exclude_leave_days">
                            <label class="form-check-label" for="excludeLeaveDays">
                                Exclude Leave Days From Compliance
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enabled" name="enabled">
                            <label class="form-check-label" for="enabled">
                                Enable Daily Reminders
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window.HoursComplianceConfig = { apiUrl: '../../api/hours_reminder.php' };
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/hours-compliance.js"></script>
<?php include '../../includes/footer.php'; 
