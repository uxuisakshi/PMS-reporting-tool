        <!-- Floating Project Chat (bottom-right) - Hidden for client role -->
        <?php if ($userRole !== 'client'): ?>
        <style>
        .chat-launcher { position: fixed; bottom: 20px; right: 20px; z-index: 1060; border-radius: 999px; box-shadow: 0 10px 24px rgba(0,0,0,0.18); padding: 12px 18px; display: flex; align-items: center; gap: 8px; }
        .chat-launcher i { font-size: 1.1rem; }
        .chat-launcher .badge { position: absolute !important; top: -8px !important; right: -8px !important; }

        .chat-widget { position: fixed; bottom: 86px; right: 20px; width: 360px; max-width: 92vw; height: 520px; max-height: 78vh; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,0.25); border: 1px solid #e5e7eb; overflow: hidden; z-index: 1060; display: none; }
        .chat-widget.open { display: block; }
        .chat-widget iframe { width: 100%; height: calc(100% - 48px); border: 0; }
        .chat-widget .chat-widget-header { height: 48px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #0d6efd, #4dabf7); color: #fff; }
        .chat-widget .chat-widget-header .btn { color: #fff; border-color: rgba(255,255,255,0.3); }
        .chat-widget .chat-widget-header .btn:hover { background: rgba(255,255,255,0.12); }

        @media (max-width: 576px) {
            .chat-widget { width: 94vw; height: 70vh; bottom: 76px; right: 3vw; }
            .chat-launcher { bottom: 14px; right: 14px; }
        }
        </style>

        <div class="chat-widget" id="projectChatWidget" aria-label="Project Chat">
            <div class="chat-widget-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="fas fa-comments"></i>
                    <strong>Project Chat</strong>
                </div>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetClose" aria-label="Close chat">
                        <i class="fas fa-times"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-light" id="chatWidgetFullscreen" aria-label="Open full chat">
                        <i class="fas fa-up-right-and-down-left-from-center"></i>
                    </button>
                </div>
            </div>
            <iframe src="" data-src="<?php echo $baseDir; ?>/modules/chat/project_chat.php?project_id=<?php echo $projectId; ?>&embed=1" title="Project Chat"></iframe>
        </div>

        <button type="button" class="btn btn-primary chat-launcher" id="chatLauncher">
            <i class="fas fa-comments"></i>
            <span>Project Chat</span>
            <?php if (isset($unreadChatCount) && $unreadChatCount > 0): ?>
                <span class="badge rounded-pill bg-danger" id="chatBadge">
                    <?php echo $unreadChatCount > 99 ? '99+' : $unreadChatCount; ?>
                    <span class="visually-hidden">unread messages</span>
                </span>
            <?php endif; ?>
        </button>
        <?php endif; ?>

<!-- Add Phase Modal -->
<div class="modal fade" id="addPhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/phases.php" id="addPhaseForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="hidden" name="phase_name" id="phaseNameHidden">
                <div class="modal-header">
                    <h5 class="modal-title">Add Project Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Phase Name <span class="text-danger">*</span></label>
                        <select id="phaseNameSelect" class="form-select" required>
                            <option value="">-- Select Phase --</option>
                            <?php
                            // Fetch active phases from phase_master
                            $phaseMasterStmt = $db->query("SELECT id, phase_name, typical_duration_days FROM phase_master WHERE is_active = 1 ORDER BY display_order ASC, phase_name ASC");
                            while ($pm = $phaseMasterStmt->fetch()):
                            ?>
                                <option value="<?php echo htmlspecialchars($pm['phase_name']); ?>" 
                                        data-phase-id="<?php echo $pm['id']; ?>"
                                        data-duration="<?php echo $pm['typical_duration_days'] ?: ''; ?>">
                                    <?php echo htmlspecialchars($pm['phase_name']); ?>
                                </option>
                            <?php endwhile; ?>
                            <option value="custom">-- Custom Phase Name --</option>
                        </select>
                        <small class="text-muted">Select from standard phases or choose "Custom" to enter your own</small>
                    </div>
                    <div class="mb-3" id="customPhaseNameDiv" style="display: none;">
                        <label class="form-label">Custom Phase Name <span class="text-danger">*</span></label>
                        <input type="text" id="customPhaseName" class="form-control" placeholder="Enter custom phase name">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="phaseStartDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="phaseEndDate" class="form-control">
                                <small class="text-muted" id="durationHint"></small>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planned Hours</label>
                        <input type="number" name="planned_hours" class="form-control" min="0" step="0.01" placeholder="e.g., 40.00">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="not_started">Not Started</option>
                            <option value="in_progress">In Progress</option>
                            <option value="on_hold">On Hold</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_phase" class="btn btn-success">Add Phase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo $baseDir; ?>/assets/js/project-modals.js?v=<?php echo time(); ?>"></script>

<!-- Edit Phase Modal -->
<div class="modal fade" id="editPhaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/phases.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <input type="hidden" name="phase_id" id="edit_phase_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Project Phase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Phase Name</label>
                        <input type="text" id="edit_phase_name" class="form-control" readonly>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="edit_start_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="edit_end_date" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Planned Hours</label>
                        <input type="number" name="planned_hours" id="edit_planned_hours" class="form-control" min="0" step="0.01" placeholder="e.g., 40.00">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="not_started">Not Started</option>
                            <option value="in_progress">In Progress</option>
                            <option value="on_hold">On Hold</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="update_phase" class="btn btn-primary">Update Phase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Asset Modal -->
<div class="modal fade" id="addAssetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/handle_asset.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="project_id" value="<?php echo $projectId; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add Project Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Asset Name *</label>
                        <input type="text" name="asset_name" class="form-control" required placeholder="e.g., Wireframes, Project Folder">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Asset Type *</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="asset_type" id="type_link" value="link" checked autocomplete="off">
                            <label class="btn btn-outline-primary" for="type_link"><i class="fas fa-link"></i> External Link</label>

                            <input type="radio" class="btn-check" name="asset_type" id="type_file" value="file" autocomplete="off">
                            <label class="btn btn-outline-primary" for="type_file"><i class="fas fa-file-upload"></i> Upload File</label>
                            
                            <input type="radio" class="btn-check" name="asset_type" id="type_text" value="text" autocomplete="off">
                            <label class="btn btn-outline-primary" for="type_text"><i class="fas fa-edit"></i> Text/Blog</label>
                        </div>
                    </div>

                    <!-- Link Fields -->
                    <div id="link_fields">
                        <div class="mb-3">
                            <label class="form-label">Link Type</label>
                            <input type="text" name="link_type" class="form-control" placeholder="e.g., Project Folder, Screenshot Link, etc.">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL *</label>
                            <input type="url" name="main_url" id="main_url" class="form-control" placeholder="https://...">
                        </div>
                    </div>

                    <!-- File Fields -->
                    <div id="file_fields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Select File *</label>
                            <input type="file" name="asset_file" id="asset_file" class="form-control">
                            <small class="text-muted">Allowed types: PDF, DOCX, ZIP, JPG, PNG etc.</small>
                        </div>
                    </div>
                    
                    <!-- Text/Blog Fields -->
                    <div id="text_fields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <input type="text" name="text_category" class="form-control" placeholder="e.g., Blog Post, Documentation, Notes">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Content *</label>
                            <textarea id="text_content_editor" name="text_content"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_asset" class="btn btn-primary">Add Asset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Text Content Modal -->
<div class="modal fade" id="viewTextModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTextModalTitle">Text Content</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="viewTextModalContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

            <!-- Import URLs CSV Modal -->
            <div class="modal fade" id="importUrlsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Import URLs CSV/Excel</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">CSV/Excel File</label>
                                <input type="file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" id="importCsvFile" class="form-control">
                                <small class="form-text text-muted">Supports CSV, XLSX, and XLS formats</small>
                            </div>
                            <div class="mb-3" id="sheetSelectorDiv" style="display:none;">
                                <label class="form-label">Select Sheet</label>
                                <select id="sheetSelector" class="form-select">
                                </select>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">After selecting a CSV we'll preview the first rows and let you choose which columns map to Unique Page and All URLs.</small>
                            </div>
                            <div id="csvPreviewArea" style="display:none;">
                                    <div class="mb-3">
                                    <label class="form-label fw-bold">Column Mapping</label>
                                    <p class="text-muted small mb-2">Map CSV columns to page fields. Select "-- None --" if a field is not in your CSV.</p>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Page No.</label>
                                            <select id="mapPageNumberCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Page Name</label>
                                            <select id="mapPageNameCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Unique URL <span class="text-danger">*</span></label>
                                            <select id="mapUniqueUrlCol" class="form-select form-select-sm"></select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Screen Name</label>
                                            <select id="mapScreenNameCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Notes</label>
                                            <select id="mapNotesCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Grouped URLs</label>
                                            <select id="mapGroupedUrlsCol" class="form-select form-select-sm">
                                                <option value="">-- None --</option>
                                            </select>
                                            <small class="text-muted">Additional URLs for this page</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive mt-3" style="max-height:300px; overflow:auto;">
                                    <table class="table table-sm table-bordered" id="csvPreviewTable">
                                        <thead></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" id="uploadCsvBtn">Upload</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Import All URLs CSV Modal -->
            <div class="modal fade" id="importAllUrlsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Import All URLs CSV/Excel</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">CSV/Excel File</label>
                                <input type="file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" id="importAllCsvFile" class="form-control">
                                <small class="form-text text-muted">Supports CSV, XLSX, and XLS formats</small>
                            </div>
                            <div class="mb-3" id="sheetSelectorAllDiv" style="display:none;">
                                <label class="form-label">Select Sheet</label>
                                <select id="sheetSelectorAll" class="form-select">
                                </select>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Preview first rows and choose the column that contains URL(s). Multiple URLs in a cell can be separated by ; or |.</small>
                            </div>
                            <div id="csvAllPreviewArea" style="display:none;">
                                <div class="mb-2">
                                    <label class="form-label">Column mapping</label>
                                    <div class="row g-2">
                                        <div class="col-auto">
                                            <select id="mapAllOnlyCol" class="form-select form-select-sm" multiple size="3"></select>
                                        </div>
                                        <div class="col-auto align-self-center">→ All URLs (multiple URLs allowed)</div>
                                    </div>
                                </div>
                                <div class="table-responsive" style="max-height:300px; overflow:auto;">
                                    <table class="table table-sm table-bordered" id="csvAllPreviewTable">
                                        <thead></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" id="uploadAllCsvBtn">Upload All URLs</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Confirm Modal -->
            <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmModalTitle">Confirm</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="confirmModalBody">Are you sure?</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmModalConfirm">Yes, proceed</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assign Unique to Page Modal -->
            <div class="modal fade" id="assignUniqueModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Assign Unique Page to Project Page</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p id="assignUniqueTitle" class="fw-bold"></p>
                            <div class="mb-3">
                                <label class="form-label">Assign to page</label>
                                <select id="assignPageSelect" class="form-select">
                                    <option value="">-- Select page (or leave blank to unassign) --</option>
                                    <?php foreach ($projectPages as $pp): ?>
                                        <option value="<?php echo (int)$pp['id']; ?>"><?php echo htmlspecialchars($pp['page_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="assignUniqueConfirm">Assign</button>
                        </div>
                    </div>
                </div>
            </div>

<!-- Edit Regression Task Modal -->
<div class="modal fade" id="regEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="regEditForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Regression Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="reg_task_id">
                    <input type="hidden" id="reg_page_id_for_modal" name="page_id">
                    <input type="hidden" id="reg_env_id_for_modal" name="environment_id">
                    <div class="mb-2">
                        <label class="form-label">Title</label>
                        <input name="title" id="reg_title" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="reg_description" class="form-control"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Assigned User</label>
                            <select name="assigned_user_id" id="reg_assigned_user_id" class="form-select">
                                <option value="">(unassigned)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phase</label>
                            <input name="phase" id="reg_phase" class="form-control">
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Status</label>
                        <select name="status" id="reg_status" class="form-select">
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Unique Modal -->
<div class="modal fade" id="addUniqueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Unique Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Name (optional)</label>
                    <input id="newUniqueName" class="form-control" placeholder="Leave empty to auto-generate (Page 11, Page 12, etc.)" />
                    <small class="form-text text-muted">If left empty, will automatically generate the next page number (e.g., Page 11)</small>
                </div>
                <div class="mb-2">
                    <label class="form-label">Canonical URL (optional)</label>
                    <input id="newUniqueCanonical" class="form-control" placeholder="https://example.com/page" />
                </div>
                <div class="mb-2">
                    <label class="form-label">Page No. (optional)</label>
                    <select id="newUniquePageNumber" class="form-select">
                        <option value="">Auto-generate (Page N)</option>
                        <option value="GLOBAL">Global</option>
                    </select>
                    <small class="form-text text-muted">Choose "Global" to mark this unique as a global page number.</small>
                </div>
                <div id="addUniqueError" class="text-danger" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="createUniqueBtn">Create</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
    (function(){
        if (!window.ProjectConfig) return;
        var projectId = window.ProjectConfig.projectId || 0;
        var baseDir = window.ProjectConfig.baseDir || '';
        var btn = document.getElementById('createUniqueBtn');
        if (!btn) return;
            btn.addEventListener('click', function(){
            var name = (document.getElementById('newUniqueName') || {value:''}).value || '';
            var canonical = (document.getElementById('newUniqueCanonical') || {value:''}).value || '';
            var pageNumber = (document.getElementById('newUniquePageNumber') || {value:''}).value || '';
            var errEl = document.getElementById('addUniqueError');
            if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
            btn.disabled = true;
            
            var _createUrl = baseDir + '/api/project_pages.php?action=create_unique';
            var _payload = { project_id: projectId, name: name, canonical_url: canonical, page_number: pageNumber };
            
            fetch(_createUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(_payload),
                credentials: 'same-origin'
            }).then(function(r){ return r.json(); }).then(function(j){
                btn.disabled = false;
                if (j && j.success) {
                    // Insert new unique row into the Unique Pages table without reloading
                    try {
                        // local escapeHtml fallback
                        function _escapeHtml(inp) {
                            if (typeof window.escapeHtml === 'function') return window.escapeHtml(inp);
                            return String(inp || '').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; });
                        }
                        var uid = Number(j.id || j.created_page_id || 0);
                        var pageLabel = String(j.page_number_label || '').trim();
                        if (!pageLabel) {
                            // If server didn't return a label but user requested GLOBAL, derive next Global N from table
                            if (pageNumber && String(pageNumber || '').toUpperCase() === 'GLOBAL') {
                                var maxG = 0;
                                try {
                                    var existingCells = document.querySelectorAll('#uniquePagesTable tbody tr td:nth-child(2)');
                                    existingCells.forEach(function(td){
                                        var t = (td.textContent || '').trim();
                                        var m = t.match(/^Global\s+(\d+)/i);
                                        if (m && m[1]) {
                                            var n = Number(m[1]); if (!isNaN(n) && n > maxG) maxG = n;
                                        }
                                    });
                                } catch (e) { maxG = 0; }
                                pageLabel = 'Global ' + (maxG + 1);
                            } else {
                                // Use provided non-GLOBAL pageNumber or fallback to name
                                if (pageNumber && String(pageNumber || '').toUpperCase() !== 'GLOBAL') pageLabel = String(pageNumber);
                                else pageLabel = '';
                            }
                        }
                        // If name matches "Page N" or "Global N" pattern, use it as pageLabel
                        if (!pageLabel && name && /^(Page|Global)\s+\d+/i.test(name)) {
                            pageLabel = name;
                        }
                        var displayName = name || canonical || pageLabel || '';
                        var tbody = document.querySelector('#uniquePagesTable tbody');
                        if (tbody) {
                            // remove 'No unique pages' placeholder row if present
                            var noRow = tbody.querySelector('tr td[colspan]');
                            if (noRow) {
                                var tr = noRow.closest('tr'); if (tr) tr.parentNode.removeChild(tr);
                            }

                            var tr = document.createElement('tr');
                            tr.id = 'unique-row-' + uid;
                            tr.innerHTML = ''+
                                '<td><input type="checkbox" class="unique-check" value="' + uid + '"></td>'+
                                '<td>' + _escapeHtml(pageLabel) + '</td>'+
                                '<td>' +
                                    '<div class="d-flex align-items-center justify-content-between gap-2">'+
                                        '<span class="page-name-display flex-grow-1 text-truncate">' + _escapeHtml(displayName) + '</span>'+
                                        '<button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="page_name" data-unique-id="' + uid + '" data-page-id="' + uid + '" data-current-name="' + _escapeHtml(displayName) + '" onclick="return window.handleEditPageName(this);">Edit</button>'+
                                    '</div>'+
                                '</td>'+
                                '<td>'+
                                    '<div class="d-flex align-items-center justify-content-between gap-2">'+
                                        '<span class="unique-url-display flex-grow-1 text-truncate">' + _escapeHtml(canonical || name || '') + '</span>'+
                                        '<button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="canonical_url" data-unique-id="' + uid + '" data-page-id="' + uid + '" data-current-name="' + _escapeHtml(canonical || name || '') + '" onclick="return window.handleEditPageName(this);">Edit</button>'+
                                    '</div>'+
                                '</td>'+
                                '<td><div class="unique-grouped-list" data-unique-id="' + uid + '"><span class="text-muted">No grouped URLs</span></div></td>'+
                                '<td><span class="text-muted">No FT assignments</span></td>'+
                                '<td><span class="text-muted">No AT assignments</span></td>'+
                                '<td><span class="text-muted">No QA assignments</span></td>'+
                                '<td><span class="badge bg-secondary">Not started</span></td>'+
                                '<td><div class="d-flex align-items-center justify-content-between gap-2">'+
                                    '<span class="notes-display flex-grow-1 text-truncate"></span>'+
                                    '<div class="d-flex align-items-center gap-2 flex-shrink-0">'+
                                        '<button type="button" class="btn btn-sm btn-link edit-page-name" data-field="notes" data-unique-id="' + uid + '" data-page-id="' + uid + '" data-current-name="" onclick="return window.handleEditPageName(this);">Edit</button>'+
                                        '<button type="button" class="btn btn-sm btn-link text-danger d-none" data-unique-id="' + uid + '" data-page-id="' + uid + '" onclick="return window.handleDeletePageNotes(this);">Delete</button>'+
                                    '</div>'+
                                '</div></td>'+
                                '<td>'+
                                    '<span class="text-muted small">No mapped page</span> '+
                                    '<button class="btn btn-sm btn-danger delete-unique ms-2" data-id="' + uid + '">Delete</button>'+
                                '</td>';
                            tbody.appendChild(tr);

                            // re-bind any handlers if necessary (delete handler relies on event delegation elsewhere)
                        }
                    } catch (ee) {
                        // fallback to reload if insertion fails
                        location.reload();
                        return;
                    }

                    // reset modal and inputs
                    try {
                        var modalEl = document.getElementById('addUniqueModal');
                        if (modalEl && window.bootstrap) bootstrap.Modal.getInstance(modalEl).hide();
                    } catch (e) {}
                    document.getElementById('newUniqueName').value = '';
                    document.getElementById('newUniqueCanonical').value = '';
                    document.getElementById('newUniquePageNumber').value = '';
                } else {
                    if (errEl) errEl.style.display = 'block';
                    if (errEl) errEl.textContent = (j && j.error) ? j.error : 'Failed to create unique page';
                }
            }).catch(function(e){
                btn.disabled = false;
                if (errEl) { errEl.style.display = 'block'; errEl.textContent = 'Request failed'; }
            });
        });
    })();
</script>

<!-- Edit Page Name / Notes Modal -->
<div class="modal fade" id="editPageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPage_unique_id" value="0">
                <input type="hidden" id="editPage_page_id" value="0">
                <div class="mb-3">
                    <label class="form-label">Field</label>
                    <select id="editPage_field" class="form-select form-select-sm">
                        <option value="page_name">Page Name</option>
                        <option value="page_number">Page Number</option>
                        <option value="canonical_url">Unique URL</option>
                        <option value="notes">Notes</option>
                    </select>
                </div>
                <div class="mb-3" id="editPage_input_wrap">
                    <label class="form-label" id="editPage_label">Page Name</label>
                    <input type="text" id="editPage_value" class="form-control">
                </div>
                <div class="mb-3 d-none" id="editPage_text_wrap">
                    <label class="form-label">Notes</label>
                    <textarea id="editPage_text" class="form-control" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="editPageSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>
