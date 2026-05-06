        <!-- Feedback Tab -->
        <div class="tab-pane fade" id="feedback" role="tabpanel">
            <span id="feedback_probe" data-file="tab_feedback.php" style="display:none;"></span>
            <div class="mb-3">
                <h5>Project Feedback</h5>
                <p class="text-muted">Submit feedback related to this project. You can target specific users or make it visible to all project members.</p>
            </div>

            <?php
            $adminResources = [];
            if (in_array($userRole, ['admin'])) {
                $adminResources = $db->query("SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
            }
            ?>

            <div class="card mb-3">
                <div class="card-body">
                    <form id="projectFeedbackForm">
                        <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                        <div class="mb-3">
                            <label class="form-label">Recipients (project members)</label>
                            <select id="pf_recipients" name="recipient_ids[]" class="form-select" multiple>
                                <?php
                                // Get project team
                                $team = $db->prepare("
                                    SELECT 
                                        ua.id,
                                        ua.project_id,
                                        ua.user_id,
                                        ua.role,
                                        ua.assigned_by,
                                        ua.assigned_at,
                                        ua.hours_allocated,
                                        ua.is_removed,
                                        u.full_name,
                                        u.username,
                                        u.email,
                                        u.role as user_role
                                    FROM user_assignments ua
                                    JOIN users u ON ua.user_id = u.id
                                    WHERE ua.project_id = ? AND (ua.is_removed IS NULL OR ua.is_removed = 0)
                                    UNION
                                    SELECT 
                                        NULL as id,
                                        NULL as project_id,
                                        p.project_lead_id as user_id,
                                        'project_lead' as role, 
                                        NULL as assigned_by,
                                        NULL as assigned_at,
                                        NULL as hours_allocated,
                                        NULL as is_removed,
                                        pl.full_name,
                                        pl.username,
                                        pl.email,
                                        pl.role as user_role
                                    FROM projects p
                                    JOIN users pl ON p.project_lead_id = pl.id
                                    WHERE p.id = ? AND p.project_lead_id IS NOT NULL
                                    AND p.project_lead_id NOT IN (
                                        SELECT user_id FROM user_assignments WHERE project_id = ? AND (is_removed IS NULL OR is_removed = 0)
                                    )
                                    ORDER BY full_name
                                ");
                                $team->execute([$projectId, $projectId, $projectId]);
                                while ($m = $team->fetch()):
                                ?>
                                <option value="<?php echo (int)$m['user_id']; ?>" data-username="<?php echo htmlspecialchars($m['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($m['full_name']); ?> (<?php echo htmlspecialchars($m['role']); ?>)</option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php if (!empty($adminResources)): ?>
                        <div class="mb-3">
                            <label class="form-label">Admin Resource Name (optional)</label>
                            <select id="pf_admin_resource" class="form-select">
                                <option value="">Select resource</option>
                                <?php foreach ($adminResources as $res): ?>
                                    <option value="<?php echo (int)$res['id']; ?>">
                                        <?php echo htmlspecialchars($res['full_name']); ?> (<?php echo htmlspecialchars($res['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Admins can send feedback directly to a specific resource.</small>
                        </div>
                        <?php endif; ?>
                        <div class="mb-3 form-check">
                            <input class="form-check-input" type="checkbox" id="pf_is_generic" name="is_generic" value="1">
                            <label class="form-check-label" for="pf_is_generic">Project feedback (visible to all project members)</label>
                        </div>
                        <div class="mb-3 form-check">
                            <input class="form-check-input" type="checkbox" id="pf_send_to_admin" name="send_to_admin" value="1">
                            <label class="form-check-label" for="pf_send_to_admin">Send to Admin</label>
                        </div>
                        <div class="mb-3 form-check">
                            <input class="form-check-input" type="checkbox" id="pf_send_to_lead" name="send_to_lead" value="1">
                            <label class="form-check-label" for="pf_send_to_lead">Send to Project Lead</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Feedback</label>
                            <textarea id="pf_editor"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Submit Feedback</button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <h6>Feedback received</h6>
                    <?php
                    // Fetch feedbacks visible to this user
                    if (in_array($userRole, ['admin'])) {
                        $fbStmt = $db->prepare("SELECT f.*, u.full_name as sender_name FROM feedbacks f LEFT JOIN users u ON f.sender_id = u.id WHERE f.project_id = ? ORDER BY f.created_at DESC");
                        $fbStmt->execute([$projectId]);
                    } else {
                        $fbStmt = $db->prepare("SELECT DISTINCT f.*, u.full_name as sender_name FROM feedbacks f LEFT JOIN feedback_recipients fr ON f.id = fr.feedback_id LEFT JOIN users u ON f.sender_id = u.id WHERE (f.project_id = ? AND f.is_generic = 1) OR fr.user_id = ? OR f.sender_id = ? ORDER BY f.created_at DESC");
                        $fbStmt->execute([$projectId, $userId, $userId]);
                    }
                    while ($f = $fbStmt->fetch()):
                    ?>
                    <div class="mb-3 border-bottom pb-2">
                        <div class="d-flex justify-content-between">
                            <div><strong><?php echo htmlspecialchars($f['sender_name'] ?: 'Unknown'); ?></strong> <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($f['created_at'])); ?></small></div>
                            <div><small class="text-muted"><?php echo $f['is_generic'] ? 'Project-wide' : 'Private'; ?></small></div>
                        </div>
                        <div class="mt-2">
                            <?php echo $f['content']; ?>
                        </div>
                    </div>
                    <?php 
                    endwhile; 
                    if ($fbStmt->rowCount() == 0):
                    ?>
                    <div class="text-muted">No feedback received yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
