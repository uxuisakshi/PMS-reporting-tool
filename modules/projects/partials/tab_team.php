        <!-- Team Tab -->
        <div class="tab-pane fade" id="team" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Project Team</h5>
                <?php 
                // Check if user can manage team
                $canManageTeam = in_array($userRole, ['admin', 'project_lead', 'qa']);
                
                // Also check client permissions for edit access
                if (!$canManageTeam) {
                    $canManageTeam = canEditProjectById($db, $userId, $projectId);
                }
                
                if ($canManageTeam): 
                ?>
                <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>" class="btn btn-primary">
                    <i class="fas fa-users-cog"></i> Manage Team
                </a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Team Member</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Assigned Date</th>
                            <th>Hours Allocated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Get project team
                        $team = $db->prepare("
                            SELECT ua.*, u.full_name, u.email, u.role as user_role 
                            FROM user_assignments ua
                            JOIN users u ON ua.user_id = u.id
                            WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
                            UNION
                            SELECT NULL as id, NULL as project_id, p.project_lead_id as user_id, 'project_lead' as role, 
                                   NULL as assigned_by, NULL as assigned_at, NULL as hours_allocated,
                                   NULL as is_removed, NULL as removed_at, NULL as removed_by,
                                   pl.full_name, pl.email, pl.role as user_role
                            FROM projects p
                            JOIN users pl ON p.project_lead_id = pl.id
                            WHERE p.id = ? AND p.project_lead_id IS NOT NULL
                            AND p.project_lead_id NOT IN (SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0))
                            ORDER BY 
                                CASE role 
                                    WHEN 'project_lead' THEN 1
                                    WHEN 'qa' THEN 2
                                    WHEN 'at_tester' THEN 3
                                    WHEN 'ft_tester' THEN 4
                                END, full_name
                        ");
                        $team->execute([$projectId, $projectId, $projectId]);
                        while ($member = $team->fetch()): ?>
                        <tr>
                            <td>
                                <?php if (in_array($userRole, ['admin'])): ?>
                                <a href="<?php echo $baseDir; ?>/modules/admin/users.php?view=<?php echo $member['user_id']; ?>" class="text-decoration-none">
                                    <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                </a>
                                <?php else: ?>
                                <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    // Use current user role, not assignment role
                                    $displayRole = $member['user_role'] ?? $member['role'];
                                    echo $displayRole === 'project_lead' ? 'warning' : 
                                         ($displayRole === 'qa' ? 'info' : 'primary');
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $displayRole)); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($member['email']); ?></td>
                            <td><?php echo $member['assigned_at'] ? date('M d, Y', strtotime($member['assigned_at'])) : 'N/A'; ?></td>
                            <td><?php echo $member['hours_allocated'] ?: 'Not set'; ?></td>
                            <td>
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>" 
                                   class="btn btn-sm btn-success" title="Message">
                                    <i class="fas fa-envelope"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
