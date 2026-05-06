<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
// Allow if admin, admin OR has explicit permission
if (!$auth->checkRole(['admin']) && empty($_SESSION['can_manage_issue_config'])) {
    $auth->requireRole(['admin']); // Fallback to standard redirect
}

$baseDir = getBaseDir();
$db = Database::getInstance();

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Issue Configuration</h4>
            <div class="text-muted small">Manage presets, metadata fields, and default templates for different project types.</div>
        </div>
        <div>
             <a href="<?php echo $baseDir; ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Dashboard
             </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <label class="fw-bold me-2">Configuring for:</label>
                </div>
                <div class="col-auto">
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check project-type-toggle" name="project_type" id="type_web" value="web" checked>
                        <label class="btn btn-outline-primary" for="type_web"><i class="fas fa-globe me-1"></i> Web</label>

                        <input type="radio" class="btn-check project-type-toggle" name="project_type" id="type_app" value="app">
                        <label class="btn btn-outline-primary" for="type_app"><i class="fas fa-mobile-alt me-1"></i> App</label>

                        <input type="radio" class="btn-check project-type-toggle" name="project_type" id="type_pdf" value="pdf">
                        <label class="btn btn-outline-primary" for="type_pdf"><i class="fas fa-file-pdf me-1"></i> PDF</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs" id="configTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="presets-tab" data-bs-toggle="tab" data-bs-target="#presets" type="button">Issue Presets</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="metadata-tab" data-bs-toggle="tab" data-bs-target="#metadata" type="button">Metadata Fields</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="defaults-tab" data-bs-toggle="tab" data-bs-target="#defaults" type="button">Default Template</button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-4 bg-white mb-5 rounded-bottom shadow-sm">
        
        <!-- Presets Tab -->
        <div class="tab-pane fade show active" id="presets" role="tabpanel">
            <div class="row">
                <div class="col-md-4 border-end">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Saved Presets</h6>
                        <button class="btn btn-sm btn-success" id="btnNewPreset"><i class="fas fa-plus"></i> New</button>
                    </div>
                    
                    <div class="input-group input-group-sm mb-3">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="searchPresets" placeholder="Search presets...">
                    </div>

                    <div class="list-group" id="presetList" style="max-height: 500px; overflow-y: auto;">
                        <!-- Presets loaded via JS -->
                        <div class="text-center p-3 text-muted">Loading...</div>
                    </div>

                    <div class="mt-3 pt-3 border-top">
                        <h6 class="small fw-bold">Import from CSV</h6>
                        <input type="file" id="csvFile" class="form-control form-control-sm mb-2" accept=".csv">
                        <button class="btn btn-sm btn-outline-primary w-100" id="btnImportCsv">Import CSV</button>
                        <div class="form-text small">
                            Required headers: <code>Title</code>. Optional: <code>Description</code> (HTML), metadata columns (e.g. <code>Severity</code>, <code>Priority</code>).
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8 ps-md-4">
                    <form id="presetForm" style="display:none;">
                        <input type="hidden" id="presetId">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0" id="formTitle">New Preset</h5>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnDeletePreset" style="display:none;"><i class="fas fa-trash"></i> Delete</button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label required">Preset Title</label>
                            <input type="text" class="form-control" id="presetTitle" required placeholder="e.g. Image missing alt text">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Default Metadata</label>
                            <div class="row g-2" id="presetMetadataContainer">
                                <!-- Dynamic Metadata Fields loaded via JS -->
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Issue Description Template</label>
                            <textarea id="presetDescription" class="summernote"></textarea>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Preset</button>
                        </div>
                    </form>
                    
                    <div id="noPresetSelected" class="text-center py-5 text-muted">
                        <i class="fas fa-hand-pointer fa-3x mb-3 opacity-25"></i>
                        <p>Select a preset from the list or create a new one.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metadata Tab -->
        <div class="tab-pane fade" id="metadata" role="tabpanel">
            <div class="alert alert-info py-2 small">
                <i class="fas fa-info-circle"></i> Define dynamic dropdown fields for your issues. System fields (Severity, Priority, Status) can be modified but not deleted completely using this UI (use rigorous DB admin if really needed).
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">Sort</th>
                            <th style="width: 200px;">Field Label</th>
                            <th style="width: 200px;">Key (Database)</th>
                            <th>Options (Comma separated)</th>
                            <th style="width: 100px;">Active</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="metadataList">
                        <!-- Metadata rows loaded via JS -->
                    </tbody>
                </table>
            </div>

            <button class="btn btn-outline-primary btn-sm mt-2" id="btnAddField">
                <i class="fas fa-plus"></i> Add New Field
            </button>
        </div>

        <!-- Default Template Tab -->
        <div class="tab-pane fade" id="defaults" role="tabpanel">
             <div class="row justify-content-center">
                 <div class="col-md-8">
                     <p class="text-muted">
                         Configure the default sections that appear in the "Create Issue" modal when <b>no preset</b> is selected.
                     </p>
                     
                     <div class="card bg-light mb-3">
                         <div class="card-body">
                             <label class="form-label fw-bold">Default Sections</label>
                             <div id="defaultSectionsList" class="mb-2">
                                 <!-- Pill tags for sections -->
                             </div>
                             
                             <div class="input-group">
                                 <input type="text" class="form-control" id="newSectionInput" placeholder="Add section (e.g. Actual Result)">
                                 <button class="btn btn-secondary" type="button" id="btnAddSection">Add</button>
                             </div>
                             <small class="text-muted">These sections will be generated as empty blocks in the editor (e.g. <code>[Actual Result]</code>)</small>
                         </div>
                     </div>

                     <div class="d-grid">
                         <button class="btn btn-primary" id="btnSaveDefaults"><i class="fas fa-save"></i> Save Configuration</button>
                     </div>
                 </div>
             </div>
        </div>
    </div>
</div>

<!-- Metadata Edit Modal -->
<div class="modal fade" id="metadataModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Metadata Field</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="metaId">
                <div class="mb-3">
                    <label class="form-label">Field Label</label>
                    <input type="text" class="form-control" id="metaLabel" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Field Key</label>
                    <input type="text" class="form-control" id="metaKey" required placeholder="system_variable_name">
                    <div class="form-text">Unique key for database (letters, numbers, underscores only).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Options</label>
                    <textarea class="form-control" id="metaOptions" rows="5" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                    <div class="form-text">One option per line. This allows values to contain commas.</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="metaActive" checked>
                    <label class="form-check-label" for="metaActive">Field Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveMeta">Save Field</button>
            </div>
        </div>
    </div>
</div>

<!-- CSV Mapping Modal -->
<div class="modal fade" id="csvMappingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Map CSV Columns</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">Map your CSV columns to the appropriate preset fields. "Preset Title" is required.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Preset Field</th>
                                <th>CSV Column</th>
                            </tr>
                        </thead>
                        <tbody id="mappingTableBody">
                            <!-- Mapping rows generated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnConfirmImport">Start Import</button>
            </div>
        </div>
    </div>
</div>

<!-- Summernote & Scripts -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>window._issueConfigData = { baseDir: '<?php echo $baseDir; ?>' };</script>
<script src="<?php echo $baseDir; ?>/assets/js/admin-issue-config.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; 