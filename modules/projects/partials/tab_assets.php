        <!-- Assets Tab -->
        <div class="tab-pane fade" id="assets" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Project Assets</h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                    <i class="fas fa-plus"></i> Add Asset
                </button>
            </div>

            <?php 
            // Get project assets
            $assets = $db->prepare("
                SELECT pa.*, u.full_name as creator_name 
                FROM project_assets pa 
                LEFT JOIN users u ON pa.created_by = u.id 
                WHERE pa.project_id = ?
                ORDER BY pa.created_at DESC
            ");
            $assets->execute([$projectId]);
            
            if ($assets->rowCount() > 0): ?>
            <div class="row">
                <?php while ($asset = $assets->fetch()): ?>
                <div class="col-md-4 mb-3">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($asset['asset_name']); ?></h5>
                                <?php if ($asset['asset_type'] === 'file'): ?>
                                    <span class="badge bg-secondary"><i class="fas fa-file"></i> File</span>
                                <?php elseif ($asset['asset_type'] === 'text'): ?>
                                    <span class="badge bg-success"><i class="fas fa-edit"></i> Text/Blog</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark"><i class="fas fa-link"></i> Link</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($asset['asset_type'] === 'link'): ?>
                                <?php if ($asset['link_type']): ?>
                                    <p class="mb-1 text-muted small"><strong>Type:</strong> <?php echo htmlspecialchars($asset['link_type']); ?></p>
                                <?php endif; ?>
                                <p class="card-text">
                                    <a href="<?php echo htmlspecialchars($asset['main_url']); ?>" target="_blank" class="text-break">
                                        <i class="fas fa-external-link-alt small"></i> <?php echo htmlspecialchars($asset['main_url']); ?>
                                    </a>
                                </p>
                            <?php elseif ($asset['asset_type'] === 'text'): ?>
                                <?php if ($asset['link_type']): ?>
                                    <p class="mb-1 text-muted small"><strong>Category:</strong> <?php echo htmlspecialchars($asset['link_type']); ?></p>
                                <?php endif; ?>
                                <div class="card-text">
                                    <div class="text-content-preview" style="max-height: 150px; overflow: hidden;">
                                        <?php 
                                        $content = $asset['text_content'] ?: $asset['description'] ?: '';
                                        echo strlen($content) > 200 ? substr(strip_tags($content), 0, 200) . '...' : strip_tags($content);
                                        ?>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#viewTextModal"
                                            data-title="<?php echo htmlspecialchars($asset['asset_name']); ?>"
                                            data-content="<?php echo htmlspecialchars($content); ?>">
                                        <i class="fas fa-eye"></i> View Full Content
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="d-grid">
                                    <a href="<?php echo $baseDir . '/api/secure_file.php?path=' . urlencode($asset['file_path']); ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-download"></i> Download File
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent border-top-0 pt-0 d-flex justify-content-between align-items-end">
                            <small class="text-muted">
                                By: <?php echo htmlspecialchars($asset['creator_name'] ?: 'System'); ?>
                                <br>
                                <?php echo date('M d, Y', strtotime($asset['created_at'])); ?>
                            </small>
                            <?php 
                            // Only uploader or admin can edit/delete
                            $isUploader = ((int)$asset['created_by'] === (int)$userId);
                            $isAdmin = in_array($userRole, ['admin'], true);
                            $canEditDeleteAsset = $isUploader || $isAdmin;
                            
                            if ($canEditDeleteAsset): 
                            ?>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button"
                                        class="btn btn-sm btn-link text-primary p-0 border-0 js-edit-asset"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editAssetModal"
                                        data-asset-id="<?php echo (int)$asset['id']; ?>"
                                        data-project-id="<?php echo (int)$projectId; ?>"
                                        data-asset-name="<?php echo htmlspecialchars($asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-asset-type="<?php echo htmlspecialchars($asset['asset_type'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-main-url="<?php echo htmlspecialchars($asset['main_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-link-type="<?php echo htmlspecialchars($asset['link_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-description="<?php echo htmlspecialchars($asset['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-text-content="<?php echo htmlspecialchars($asset['text_content'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/handle_asset.php" 
                                      onsubmit="var form = this; confirmModal('Are you sure you want to delete this asset?', function(){ form.submit(); }); return false;"
                                      class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                                    <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                    <input type="hidden" name="delete_asset" value="1">
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0 border-0">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No assets uploaded for this project.
            </div>
            <?php endif; ?>

            <?php
            $assetHistoryStmt = $db->prepare("
                SELECT al.created_at, al.action, al.details, u.full_name AS actor_name
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.entity_type = 'project'
                  AND al.entity_id = ?
                  AND al.action IN ('Edited asset', 'Deleted asset')
                ORDER BY al.created_at DESC
                LIMIT 100
            ");
            $assetHistoryStmt->execute([$projectId]);
            $assetHistory = $assetHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-history"></i> Asset Edit/Delete History</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($assetHistory)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Action</th>
                                    <th>Asset</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assetHistory as $row): ?>
                                    <?php
                                    $details = json_decode($row['details'] ?? '', true);
                                    $assetNameFromLog = $details['asset_name'] ?? '-';
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($row['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($assetNameFromLog, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($row['actor_name'] ?: 'System', ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-3 text-muted">No edit/delete history available yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editAssetModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/handle_asset.php" enctype="multipart/form-data" id="editAssetForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="edit_asset" value="1">
                        <input type="hidden" name="project_id" id="edit_asset_project_id" value="<?php echo (int)$projectId; ?>">
                        <input type="hidden" name="asset_id" id="edit_asset_id">
                        <input type="hidden" name="asset_type" id="edit_asset_type">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Asset</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Asset Name *</label>
                                <input type="text" name="asset_name" id="edit_asset_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Asset Type</label>
                                <input type="text" id="edit_asset_type_text" class="form-control" readonly>
                            </div>

                            <div id="edit_asset_link_fields" style="display:none;">
                                <div class="mb-3">
                                    <label class="form-label">Link Type</label>
                                    <input type="text" name="link_type" id="edit_link_type" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">URL *</label>
                                    <input type="url" name="main_url" id="edit_main_url" class="form-control">
                                </div>
                            </div>

                            <div id="edit_asset_text_fields" style="display:none;">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <input type="text" name="text_category" id="edit_text_category" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Content *</label>
                                    <textarea name="text_content" id="edit_text_content" class="form-control" rows="8"></textarea>
                                </div>
                            </div>

                            <div id="edit_asset_file_fields" style="display:none;">
                                <div class="mb-3">
                                    <label class="form-label">Replace File (Optional)</label>
                                    <input type="file" name="asset_file" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/tab-assets.js"></script>

