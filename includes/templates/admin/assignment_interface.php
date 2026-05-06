<?php
/**
 * Admin Assignment Interface Template
 * Interface for selecting clients and projects with assignment management
 */
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1>Client Project Assignment Management</h1>
            <p class="text-muted">Assign projects to client users and manage their access permissions.</p>
        </div>
    </div>
    
    <!-- Assignment Form -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Assign Projects to Client</h5>
                </div>
                <div class="card-body">
                    <form id="assignmentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        
                        <div class="mb-3">
                            <label for="clientUser" class="form-label">Client User</label>
                            <select class="form-select" id="clientUser" name="client_user_id" required>
                                <option value="">Select a client user...</option>
                                <?php foreach ($clientUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username'] . ' (' . $user['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="projects" class="form-label">Projects</label>
                            <select class="form-select" id="projects" name="project_ids" multiple required>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>">
                                        <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hold Ctrl/Cmd to select multiple projects</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="expiresAt" class="form-label">Expires At (Optional)</label>
                            <input type="datetime-local" class="form-control" id="expiresAt" name="expires_at">
                            <div class="form-text">Leave empty for permanent access</div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="notifyClient" name="notify_client" value="yes">
                            <label class="form-check-label" for="notifyClient">
                                Send notification email to client
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Assign Projects</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Revoke Project Access</h5>
                </div>
                <div class="card-body">
                    <form id="revocationForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        
                        <div class="mb-3">
                            <label for="revokeClientUser" class="form-label">Client User</label>
                            <select class="form-select" id="revokeClientUser" name="client_user_id" required>
                                <option value="">Select a client user...</option>
                                <?php foreach ($clientUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username'] . ' (' . $user['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="revokeProjects" class="form-label">Projects to Revoke</label>
                            <select class="form-select" id="revokeProjects" name="project_ids" multiple required>
                                <!-- Will be populated based on selected user -->
                            </select>
                            <div class="form-text">Only shows projects currently assigned to the selected user</div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="notifyClientRevoke" name="notify_client" value="yes">
                            <label class="form-check-label" for="notifyClientRevoke">
                                Send notification email to client
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-danger">Revoke Access</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Current Assignments -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Current Assignments</h5>
                    <button class="btn btn-secondary btn-sm" onclick="refreshAssignments()">
                        <i class="fas fa-refresh"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="assignmentsTable">
                            <thead>
                                <tr>
                                    <th>Client User</th>
                                    <th>Project</th>
                                    <th>Assigned Date</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assignment['username']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['project_name']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($assignment['assigned_at'])); ?></td>
                                        <td>
                                            <?php if ($assignment['expires_at']): ?>
                                                <?php echo date('Y-m-d H:i', strtotime($assignment['expires_at'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($assignment['active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="quickRevoke(<?php echo $assignment['client_user_id']; ?>, <?php echo $assignment['project_id']; ?>)">
                                                Revoke
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assignment History -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>Assignment History (Audit Trail)</h5>
                </div>
                <div class="card-body">
                    <div id="historyContainer">
                        <p class="text-muted">Loading assignment history...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Success/Error Messages -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="successToast" class="toast" role="alert">
        <div class="toast-header">
            <strong class="me-auto text-success">Success</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="successMessage"></div>
    </div>
    
    <div id="errorToast" class="toast" role="alert">
        <div class="toast-header">
            <strong class="me-auto text-danger">Error</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="errorMessage"></div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window._assignmentInterfaceConfig = {
    csrfToken: <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
};
</script>
<script src="<?php echo htmlspecialchars(getBaseDir(), ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-assignment-interface.js"></script>
