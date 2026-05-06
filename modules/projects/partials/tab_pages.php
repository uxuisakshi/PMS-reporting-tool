        <?php $canManageAssignmentsInView = in_array($userRole, ['admin', 'project_lead', 'qa'], true); ?>
        <!-- Pages Tab -->
        <div class="tab-pane fade" id="pages" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Project Pages</h5>
                <?php if ($canManageAssignmentsInView): ?>
                <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>&tab=pages" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-edit"></i> Manage Assignments
                </a>
                <?php endif; ?>
            </div>

            <!-- Pages sub-tabs: Unique Pages, All URLs -->
            <ul class="nav nav-tabs mb-3" id="pagesSubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="project-sub-tab" data-bs-toggle="tab" data-bs-target="#project_pages_sub" type="button">Project Pages</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="allurls-sub-tab" data-bs-toggle="tab" data-bs-target="#all_urls_sub" type="button">All URLs</button>
                </li>
            </ul>

            <div class="tab-content">
            <div class="tab-pane fade" id="pages_main" role="tabpanel" style="display:none;">

            <?php 
            // Get project pages with environment details (show all pages here; dashboards control visibility)
            // Order: Global pages first (Global 1, Global 2, ...), then Page pages (Page 1, Page 2, ..., Page 10, ...)
            $pages = $db->prepare("
                SELECT pp.* 
                FROM project_pages pp 
                WHERE pp.project_id = ? 
                ORDER BY 
                    CASE 
                        WHEN pp.page_number LIKE 'Global%' THEN 0
                        WHEN pp.page_number LIKE 'Page%' THEN 1
                        ELSE 2
                    END,
                    CAST(
                        SUBSTRING_INDEX(
                            SUBSTRING_INDEX(pp.page_number, ' ', -1),
                            ' ', 1
                        ) AS UNSIGNED
                    ),
                    pp.page_number,
                    pp.id ASC
            ");
            $pages->execute([$projectId]);
            
            if ($pages->rowCount() > 0):
                while ($page = $pages->fetch()): 
                    // Get environments for this page
                    $environments = $db->prepare("
                        SELECT pe.*, te.name as env_name, te.type as env_type, te.browser, te.assistive_tech,
                               at_user.full_name as at_tester_name,
                               ft_user.full_name as ft_tester_name,
                               qa_user.full_name as qa_name
                        FROM page_environments pe
                        JOIN testing_environments te ON pe.environment_id = te.id
                        LEFT JOIN users at_user ON pe.at_tester_id = at_user.id
                        LEFT JOIN users ft_user ON pe.ft_tester_id = ft_user.id
                        LEFT JOIN users qa_user ON pe.qa_id = qa_user.id
                        WHERE pe.page_id = ?
                        ORDER BY te.name
                    ");
                    $environments->execute([$page['id']]);
            ?>
            <div class="card mb-3">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center">
                                <button class="btn btn-sm btn-outline-secondary me-2 page-toggle-btn" 
                                        type="button" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#page-details-<?php echo $page['id']; ?>" 
                                        aria-expanded="false" 
                                        aria-controls="page-details-<?php echo $page['id']; ?>"
                                        title="Expand/Collapse Details">
                                    <i class="fas fa-chevron-down toggle-icon"></i>
                                </button>
                                <div>
                                    <h6 class="mb-0">
                                        <strong><?php echo htmlspecialchars($page['page_name']); ?></strong>
                                        <?php if ($page['url'] || $page['screen_name'] || $page['page_number']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($page['url'] ?: $page['screen_name'] ?: $page['page_number']); ?></small>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="d-flex gap-1 justify-content-end align-items-center">
                                <!-- Page Status Dropdown -->
                                <div class="me-2">
                                    <?php echo renderPageStatusDropdown($page['id'], $page['status']); ?>
                                </div>
                                
                                <!-- Summary badges when collapsed -->
                                <div class="page-summary me-2">
                                    <?php 
                                    // Get environment summary
                                    $envSummary = $db->prepare("
                                        SELECT 
                                            COUNT(*) as total_envs,
                                            SUM(CASE WHEN pe.status = 'completed' THEN 1 ELSE 0 END) as completed_envs,
                                            SUM(CASE WHEN pe.qa_status = 'completed' THEN 1 ELSE 0 END) as qa_completed_envs
                                        FROM page_environments pe
                                        WHERE pe.page_id = ?
                                    ");
                                    $envSummary->execute([$page['id']]);
                                    $summary = $envSummary->fetch();
                                    
                                    $totalEnvs = $summary['total_envs'] ?: 0;
                                    $completedEnvs = $summary['completed_envs'] ?: 0;
                                    $qaCompletedEnvs = $summary['qa_completed_envs'] ?: 0;
                                    ?>
                                    <small class="text-muted">
                                        <span class="badge bg-secondary"><?php echo $totalEnvs; ?> Envs</span>
                                        <?php if ($totalEnvs > 0): ?>
                                        <span class="badge bg-<?php echo $completedEnvs == $totalEnvs ? 'success' : ($completedEnvs > 0 ? 'warning' : 'secondary'); ?>">
                                            <?php echo $completedEnvs; ?>/<?php echo $totalEnvs; ?> Testing
                                        </span>
                                        <span class="badge bg-<?php echo $qaCompletedEnvs == $totalEnvs ? 'success' : ($qaCompletedEnvs > 0 ? 'info' : 'secondary'); ?>">
                                            <?php echo $qaCompletedEnvs; ?>/<?php echo $totalEnvs; ?> QA
                                        </span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <a href="<?php echo $baseDir; ?>/modules/chat/project_chat.php?page_id=<?php echo $page['id']; ?>" 
                                   class="btn btn-sm btn-outline-primary" title="Page Chat">
                                    <i class="fas fa-comments"></i>
                                </a>
                                <?php if (!in_array($userRole, ['at_tester', 'ft_tester'])): ?>
                                <a href="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>&tab=pages&open_page_id=<?php echo $page['id']; ?>&return_to=<?php echo urlencode($baseDir . '/modules/projects/view.php?id=' . $projectId); ?>" 
                                   class="btn btn-sm btn-outline-secondary" title="Manage Assignments" data-page-edit-id="<?php echo $page['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="collapse" id="page-details-<?php echo $page['id']; ?>">
                    <div class="card-body p-0">
                        <?php if ($environments->rowCount() > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Environment</th>
                                        <th style="width: 20%;">AT Tester</th>
                                        <th style="width: 15%;">AT Status</th>
                                        <th style="width: 20%;">FT Tester</th>
                                        <th style="width: 15%;">FT Status</th>
                                        <th style="width: 15%;">QA</th>
                                        <th style="width: 15%;">QA Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($env = $environments->fetch()): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <strong class="small"><?php echo htmlspecialchars($env['env_name']); ?></strong>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($env['browser']); ?>
                                                <?php if ($env['assistive_tech']): ?>
                                                / <?php echo htmlspecialchars($env['assistive_tech']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($env['at_tester_name']): ?>
                                        <span class="badge bg-primary small">
                                            <i class="fas fa-user-check"></i> <?php echo htmlspecialchars(explode(' ', $env['at_tester_name'])[0]); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($userRole, ['admin', 'project_lead', 'qa']) || 
                                                  $env['at_tester_id'] == $userId): ?>
                                        <select class="form-select form-select-sm env-status-update" 
                                                data-page-id="<?php echo $page['id']; ?>" 
                                                data-env-id="<?php echo $env['environment_id']; ?>"
                                                data-status-type="testing"
                                                style="font-size: 0.75rem;">
                                            <option value="not_started" <?php echo $env['status'] === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo $env['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $env['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="on_hold" <?php echo $env['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="needs_review" <?php echo $env['status'] === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?php 
                                            echo $env['status'] === 'completed' ? 'success' : 
                                                 ($env['status'] === 'in_progress' ? 'primary' : 
                                                  ($env['status'] === 'on_hold' ? 'warning' : 
                                                   ($env['status'] === 'needs_review' ? 'info' : 'secondary')));
                                        ?> small">
                                            <?php echo htmlspecialchars(formatTestStatusLabel($env['status'] ?? 'not_started')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($env['ft_tester_name']): ?>
                                        <span class="badge bg-success small">
                                            <i class="fas fa-user-cog"></i> <?php echo htmlspecialchars(explode(' ', $env['ft_tester_name'])[0]); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($userRole, ['admin', 'project_lead', 'qa']) || 
                                                  $env['ft_tester_id'] == $userId): ?>
                                        <select class="form-select form-select-sm env-status-update" 
                                                data-page-id="<?php echo $page['id']; ?>" 
                                                data-env-id="<?php echo $env['environment_id']; ?>"
                                                data-status-type="testing"
                                                style="font-size: 0.75rem;">
                                            <option value="not_started" <?php echo $env['status'] === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo $env['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $env['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="on_hold" <?php echo $env['status'] === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="needs_review" <?php echo $env['status'] === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?php 
                                            echo $env['status'] === 'completed' ? 'success' : 
                                                 ($env['status'] === 'in_progress' ? 'primary' : 
                                                  ($env['status'] === 'on_hold' ? 'warning' : 
                                                   ($env['status'] === 'needs_review' ? 'info' : 'secondary')));
                                        ?> small">
                                            <?php echo htmlspecialchars(formatTestStatusLabel($env['status'] ?? 'not_started')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($env['qa_name']): ?>
                                        <span class="badge bg-info small">
                                            <i class="fas fa-user-shield"></i> <?php echo htmlspecialchars(explode(' ', $env['qa_name'])[0]); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($userRole, ['admin', 'project_lead', 'qa']) || 
                                                  $env['qa_id'] == $userId): ?>
                                        <?php
                                            $qaStatusRaw = strtolower(trim((string)($env['qa_status'] ?? 'not_started')));
                                            $qaStatusMap = [
                                                'pending' => 'not_started',
                                                'na' => 'on_hold',
                                                'pass' => 'completed',
                                                'fail' => 'needs_review'
                                            ];
                                            $qaStatus = $qaStatusMap[$qaStatusRaw] ?? $qaStatusRaw;
                                            if (!in_array($qaStatus, ['not_started', 'in_progress', 'completed', 'on_hold', 'needs_review'], true)) {
                                                $qaStatus = 'not_started';
                                            }
                                        ?>
                                        <select class="form-select form-select-sm env-status-update" 
                                                data-page-id="<?php echo $page['id']; ?>" 
                                                data-env-id="<?php echo $env['environment_id']; ?>"
                                                data-status-type="qa"
                                                style="font-size: 0.75rem;">
                                            <option value="not_started" <?php echo $qaStatus === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="in_progress" <?php echo $qaStatus === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $qaStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="on_hold" <?php echo $qaStatus === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                                            <option value="needs_review" <?php echo $qaStatus === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?php 
                                            echo $env['qa_status'] === 'completed' ? 'success' : 
                                                 ($env['qa_status'] === 'in_progress' ? 'primary' : 
                                                  ($env['qa_status'] === 'on_hold' ? 'warning' : 
                                                   ($env['qa_status'] === 'needs_review' ? 'info' : 'secondary')));
                                        ?> small">
                                            <?php echo htmlspecialchars(formatQAStatusLabel($env['qa_status'] ?? 'not_started')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-3 text-center text-muted">
                        <i class="fas fa-info-circle"></i> No environments assigned to this page.
                        <br><small>Use "Manage Assignments" to assign environments and testers.</small>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php 
                endwhile;
            else:
            ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No pages found for this project.
            </div>
            <?php endif; ?>
            </div> <!-- end #pages_main -->

            <!-- Unique Pages sub-pane -->
            <div class="tab-pane fade show active" id="project_pages_sub" role="tabpanel" aria-labelledby="project-sub-tab">
                <!-- Header with title and action buttons -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Unique Pages (URLs)</h5>
                    <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-success" id="exportPagesBtn" title="Export Project Pages + All URLs to Excel">
                            <i class="fas fa-file-excel me-1"></i> Export Pages
                        </button>
                        <button class="btn btn-sm btn-primary" id="exportClientReportBtn" title="Export full client report (Overview, URL Details, All URLs, Final Report, Conformance Score)">
                            <i class="fas fa-file-excel me-1"></i> Export Client Report
                        </button>
                        <button class="btn btn-sm btn-danger" id="deleteSelectedUnique">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <a href="#" class="btn btn-sm btn-primary" id="importUrlsBtn">
                            <i class="fas fa-upload"></i> Import CSV/Excel
                        </a>
                        <button class="btn btn-sm btn-outline-primary" id="addUniqueBtn">
                            <i class="fas fa-plus"></i> Add Unique
                        </button>
                    </div>
                </div>
                
                <!-- Filters row -->
                <div class="row mb-3">
                    <div class="col-md-2">
                        <label class="form-label small text-muted">Search</label>
                        <input id="uniqueFilter" class="form-control form-control-sm" placeholder="Search name or URL..." />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">User Filter</label>
                        <select id="uniqueFilterUser" class="form-select form-select-sm">
                            <option value="">All Users</option>
                            <?php foreach ($projectUsers as $pu): ?>
                                <option value="<?php echo htmlspecialchars($pu['full_name']); ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">Environment</label>
                        <select id="uniqueFilterEnv" class="form-select form-select-sm">
                            <option value="">All Environments</option>
                            <?php
                                $envListStmt = $db->prepare('SELECT id, name FROM testing_environments ORDER BY name');
                                $envListStmt->execute();
                                $envList = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($envList as $env) {
                                    echo '<option value="' . htmlspecialchars($env['name']) . '">' . htmlspecialchars($env['name']) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">QA Filter</label>
                        <select id="uniqueFilterQa" class="form-select form-select-sm">
                            <option value="">All QA</option>
                            <?php foreach ($projectUsers as $pu): ?>
                                <option value="<?php echo htmlspecialchars($pu['full_name']); ?>"><?php echo htmlspecialchars($pu['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">Page Status</label>
                        <select id="uniqueFilterPageStatus" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Need Assignment">Need Assignment</option>
                            <option value="Tester Not Assigned">Tester Not Assigned</option>
                            <option value="QA Not Assigned">QA Not Assigned</option>
                            <option value="Not Started">Not Started</option>
                            <option value="Testing In Progress">Testing In Progress</option>
                            <option value="QA In Progress">QA In Progress</option>
                            <option value="Needs Review">Needs Review</option>
                            <option value="In Fixing">In Fixing</option>
                            <option value="On Hold">On Hold</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                <?php
                // prepare statements for mapped page and environment aggregation
                // Try to find a mapped project page. Prefer grouped_urls linkage, but also
                // accept project_pages that were created from a Unique (page_number = unique.name)
                // or whose url equals unique.canonical_url. This ensures newly-created project_pages
                // are discovered even if grouped_urls linkage wasn't present.
                $mpStmt = $db->prepare(
                    'SELECT pp.id, pp.page_number, pp.page_name, pp.status, pp.notes
                     FROM project_pages pp
                     WHERE pp.project_id = ? AND (
                         pp.id IN (SELECT DISTINCT pp2.id FROM project_pages pp2 
                                   LEFT JOIN grouped_urls gu ON pp2.project_id = gu.project_id AND (pp2.url = gu.url OR pp2.url = gu.normalized_url)
                                   WHERE pp2.project_id = ? AND gu.unique_page_id = ?)
                         OR pp.page_number = (SELECT page_name FROM project_pages WHERE id = ?)
                         OR pp.url = (SELECT url FROM project_pages WHERE id = ?)
                     )
                     LIMIT 1'
                );
                $envStmt = $db->prepare('SELECT GROUP_CONCAT(DISTINCT at_u.full_name SEPARATOR ", ") as at_testers, GROUP_CONCAT(DISTINCT ft_u.full_name SEPARATOR ", ") as ft_testers, GROUP_CONCAT(DISTINCT qa_u.full_name SEPARATOR ", ") as qa_names, GROUP_CONCAT(DISTINCT te.name SEPARATOR ", ") as env_names FROM page_environments pe JOIN testing_environments te ON pe.environment_id = te.id LEFT JOIN users at_u ON pe.at_tester_id = at_u.id LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id LEFT JOIN users qa_u ON pe.qa_id = qa_u.id WHERE pe.page_id = ?');
                // QA summary per page (used in Unique list)
                $qaSummaryStmt = $db->prepare('SELECT COUNT(*) AS total_envs, SUM(CASE WHEN pe.qa_status = "completed" THEN 1 ELSE 0 END) AS qa_passed FROM page_environments pe WHERE pe.page_id = ?');
                // list detailed env assignments per page
                $envListStmt = $db->prepare('SELECT pe.environment_id, te.name as env_name, pe.status AS env_status, pe.qa_status AS env_qa_status, pe.at_tester_id, at_u.full_name AS at_name, pe.ft_tester_id, ft_u.full_name AS ft_name, pe.qa_id, qa_u.full_name AS qa_name FROM page_environments pe JOIN testing_environments te ON pe.environment_id = te.id LEFT JOIN users at_u ON pe.at_tester_id = at_u.id LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id LEFT JOIN users qa_u ON pe.qa_id = qa_u.id WHERE pe.page_id = ? ORDER BY te.name');
                $envStmt = $db->prepare('SELECT GROUP_CONCAT(DISTINCT at_u.full_name SEPARATOR ", ") as at_testers, GROUP_CONCAT(DISTINCT ft_u.full_name SEPARATOR ", ") as ft_testers, GROUP_CONCAT(DISTINCT qa_u.full_name SEPARATOR ", ") as qa_names, GROUP_CONCAT(DISTINCT te.name SEPARATOR ", ") as env_names FROM page_environments pe JOIN testing_environments te ON pe.environment_id = te.id LEFT JOIN users at_u ON pe.at_tester_id = at_u.id LEFT JOIN users ft_u ON pe.ft_tester_id = ft_u.id LEFT JOIN users qa_u ON pe.qa_id = qa_u.id WHERE pe.page_id = ?');
                // grouped URLs per unique
                $groupForUnique = $db->prepare('SELECT url FROM grouped_urls WHERE project_id = ? AND unique_page_id = ? ORDER BY url');
                ?>
                <div class="table-responsive">
                    <table class="table table-striped table-sm resizable-table" id="uniquePagesTable">
                        <thead>
                            <tr>
                                <th style="width:40px; position: relative;">
                                    <input type="checkbox" id="selectAllUnique">
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:100px; position: relative;">
                                    Page No.
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:200px; position: relative;">
                                    Page Name
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Unique URLs
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:120px; position: relative;">
                                    Grouped URLs
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    FT (Env - Status)
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    AT (Env - Status)
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    QA (Env - Status)
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Page Status
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:200px; position: relative;">
                                    Notes
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Actions
                                    <div class="col-resizer"></div>
                                </th>
                                </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($uniquePages)): foreach ($uniquePages as $u): 
                                $mapped = null; $envs = null; $pageIdForEnv = null;
                                $mpStmt->execute([$projectId, $projectId, $u['id'], $u['id'], $u['id']]);
                                $mapped = $mpStmt->fetch(PDO::FETCH_ASSOC);
                                if (!$mapped) {
                                    $mapped = [
                                        'id' => $u['id'],
                                        'page_number' => $u['page_number'] ?? '',
                                        'page_name' => $u['name'] ?? '',
                                        'status' => $u['status'] ?? 'not_started',
                                        'notes' => $u['notes'] ?? ''
                                    ];
                                }
                                if ($mapped) $pageIdForEnv = $mapped['id'];
                                if ($pageIdForEnv) { $envStmt->execute([$pageIdForEnv]); $envs = $envStmt->fetch(PDO::FETCH_ASSOC); }
                        ?>
                            <tr id="unique-row-<?php echo (int)$u['id']; ?>">
                                <td><input type="checkbox" class="unique-check" value="<?php echo (int)$u['id']; ?>"></td>
                                <?php
                                    // Determine display for Page No and Page Name.
                                    $displayPageNo = resolvePageDisplayValue($mapped ?: $u);
                                    $displayPageName = $mapped['page_name'] ?? ($u['name'] ?? '');
                                    
                                    // If page_number is still empty in results but was resolved from name, 
                                    // adjust display name if needed.
                                    if (empty($u['page_number']) && empty($mapped['page_number'])) {
                                        if (preg_match('/^(Page|Global)\s+\d+/i', $displayPageName)) {
                                            $displayPageName = $u['canonical_url'] ?? '';
                                        }
                                    }
                                ?>
                                <td>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="page-no-display flex-grow-1 text-truncate"><?php echo htmlspecialchars($displayPageNo); ?></span>
                                        <button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="page_number" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)($mapped['id'] ?? 0); ?>" data-current-name="<?php echo htmlspecialchars($displayPageNo); ?>" onclick="return window.handleEditPageName(this);">Edit</button>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="page-name-display flex-grow-1 text-truncate"><?php echo htmlspecialchars($displayPageName); ?></span>
                                        <button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="page_name" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)($mapped['id'] ?? 0); ?>" data-current-name="<?php echo htmlspecialchars($displayPageName); ?>" onclick="return window.handleEditPageName(this);">Edit</button>
                                    </div>
                                </td>
                                <td>
                                    <?php $uniqueDisplay = $u['canonical_url'] ?? $u['name'] ?? ''; ?>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="unique-url-display flex-grow-1 text-truncate"><?php echo htmlspecialchars($uniqueDisplay); ?></span>
                                        <button type="button" class="btn btn-sm btn-link flex-shrink-0 edit-page-name" data-field="canonical_url" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)$u['id']; ?>" data-current-name="<?php echo htmlspecialchars($uniqueDisplay); ?>" onclick="return window.handleEditPageName(this);">Edit</button>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $groupForUnique->execute([$projectId, $u['id']]);
                                        $grows = $groupForUnique->fetchAll(PDO::FETCH_COLUMN);
                                        if (empty($grows)) {
                                            $fallbackUrl = trim((string)($u['canonical_url'] ?? ''));
                                            if ($fallbackUrl !== '') {
                                                $grows = [$fallbackUrl];
                                            }
                                        }
                                        if (!empty($grows)) {
                                            $urlCount = count($grows);
                                            $maxVisible = 3; // Show only first 3 URLs initially
                                            echo '<div class="unique-grouped-list" data-unique-id="' . (int)$u['id'] . '">';
                                            
                                            // Show first few URLs
                                            for ($i = 0; $i < min($maxVisible, $urlCount); $i++) {
                                                echo '<div class="grouped-url-item">' . htmlspecialchars($grows[$i]) . '</div>';
                                            }
                                            
                                            // If more URLs exist, show them in a collapsible section
                                            if ($urlCount > $maxVisible) {
                                                $collapseId = 'collapse-urls-' . (int)$u['id'];
                                                echo '<div class="collapse" id="' . $collapseId . '">';
                                                for ($i = $maxVisible; $i < $urlCount; $i++) {
                                                    echo '<div class="grouped-url-item">' . htmlspecialchars($grows[$i]) . '</div>';
                                                }
                                                echo '</div>';
                                                echo '<button class="btn btn-sm btn-link p-0 mt-1 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '" aria-expanded="false">';
                                                echo '<span class="when-collapsed"><i class="fas fa-chevron-down"></i> Show ' . ($urlCount - $maxVisible) . ' more</span>';
                                                echo '<span class="when-expanded"><i class="fas fa-chevron-up"></i> Show less</span>';
                                                echo '</button>';
                                            }
                                            
                                            echo '</div>';
                                        } else {
                                            echo '<div class="unique-grouped-list" data-unique-id="' . (int)$u['id'] . '"><span class="text-muted">No grouped URLs</span></div>';
                                        }
                                    ?>
                                </td>
                                <?php
                                    // prepare per-environment rows for merged columns
                                    $envRows = [];
                                    if ($pageIdForEnv) {
                                        try {
                                            $envListStmt->execute([$pageIdForEnv]);
                                            $envRows = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
                                        } catch (Exception $e) { $envRows = []; }
                                    }
                                    $pageStatusKey = $mapped['status'] ?? 'not_started';
                                    $assignmentGapStatus = computePageAssignmentGapStatusFromEnvRows($envRows);
                                    if ($assignmentGapStatus !== '') {
                                        $pageStatusKey = $assignmentGapStatus;
                                    } elseif (!empty($envRows)) {
                                        $pageStatusKey = computeAggregatePageStatusFromEnvRows($envRows);
                                    }
                                    $pageStatusLabel = formatPageProgressStatusLabel($pageStatusKey);
                                    $pageStatusBadge = 'secondary';
                                    if ($pageStatusKey === 'completed') $pageStatusBadge = 'success';
                                    elseif ($pageStatusKey === 'in_progress') $pageStatusBadge = 'warning text-dark';
                                    elseif ($pageStatusKey === 'qa_in_progress') $pageStatusBadge = 'info text-dark';
                                    elseif ($pageStatusKey === 'needs_review') $pageStatusBadge = 'primary';
                                    elseif ($pageStatusKey === 'in_fixing') $pageStatusBadge = 'danger';
                                    elseif ($pageStatusKey === 'on_hold') $pageStatusBadge = 'light text-dark border';
                                    elseif ($pageStatusKey === 'need_assignment') $pageStatusBadge = 'dark';
                                    elseif ($pageStatusKey === 'tester_not_assigned' || $pageStatusKey === 'qa_not_assigned') $pageStatusBadge = 'secondary';
                                ?>
                                <td>
                                    <?php
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            // Only show if FT tester is assigned
                                            if ($er['ft_tester_id']) {
                                                $ftName = trim((string)($er['ft_name'] ?? ''));
                                                if ($ftName === '') $ftName = 'User #' . (int)$er['ft_tester_id'];
                                                $envName = trim((string)($er['env_name'] ?? ''));
                                                if ($envName === '') $envName = 'Env #' . (int)$er['environment_id'];
                                                $ft = htmlspecialchars($ftName);
                                                $envLabel = htmlspecialchars($envName);
                                                $statusHtml = renderEnvStatusDropdown($pageIdForEnv, $er['environment_id'], $er['env_status']);
                                                echo '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">';
                                                echo '<div class="flex-grow-1 text-truncate"><strong>' . $ft . '</strong> <small class="text-muted">&middot; ' . $envLabel . '</small></div>';
                                                echo '<div class="flex-shrink-0">' . $statusHtml . '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    // Show message only if no FT assignments at all
                                    $hasFT = false;
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            if ($er['ft_tester_id']) { $hasFT = true; break; }
                                        }
                                    }
                                    if (!$hasFT) {
                                        echo '<span class="text-muted">No FT assignments</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            // Only show if AT tester is assigned
                                            if ($er['at_tester_id']) {
                                                $atName = trim((string)($er['at_name'] ?? ''));
                                                if ($atName === '') $atName = 'User #' . (int)$er['at_tester_id'];
                                                $envName = trim((string)($er['env_name'] ?? ''));
                                                if ($envName === '') $envName = 'Env #' . (int)$er['environment_id'];
                                                $at = htmlspecialchars($atName);
                                                $envLabel = htmlspecialchars($envName);
                                                $statusHtml = renderEnvStatusDropdown($pageIdForEnv, $er['environment_id'], $er['env_status']);
                                                echo '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">';
                                                echo '<div class="flex-grow-1 text-truncate"><strong>' . $at . '</strong> <small class="text-muted">&middot; ' . $envLabel . '</small></div>';
                                                echo '<div class="flex-shrink-0">' . $statusHtml . '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    // Show message only if no AT assignments at all
                                    $hasAT = false;
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            if ($er['at_tester_id']) { $hasAT = true; break; }
                                        }
                                    }
                                    if (!$hasAT) {
                                        echo '<span class="text-muted">No AT assignments</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            // Only show if QA is assigned
                                            if ($er['qa_id']) {
                                                $qaName = trim((string)($er['qa_name'] ?? ''));
                                                if ($qaName === '') $qaName = 'User #' . (int)$er['qa_id'];
                                                $envName = trim((string)($er['env_name'] ?? ''));
                                                if ($envName === '') $envName = 'Env #' . (int)$er['environment_id'];
                                                $qa = htmlspecialchars($qaName);
                                                $envLabel = htmlspecialchars($envName);
                                                $qaStatus = $er['env_qa_status'] ?? 'not_started';
                                                $statusHtml = renderQAEnvStatusDropdown($pageIdForEnv, $er['environment_id'], $qaStatus);
                                                echo '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">';
                                                echo '<div class="flex-grow-1 text-truncate"><strong>' . $qa . '</strong> <small class="text-muted">&middot; ' . $envLabel . '</small></div>';
                                                echo '<div class="flex-shrink-0">' . $statusHtml . '</div>';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    // Show message only if no QA assignments at all
                                    $hasQA = false;
                                    if (!empty($envRows)) {
                                        foreach ($envRows as $er) {
                                            if ($er['qa_id']) { $hasQA = true; break; }
                                        }
                                    }
                                    if (!$hasQA) {
                                        echo '<span class="text-muted">No QA assignments</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo htmlspecialchars($pageStatusBadge); ?>">
                                        <?php echo htmlspecialchars($pageStatusLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $notesDisplay = (isset($mapped['notes']) && strlen(trim((string)$mapped['notes'])) > 0) ? $mapped['notes'] : ($u['notes'] ?? ''); ?>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="notes-display flex-grow-1 text-truncate"><?php echo htmlspecialchars($notesDisplay); ?></span>
                                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                            <button type="button" class="btn btn-sm btn-link edit-page-name" data-field="notes" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)($mapped['id'] ?? 0); ?>" data-current-name="<?php echo htmlspecialchars($notesDisplay); ?>" onclick="return window.handleEditPageName(this);">Edit</button>
                                            <button type="button" class="btn btn-sm btn-link text-danger<?php echo trim((string)$notesDisplay) === '' ? ' d-none' : ''; ?>" data-unique-id="<?php echo (int)$u['id']; ?>" data-page-id="<?php echo (int)($mapped['id'] ?? 0); ?>" onclick="return window.handleDeletePageNotes(this);">Delete</button>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($pageIdForEnv && $canManageAssignmentsInView): ?>
                                        <button class="btn btn-sm btn-outline-primary assign-page-btn me-1" data-bs-toggle="modal" data-bs-target="#assignPageModal-<?php echo $pageIdForEnv; ?>">Assign</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger delete-unique" data-id="<?php echo (int)$u['id']; ?>">Delete</button>
                                </td>
                            </tr>
                            <?php if ($pageIdForEnv && $canManageAssignmentsInView): 
                                // Build env assignment map for modal defaults
                                $envListStmt->execute([$pageIdForEnv]);
                                $envRowsModal = $envListStmt->fetchAll(PDO::FETCH_ASSOC);
                                $envMap = [];
                                foreach ($envRowsModal as $erow) {
                                    $envMap[(int)$erow['environment_id']] = $erow;
                                }
                                $pageInfoStmt = $db->prepare('SELECT at_tester_id, ft_tester_id, qa_id FROM project_pages WHERE id = ?');
                                $pageInfoStmt->execute([$pageIdForEnv]);
                                $pageInfo = $pageInfoStmt->fetch(PDO::FETCH_ASSOC) ?: ['at_tester_id'=>null,'ft_tester_id'=>null,'qa_id'=>null];
                            ?>
                            <div class="modal fade" id="assignPageModal-<?php echo $pageIdForEnv; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form method="POST" action="<?php echo $baseDir; ?>/modules/projects/manage_assignments.php?project_id=<?php echo $projectId; ?>&tab=pages">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="assign_page" value="1">
                                            <input type="hidden" name="page_id" value="<?php echo $pageIdForEnv; ?>">
                                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($baseDir . '/modules/projects/view.php?id=' . $projectId . '#project_pages_sub'); ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Assign testers & environments</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-2">
                                                    <div class="col-md-4">
                                                        <label class="form-label">AT Tester</label>
                                                        <select name="at_tester_id" class="form-select form-select-sm">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                                                <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$pageInfo['at_tester_id'] === (int)$tm['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($tm['full_name']); ?>
                                                                </option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">FT Tester</label>
                                                        <select name="ft_tester_id" class="form-select form-select-sm">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                                                <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$pageInfo['ft_tester_id'] === (int)$tm['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($tm['full_name']); ?>
                                                                </option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">QA</label>
                                                        <select name="qa_id" class="form-select form-select-sm">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                                                <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$pageInfo['qa_id'] === (int)$tm['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($tm['full_name']); ?>
                                                                </option>
                                                            <?php endif; endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <hr class="my-3">
                                                <div class="mb-2 d-flex justify-content-between align-items-center">
                                                    <strong class="mb-0">Environments</strong>
                                                    <small class="text-muted">Check envs and set per-env testers (optional)</small>
                                                </div>
                                                <div class="row">
                                                    <?php foreach ($allEnvironments as $env): 
                                                        $envId = (int)$env['id'];
                                                        $linked = isset($envMap[$envId]);
                                                        $atSel = $linked ? ($envMap[$envId]['at_tester_id'] ?? '') : '';
                                                        $ftSel = $linked ? ($envMap[$envId]['ft_tester_id'] ?? '') : '';
                                                        $qaSel = $linked ? ($envMap[$envId]['qa_id'] ?? '') : '';
                                                    ?>
                                                    <div class="col-md-6 mb-3">
                                                        <div class="border rounded p-2">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="envs[]" value="<?php echo $envId; ?>" id="env_chk_<?php echo $pageIdForEnv . '_' . $envId; ?>" <?php echo $linked ? 'checked' : ''; ?>>
                                                                <label class="form-check-label fw-bold" for="env_chk_<?php echo $pageIdForEnv . '_' . $envId; ?>"><?php echo htmlspecialchars($env['name']); ?></label>
                                                            </div>
                                                            <div class="row g-2 mt-2">
                                                                <div class="col-4">
                                                                    <select name="at_tester_env_<?php echo $envId; ?>" class="form-select form-select-sm">
                                                                        <option value="">AT</option>
                                                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'at_tester'): ?>
                                                                            <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$atSel === (int)$tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                                        <?php endif; endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-4">
                                                                    <select name="ft_tester_env_<?php echo $envId; ?>" class="form-select form-select-sm">
                                                                        <option value="">FT</option>
                                                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'ft_tester'): ?>
                                                                            <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$ftSel === (int)$tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                                        <?php endif; endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-4">
                                                                    <select name="qa_env_<?php echo $envId; ?>" class="form-select form-select-sm">
                                                                        <option value="">QA</option>
                                                                        <?php foreach ($teamMembers as $tm): if ($tm['role'] === 'qa'): ?>
                                                                            <option value="<?php echo (int)$tm['id']; ?>" <?php echo ((int)$qaSel === (int)$tm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tm['full_name']); ?></option>
                                                                        <?php endif; endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($allEnvironments)): ?>
                                                        <div class="col-12 text-muted">No environments configured.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save assignments</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; else: ?>
                            <tr><td colspan="11" class="text-muted">No unique pages defined for this project.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div> <!-- end #project_pages_sub -->

            <!-- All URLs sub-pane -->
            <div class="tab-pane fade" id="all_urls_sub" role="tabpanel" aria-labelledby="allurls-sub-tab">
                <!-- Header with title and action buttons -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">All URLs</h5>
                    <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-primary" id="copySelectedUrls" disabled>
                            <i class="fas fa-copy"></i> Copy Selected URLs (<span id="selectedUrlsCount">0</span>)
                        </button>
                        <button class="btn btn-sm btn-danger" id="deleteSelectedGrouped">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <a href="#" class="btn btn-sm btn-outline-primary" id="importAllUrlsBtn">
                            <i class="fas fa-upload"></i> Import All URLs CSV/Excel
                        </a>
                        <?php if (in_array($userRole, ['admin', 'project_lead', 'qa'])): ?>
                            <button class="btn btn-sm btn-primary" id="openAddGroupedUrlModal">
                                <i class="fas fa-plus"></i> Add Page
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Filters row -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Search URL</label>
                        <input id="allUrlsFilter" class="form-control form-control-sm" placeholder="Search URL..." />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Unique Page Filter</label>
                        <select id="allUrlsUniqueFilter" class="form-select form-select-sm">
                            <option value="">All Unique Pages</option>
                            <?php foreach ($uniquePages as $up): ?>
                                <option value="<?php echo htmlspecialchars($up['name'] ?? $up['canonical_url'] ?? ''); ?>"><?php echo htmlspecialchars($up['name'] ?? $up['canonical_url'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Mapping Status</label>
                        <select id="allUrlsMappingFilter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="mapped">Mapped</option>
                            <option value="unassigned">Unassigned</option>
                        </select>
                    </div>
                </div>
                
                <!-- Pagination for All URLs (Top) -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <span id="allUrlsInfo" class="text-muted small"></span>
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0" id="allUrlsPagination">
                        </ul>
                    </nav>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped table-sm resizable-table" id="allUrlsTable">
                        <thead>
                            <tr>
                                <th style="width:40px; position: relative;">
                                    <input type="checkbox" id="selectAllGrouped">
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:300px; position: relative;">
                                    URL
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:180px; position: relative;">
                                    Unique Page
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:200px; position: relative;">
                                    Mapped
                                    <div class="col-resizer"></div>
                                </th>
                                <th style="width:150px; position: relative;">
                                    Actions
                                    <div class="col-resizer"></div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($groupedUrls)): foreach ($groupedUrls as $g): ?>
                            <tr id="grouped-row-<?php echo (int)$g['grouped_id']; ?>">
                                <td><input type="checkbox" class="grouped-check" value="<?php echo (int)$g['grouped_id']; ?>" data-url="<?php echo htmlspecialchars($g['url']); ?>"></td>
                                <td><?php echo htmlspecialchars($g['url']); ?></td>
                                <td class="dropdown-cell">
                                    <select class="form-select form-select-sm grouped-unique-select" data-grouped-id="<?php echo (int)$g['grouped_id']; ?>" style="min-width:160px;">
                                        <option value="">(Unassigned)</option>
                                        <?php foreach ($uniquePages as $uopt): ?>
                                            <?php $optLabel = htmlspecialchars($uopt['name'] ?? $uopt['canonical_url'] ?? ''); $optCanonical = htmlspecialchars($uopt['canonical_url'] ?? ''); ?>
                                            <option value="<?php echo (int)$uopt['id']; ?>" data-canonical="<?php echo $optCanonical; ?>" <?php echo ((int)($g['unique_page_id'] ?? 0) === (int)$uopt['id']) ? 'selected' : ''; ?>><?php echo $optLabel; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="mapped-col">
                                    <?php if (!empty($g['unique_id']) || !empty($g['mapped_page_name'])): ?>
                                        <?php if (!empty($g['unique_id'])): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($g['unique_name'] ?? $g['mapped_page_name'] ?? ''); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($g['canonical_url'] ?? $g['url'] ?? ''); ?></small>
                                            </div>
                                        <?php else: ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($g['mapped_page_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($g['url'] ?? ''); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div><span class="text-muted">(Unassigned)</span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger delete-grouped" data-id="<?php echo (int)$g['grouped_id']; ?>">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-muted">No URLs uploaded for this project.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination for All URLs (Bottom) -->
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div>
                        <span id="allUrlsInfoBottom" class="text-muted small"></span>
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0" id="allUrlsPaginationBottom">
                        </ul>
                    </nav>
                </div>
            </div>

            <!-- Add Grouped URL Modal (only for All URLs tab) -->
            <div class="modal fade" id="addGroupedUrlModal" tabindex="-1" aria-labelledby="addGroupedUrlModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addGroupedUrlModalLabel">Add URL to All URLs</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addGroupedUrlForm">
                                <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
                                <div class="mb-3">
                                    <label class="form-label">URL *</label>
                                    <input type="text" name="url" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Map to Unique (optional)</label>
                                    <select name="unique_page_id" class="form-select">
                                        <option value="">(Unassigned)</option>
                                        <?php foreach ($uniquePages as $uopt): ?>
                                            <?php $optCanon = htmlspecialchars($uopt['canonical_url'] ?? ''); ?>
                                            <option value="<?php echo (int)$uopt['id']; ?>" data-canonical="<?php echo $optCanon; ?>"><?php echo htmlspecialchars($uopt['name'] ?? $uopt['canonical_url'] ?? ''); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="text-end">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Add URL</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            </div> <!-- end .tab-content for pages sub-tabs -->
        </div>

<script>window._tabPagesConfig = { projectTitle: <?php echo json_encode($project['title'] ?? 'Project'); ?> };</script>
<script src="<?php echo $baseDir; ?>/assets/js/tab-pages.js?v=<?php echo time(); ?>"></script>
