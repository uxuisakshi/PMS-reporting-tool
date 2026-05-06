        <!-- Phases Tab -->
        <div class="tab-pane fade show active" id="phases" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Project Phases</h5>
                <?php 
                // Check if user can manage phases
                $canManagePhases = in_array($userRole, ['admin', 'project_lead', 'admin']);
                
                // Also check client permissions for edit access
                if (!$canManagePhases) {
                    $canManagePhases = canEditProjectById($db, $userId, $projectId);
                }
                
                if ($canManagePhases): 
                ?>
                <div>
                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addPhaseModal">
                        <i class="fas fa-plus"></i> Add Phase
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Phase</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Planned Hours</th>
                            <th>Actual Hours</th>
                            <th>Status</th>
                            <?php if ($canManagePhases): ?>
                            <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Get project phases
                        $phases = $db->prepare("
                            SELECT * FROM project_phases 
                            WHERE project_id = ? 
                            ORDER BY FIELD(phase_name, 'po_received', 'scoping_confirmation', 'testing', 'regression', 'training', 'vpat_acr')
                        ");
                        $phases->execute([$projectId]);
                        while ($phase = $phases->fetch()): 
                        ?>
                        <tr>
                            <td><?php echo ucfirst(str_replace('_', ' ', $phase['phase_name'])); ?></td>
                            <td><?php echo $phase['start_date'] ? date('M d, Y', strtotime($phase['start_date'])) : 'Not started'; ?></td>
                            <td><?php echo $phase['end_date'] ? date('M d, Y', strtotime($phase['end_date'])) : '-'; ?></td>
                            <td><?php echo $phase['planned_hours'] ?: '-'; ?></td>
                            <td>
                                <?php if ($phase['actual_hours'] > 0): ?>
                                <span class="badge bg-<?php echo $phase['actual_hours'] > $phase['planned_hours'] ? 'warning' : 'success'; ?>">
                                    <?php echo $phase['actual_hours']; ?>
                                </span>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                // Check if user can update phase status (includes QA role)
                                $canUpdatePhaseStatus = in_array($userRole, ['admin', 'project_lead', 'qa']);
                                if (!$canUpdatePhaseStatus) {
                                    $canUpdatePhaseStatus = canEditProjectById($db, $userId, $projectId);
                                }
                                
                                if ($canUpdatePhaseStatus): 
                                ?>
                                    <select class="form-select form-select-sm phase-status-update" 
                                            data-phase-id="<?php echo $phase['id']; ?>" 
                                            data-project-id="<?php echo $projectId; ?>">
                                        <option value="not_started" <?php echo $phase['status'] === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                        <option value="in_progress" <?php echo $phase['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="on_hold" <?php echo $phase['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                        <option value="completed" <?php echo $phase['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                <?php else: ?>
                                    <?php 
                                        $s = $phase['status'] ?: 'not_started';
                                        $badgeClass = 'secondary';
                                        if ($s === 'completed') $badgeClass = 'success';
                                        elseif ($s === 'in_progress') $badgeClass = 'primary';
                                        elseif ($s === 'on_hold') $badgeClass = 'warning';
                                    ?>
                                    <span class="badge bg-<?php echo $badgeClass; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $s)); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManagePhases): ?>
                            <td>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-phase-btn" 
                                            data-phase-id="<?php echo $phase['id']; ?>"
                                            data-phase-name="<?php echo htmlspecialchars($phase['phase_name']); ?>"
                                            data-start-date="<?php echo $phase['start_date']; ?>"
                                            data-end-date="<?php echo $phase['end_date']; ?>"
                                            data-planned-hours="<?php echo $phase['planned_hours']; ?>"
                                            data-status="<?php echo $phase['status']; ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editPhaseModal"
                                            title="Edit Phase">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="confirmModal('Delete this phase?', function(){ window.location.href='<?php echo $baseDir; ?>/modules/projects/phases.php?delete=<?php echo $phase['id']; ?>&project_id=<?php echo $projectId; ?>'; }); return false;"
                                            title="Delete Phase">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
