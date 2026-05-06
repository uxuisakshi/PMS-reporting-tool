        <!-- Production Hours Tab -->
        <div class="tab-pane fade" id="production-hours" role="tabpanel">
            <span id="production_hours_probe" data-file="tab_production_hours.php" style="display:none;"></span>
            <div class="mb-3">
                <h5>Production Hours Tracking</h5>
                <p class="text-muted">Detailed breakdown of hours utilized by team members on this project.</p>
            </div>
            
            <div class="row mb-3">
                <?php 
                // Calculate total utilized hours first
                $utilizedStmt = $db->prepare("
                    SELECT COALESCE(SUM(hours_spent), 0) as total_utilized 
                    FROM project_time_logs 
                    WHERE project_id = ? AND is_utilized = 1
                ");
                $utilizedStmt->execute([$projectId]);
                $totalUtilized = $utilizedStmt->fetchColumn();

                // Calculate overshoot hours (relative to BUDGET hours, not allocated)
                $overshootHours = max(0, ($totalUtilized - $budgetHours));
                $hasOvershoot = $overshootHours > 0;
                ?>
                
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4 class="text-primary"><?php echo number_format($budgetHours, 1); ?></h4>
                            <small class="text-muted">Budget Hours</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4 class="text-info">
                                <?php echo number_format($allocatedHours, 1); ?>
                            </h4>
                            <small class="text-muted">Allocated to Team</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4 class="text-success">
                                <?php echo number_format($totalUtilized, 1); ?>
                            </h4>
                            <small class="text-muted">Total Utilized</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4 class="text-warning">
                                <?php 
                                // Remaining budget = budget hours minus utilized hours
                                echo number_format($availableHours, 1);
                                ?>
                            </h4>
                            <small class="text-muted">Remaining Budget</small>
                        </div>
                    </div>
                </div>
                
                <?php if ($hasOvershoot): ?>
                <div class="col-md-2">
                    <div class="card text-center border-danger">
                        <div class="card-body">
                            <h4 class="text-danger">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo number_format($overshootHours, 1); ?>
                            </h4>
                            <small class="text-muted">Overshoot Hours</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h4 class="text-info">
                                <?php 
                                if ($budgetHours > 0) {
                                    echo round(($totalUtilized / $budgetHours) * 100, 1) . '%';
                                } else {
                                    echo '0%';
                                }
                                ?>
                            </h4>
                            <small class="text-muted">Budget Utilization</small>
                        </div>
                    </div>
                </div>
                
                <?php if ($hasOvershoot): ?>
                <div class="col-12 mt-2">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Budget Overshoot Alert:</strong> 
                        This project has exceeded its budget by <?php echo number_format($overshootHours, 1); ?> hours 
                        (<?php echo $budgetHours > 0 ? round(($overshootHours / $budgetHours) * 100, 1) : 0; ?>% over budget).
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <!-- Quick log form -->
            <div class="mb-3">
                <form id="logProductionHoursForm" class="row g-2 align-items-end">
                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">

                    <?php if (in_array($userRole, ['admin'])): ?>
                    <div class="col-md-3">
                        <label class="form-label">User</label>
                        <select name="user_id" class="form-select">
                            <?php foreach ($projectUsers as $pu): ?>
                                <option value="<?php echo $pu['id']; ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                    <?php endif; ?>

                    <div class="col-md-2">
                        <label class="form-label">Date</label>
                        <div class="input-group">
                            <input type="date" id="proj_log_date" name="log_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            <button type="button" id="proj_log_date_info" class="btn btn-outline-secondary" tabindex="0" data-bs-toggle="tooltip" data-bs-trigger="click" data-bs-placement="top" data-bs-container="body" data-bs-boundary="window" title="" aria-label="View allowed log date range">
                                <i class="fas fa-info-circle" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <!-- page/env selects are provided in the Page Testing container below -->

                    <div class="col-md-3">
                        <label class="form-label">Task Type</label>
                        <select name="task_type" id="taskTypeSelect" class="form-select">
                            <option value="">Select Task Type</option>
                            <option value="page_testing">Page Testing</option>
                            <option value="page_qa">Page QA</option>
                            <option value="regression_testing">Regression Testing</option>
                            <option value="project_phase">Project Phase</option>
                            <option value="generic_task">Generic Task</option>
                        </select>
                    </div>
                    <!-- Testing Type removed (using Task Type instead) -->

                    <div class="col-md-12 mt-2" id="pageTestingContainer" style="display:none;">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Page/Screen (Multiple)</label>
                                <select name="page_ids[]" id="productionPageSelect" class="form-select" multiple size="4">
                                    <option value="">(none)</option>
                                    <?php foreach ($projectPages as $ppg): ?>
                                        <option value="<?php echo (int)$ppg['id']; ?>"><?php echo htmlspecialchars($ppg['page_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Environments (Multiple)</label>
                                <select name="environment_ids[]" id="productionEnvSelect" class="form-select" multiple size="3">
                                    <option value="">Select page first</option>
                                </select>
                                <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Testing Type</label>
                                <select name="testing_type" id="testingTypeSelect" class="form-select">
                                    <option value="at_testing">AT Testing</option>
                                    <option value="ft_testing">FT Testing</option>
                                </select>
                                <div class="mt-2" id="productionIssueContainer" style="display:none;">
                                    <label class="form-label">Issue (optional)</label>
                                    <select name="issue_id" id="productionIssueSelect" class="form-select">
                                        <option value="">Select issue (optional)</option>
                                    </select>
                                    <small class="text-muted">Select an issue when logging regression hours</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 mt-2" id="projectPhaseContainer" style="display:none;">
                        <div class="row">
                                <div class="col-md-6">
                                <label class="form-label">Project Phase</label>
                                <select name="phase_id" id="projectPhaseSelect" class="form-select">
                                    <option value="">Select project phase</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phase Activity</label>
                                <select name="phase_activity" class="form-select">
                                    <option value="scoping">Scoping & Analysis</option>
                                    <option value="setup">Setup & Configuration</option>
                                    <option value="testing">Testing Activities</option>
                                    <option value="review">Review & Documentation</option>
                                    <option value="training">Training & Knowledge Transfer</option>
                                    <option value="reporting">Reporting & VPAT</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 mt-2" id="genericTaskContainer" style="display:none;">
                        <div class="row">
                                <div class="col-md-6">
                                <label class="form-label">Task Category</label>
                                <select name="generic_category_id" id="genericCategorySelect" class="form-select">
                                    <option value="">Select category</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Task Details</label>
                                <input type="text" name="generic_task_detail" class="form-control" placeholder="Specific task details">
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 mt-2" id="regressionContainer" style="display:none;">
                        <label class="form-label">Regression Summary</label>
                        <div id="regressionSummary" class="border rounded p-2">Loading…</div>
                        <div class="row mt-2">
                            <div class="col-md-4">
                                <label class="form-label">Issue Count</label>
                                <input type="number" name="issue_count" id="regressionIssueCount" class="form-control" min="1" step="1" placeholder="e.g., 5">
                                <small class="text-muted">Number of issues covered in this regression log</small>
                            </div>
                        </div>
                    </div>


                    <div class="col-md-2">
                        <label class="form-label">Hours</label>
                        <input type="number" id="logHoursInput" name="hours" class="form-control" step="0.01" min="0.01" placeholder="e.g., 1.50" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Description</label>
                        <input type="text" id="logDescriptionInput" name="description" class="form-control" placeholder="Short description (optional)">
                    </div>

                    <!-- Utilized checkbox removed; production logs default to utilized -->

                    <div class="col-md-1">
                        <button type="submit" id="logTimeBtn" class="btn btn-primary">Log</button>
                    </div>
                </form>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Hours Breakdown by Team Member</h6>
                </div>
                <div class="card-body">
                    <?php $projectTotalHoursForOverall = (float)($totalHours ?? 0); ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Team Member</th>
                                    <th>Role</th>
                                    <th>Allocated Hours</th>
                                    <th>Utilized Hours</th>
                                    <th>Project Hours %</th>
                                    <th>Remaining Hours</th>
                                    <th>Utilization %</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Preload project time logs for this project (utilized entries)
                                $logsStmt = $db->prepare(" 
                                    SELECT ptl.*, pp.page_name, te.name as environment_name, ph.phase_name, gtc.name as generic_category_name, i.issue_key 
                                    FROM project_time_logs ptl
                                    LEFT JOIN project_pages pp ON ptl.page_id = pp.id
                                    LEFT JOIN testing_environments te ON ptl.environment_id = te.id
                                    LEFT JOIN project_phases ph ON ptl.phase_id = ph.id
                                    LEFT JOIN generic_task_categories gtc ON ptl.generic_category_id = gtc.id
                                    LEFT JOIN issues i ON ptl.issue_id = i.id
                                    WHERE ptl.project_id = ? AND ptl.is_utilized = 1
                                    ORDER BY ptl.user_id, ptl.log_date DESC, ptl.id DESC
                                ");
                                $logsStmt->execute([$projectId]);
                                $allLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
                                $logsByUser = [];
                                foreach ($allLogs as $l) { $logsByUser[intval($l['user_id'])][] = $l; }
                                $logHistoryByLogId = [];
                                try {
                                    $logIds = array_values(array_unique(array_map(function($row) { return (int)$row['id']; }, $allLogs)));
                                    if (!empty($logIds)) {
                                        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
                                        $histStmt = $db->prepare("
                                            SELECT h.*, u.full_name as changed_by_name
                                            FROM project_time_log_history h
                                            LEFT JOIN users u ON u.id = h.changed_by
                                            WHERE h.time_log_id IN ($placeholders)
                                            ORDER BY h.changed_at DESC
                                        ");
                                        $histStmt->execute($logIds);
                                        $histRows = $histStmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($histRows as $hr) {
                                            $hid = (int)($hr['time_log_id'] ?? 0);
                                            if ($hid > 0) {
                                                if (!isset($logHistoryByLogId[$hid])) {
                                                    $logHistoryByLogId[$hid] = [];
                                                }
                                                $logHistoryByLogId[$hid][] = $hr;
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                    $logHistoryByLogId = [];
                                }

                                // Get detailed hours breakdown by user
                                $hoursBreakdown = $db->prepare(" 
                                    SELECT 
                                        u.id as user_id,
                                        u.full_name,
                                        u.role as user_role,
                                        ua.role as project_role,
                                        ua.hours_allocated,
                                        COALESCE(SUM(ptl.hours_spent), 0) as hours_utilized,
                                        MAX(ptl.log_date) as last_activity_date
                                    FROM user_assignments ua
                                    JOIN users u ON ua.user_id = u.id
                                    LEFT JOIN project_time_logs ptl ON ua.user_id = ptl.user_id AND ua.project_id = ptl.project_id AND ptl.is_utilized = 1
                                    WHERE ua.project_id = ?
                                    GROUP BY u.id, u.full_name, u.role, ua.role, ua.hours_allocated
                                    
                                    UNION ALL
                                    
                                    SELECT 
                                        pl.id as user_id,
                                        pl.full_name,
                                        pl.role as user_role,
                                        'project_lead' as project_role,
                                        NULL as hours_allocated,
                                        COALESCE(SUM(ptl.hours_spent), 0) as hours_utilized,
                                        MAX(ptl.log_date) as last_activity_date
                                    FROM projects p
                                    JOIN users pl ON p.project_lead_id = pl.id
                                    LEFT JOIN project_time_logs ptl ON pl.id = ptl.user_id AND p.id = ptl.project_id AND ptl.is_utilized = 1
                                    WHERE p.id = ? AND p.project_lead_id IS NOT NULL
                                    AND p.project_lead_id NOT IN (SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0))
                                    GROUP BY pl.id, pl.full_name, pl.role
                                    
                                    ORDER BY hours_utilized DESC, full_name
                                ");
                                $hoursBreakdown->execute([$projectId, $projectId, $projectId]);
                                
                                while ($member = $hoursBreakdown->fetch()): 
                                    $allocatedHours = $member['hours_allocated'] ?: 0;
                                    $utilizedHours = $member['hours_utilized'] ?: 0;
                                    $projectHoursUsagePercent = $projectTotalHoursForOverall > 0 ? round(($utilizedHours / $projectTotalHoursForOverall) * 100, 1) : 0;
                                    $remainingHours = max(0, $allocatedHours - $utilizedHours);
                                    $utilizationPercent = $allocatedHours > 0 ? round(($utilizedHours / $allocatedHours) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <?php if (in_array($userRole, ['admin'])): ?>
                                        <div class="d-flex align-items-center">
                                            <a href="<?php echo $baseDir; ?>/modules/admin/users.php?view=<?php echo $member['user_id']; ?>" class="text-decoration-none me-2">
                                                <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                            </a>
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#userLogs<?php echo $member['user_id']; ?>" aria-expanded="false" aria-controls="userLogs<?php echo $member['user_id']; ?>">
                                                Details
                                            </button>
                                        </div>
                                        <?php else: ?>
                                        <div class="d-flex align-items-center">
                                            <strong class="me-2"><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#userLogs<?php echo $member['user_id']; ?>" aria-expanded="false" aria-controls="userLogs<?php echo $member['user_id']; ?>">
                                                Details
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $member['project_role'] === 'project_lead' ? 'warning' : 
                                                 ($member['project_role'] === 'qa' ? 'info' : 'primary');
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $member['project_role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $allocatedHours ?: 'Not set'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $utilizedHours > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo $utilizedHours; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($projectTotalHoursForOverall > 0): ?>
                                        <span class="badge bg-primary">
                                            <?php echo number_format($projectHoursUsagePercent, 1); ?>%
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($allocatedHours > 0): ?>
                                        <span class="badge bg-<?php echo $remainingHours > 0 ? 'warning' : 'success'; ?>">
                                            <?php echo $remainingHours; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($allocatedHours > 0): ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-<?php echo $utilizationPercent > 100 ? 'danger' : ($utilizationPercent > 80 ? 'warning' : 'success'); ?>" 
                                                 style="width: <?php echo min(100, $utilizationPercent); ?>%">
                                                <?php echo $utilizationPercent; ?>%
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($member['last_activity_date']): ?>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($member['last_activity_date'])); ?></small>
                                        <?php else: ?>
                                        <small class="text-muted">No activity</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr class="table-sm">
                                    <td colspan="8" class="p-0 border-0">
                                        <div class="collapse" id="userLogs<?php echo $member['user_id']; ?>">
                                            <div class="p-2">
                                                <h6 class="mb-2">Detailed Time Logs</h6>
                                                <?php $uLogs = isset($logsByUser[$member['user_id']]) ? $logsByUser[$member['user_id']] : []; ?>
                                                <?php if (count($uLogs) === 0): ?>
                                                    <div class="text-muted small">No utilized time entries for this user on this project.</div>
                                                <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-bordered mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th style="width:100px">Date</th>
                                                                    <th>Task Type</th>
                                                                    <th>Page / Screen</th>
                                                                    <th>Environment</th>
                                                                    <th>Phase / Category</th>
                                                                    <th>Issue</th>
                                                                    <th style="width:100px">Hours</th>
                                                                    <th>Description</th>
                                                                    <th style="width:120px">History</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php foreach ($uLogs as $log): ?>
                                                                <?php $logHist = $logHistoryByLogId[(int)$log['id']] ?? []; ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($log['log_date']); ?></td>
                                                                    <td><?php echo htmlspecialchars(formatTaskType($log['task_type'] ?: ($log['testing_type'] ?: ''))); ?></td>
                                                                    <td><?php echo htmlspecialchars($log['page_name'] ?: ''); ?></td>
                                                                    <td><?php echo htmlspecialchars($log['environment_name'] ?: ''); ?></td>
                                                                    <td><?php echo htmlspecialchars($log['phase_name'] ?: $log['generic_category_name'] ?: ''); ?></td>
                                                                    <td><?php echo htmlspecialchars($log['issue_key'] ?: ''); ?></td>
                                                                    <td><?php echo htmlspecialchars(number_format($log['hours_spent'], 2)); ?></td>
                                                                    <td><?php echo htmlspecialchars($log['description'] ?? $log['comments'] ?? ''); ?></td>
                                                                    <td>
                                                                        <?php if (!empty($logHist)): ?>
                                                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#logHist<?php echo (int)$log['id']; ?>" aria-expanded="false" aria-controls="logHist<?php echo (int)$log['id']; ?>">
                                                                                <?php echo count($logHist); ?> events
                                                                            </button>
                                                                        <?php else: ?>
                                                                            <span class="text-muted small">No changes</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                                <?php if (!empty($logHist)): ?>
                                                                <tr>
                                                                    <td colspan="9" class="p-0 border-0">
                                                                        <div class="collapse" id="logHist<?php echo (int)$log['id']; ?>">
                                                                            <div class="p-2 bg-light border rounded">
                                                                                <?php foreach ($logHist as $h): ?>
                                                                                    <div class="small mb-2">
                                                                                        <strong><?php echo htmlspecialchars(ucfirst($h['action_type'])); ?></strong>
                                                                                        by <?php echo htmlspecialchars($h['changed_by_name'] ?: 'Unknown'); ?>
                                                                                        on <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($h['changed_at']))); ?>
                                                                                        <?php if (!empty($h['old_log_date']) || !empty($h['new_log_date'])): ?>
                                                                                            <div class="text-muted">Date: <?php echo htmlspecialchars($h['old_log_date'] ?: '-'); ?> -> <?php echo htmlspecialchars($h['new_log_date'] ?: '-'); ?></div>
                                                                                        <?php endif; ?>
                                                                                        <?php if ($h['old_hours'] !== null || $h['new_hours'] !== null): ?>
                                                                                            <div class="text-muted">Hours: <?php echo htmlspecialchars($h['old_hours'] !== null ? number_format((float)$h['old_hours'], 2) : '-'); ?> -> <?php echo htmlspecialchars($h['new_hours'] !== null ? number_format((float)$h['new_hours'], 2) : '-'); ?></div>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
