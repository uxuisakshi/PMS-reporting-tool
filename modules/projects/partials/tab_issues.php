        <!-- Issues Tab -->
        <div class="tab-pane fade" id="issues" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <h5 class="mb-0">Issues</h5>
                    <div class="small text-muted">Pages-wise final issues.</div>
                </div>
            </div>

            <ul class="nav nav-tabs mb-3" id="issuesSubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="issues-pages-tab" data-bs-toggle="tab" data-bs-target="#issues_pages" type="button">Pages</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="issues-common-tab" data-bs-toggle="tab" data-bs-target="#issues_common" type="button">Common Issues</button>
                </li>
            </ul>

            <div class="tab-content" id="issuesSubTabContent">
                <div class="tab-pane fade show active" id="issues_pages" role="tabpanel">
                    <div class="row g-3" id="issuesPagesRow">
                        <div class="col-lg-12" id="issuesPagesCol">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold">Pages</span>
                                    <span class="text-muted small"><?php echo count($uniqueIssuePages); ?> total</span>
                                </div>
                                <div class="card-body border-bottom">
                                    <div class="d-flex flex-wrap gap-3">
                                        <div>
                                            <div class="text-muted small">Total Pages</div>
                                            <div class="fw-semibold"><?php echo (int)$issuesPagesCount; ?></div>
                                        </div>
                                        <div>
                                            <div class="text-muted small">Total Issues</div>
                                            <div class="fw-semibold"><?php echo (int)$issuesTotalCount; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive" id="issuesPageList">
                                    <table class="table table-hover table-sm align-middle mb-0 resizable-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40px;">#<div class="col-resizer"></div></th>
                                                <th>Page Name<div class="col-resizer"></div></th>
                                                <th style="width: 100px;">Page No<div class="col-resizer"></div></th>
                                                <th style="width: 100px;">Issues<div class="col-resizer"></div></th>
                                                <th style="width: 150px;">Tester<div class="col-resizer"></div></th>
                                                <th style="width: 120px;">Environment<div class="col-resizer"></div></th>
                                                <th style="width: 120px;">Prod Hours<div class="col-resizer"></div></th>
                                                <th style="width: 120px;">Grouped URLs</th>
                                            </tr>
                                        </thead>
                                        <tbody>
    <?php if (!empty($uniqueIssuePages)): 
        $rowNum = 1;
        foreach ($uniqueIssuePages as $u):
        $mappedPageId = (int)($u['mapped_page_id'] ?? 0);
        $sum = $mappedPageId ? ($issuePageSummaries[$mappedPageId] ?? []) : [];
        $tester = trim($sum['testers'] ?? "");
        $envs = trim($sum['envs'] ?? "");
        $count = isset($sum['issues_count']) ? (int)$sum['issues_count'] : 0;
        $prodHours = isset($sum['production_hours']) ? (float)$sum['production_hours'] : 0;
        $uniqueLabel = $u['canonical_url'] ?: ($u['unique_name'] ?? "");
        $pageNoLabel = $u['mapped_page_number'] ?? "";
        $displayName = $u['mapped_page_name'] ?? "";
        if (!$displayName) { $displayName = $u['unique_name'] ?? $uniqueLabel; }
        $pageUrls = $urlsByUniqueId[$u['unique_id']] ?? [];
        $hasUrls = !empty($pageUrls);
        $urlCount = count($pageUrls);
    ?>
                                            <tr class="issues-page-row" 
                                                data-unique-id="<?php echo (int)$u['unique_id']; ?>"
                                                data-page-id="<?php echo (int)$mappedPageId; ?>"
                                                data-page-name="<?php echo htmlspecialchars($displayName); ?>"
                                                data-page-tester="<?php echo htmlspecialchars($tester ?: '-'); ?>"
                                                data-page-env="<?php echo htmlspecialchars($envs ?: '-'); ?>"
                                                data-page-issues="<?php echo $count; ?>"
                                                style="cursor: pointer;">
                                                <td class="text-muted"><?php echo $rowNum++; ?></td>
                                                <td>
                                                    <div class="fw-semibold text-primary"><?php echo htmlspecialchars($displayName); ?></div>
                                                    <div class="small text-muted text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($uniqueLabel); ?>">
                                                        <?php echo htmlspecialchars($uniqueLabel ?: '-'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary-subtle text-primary">
                                                        <?php echo htmlspecialchars($pageNoLabel ?: '-'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $count > 0 ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary'; ?>">
                                                        <?php echo $count; ?>
                                                    </span>
                                                </td>
                                                <td class="small"><?php echo htmlspecialchars($tester ?: '-'); ?></td>
                                                <td class="small"><?php echo htmlspecialchars($envs ?: '-'); ?></td>
                                                <td class="small"><?php echo number_format($prodHours, 2); ?> hrs</td>
                                                <td>
                                                    <?php if ($hasUrls): ?>
                                                    <button class="btn btn-xs btn-outline-secondary" 
                                                            type="button" 
                                                            data-bs-toggle="collapse" 
                                                            data-bs-target="#urls-<?php echo (int)$u['unique_id']; ?>" 
                                                            aria-expanded="false"
                                                            onclick="event.stopPropagation();">
                                                        <i class="fas fa-link me-1"></i> <?php echo $urlCount; ?>
                                                    </button>
                                                    <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($hasUrls): ?>
                                            <tr class="collapse" id="urls-<?php echo (int)$u['unique_id']; ?>">
                                                <td colspan="8" class="p-0 border-0">
                                                    <div class="bg-light p-3 border-top">
                                                        <div class="small fw-bold text-muted mb-2">
                                                            <i class="fas fa-link me-1"></i> Grouped URLs (<?php echo $urlCount; ?>)
                                                        </div>
                                                        <ul class="list-unstyled mb-0 small">
                                                            <?php foreach ($pageUrls as $pUrl): ?>
                                                            <li class="mb-1 text-break">
                                                                <i class="fas fa-angle-right text-muted me-2"></i>
                                                                <?php echo htmlspecialchars($pUrl['url']); ?>
                                                            </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
    <?php endforeach; else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-5">
                                                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                                                    <div>No unique pages added yet.</div>
                                                </td>
                                            </tr>
    <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
</div>
</div>

<script src="<?php echo $baseDir; ?>/modules/projects/js/issue_title_field.js"></script>
<div class="col-lg-12 d-none" id="issuesDetailCol">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary d-none mb-1" id="issuesBackBtn"><i class="fas fa-arrow-left"></i> Back</button>
                                        <div class="fw-semibold" id="issueSelectedPageName">Select a page</div>
                                        <div class="small text-muted" id="issueSelectedPageMeta">Tester: - | Env: - | Issues: -</div>
                                    </div>
                                    <div class="d-flex gap-2 issues-actions">
                                        <?php if ($_SESSION['role'] !== 'client'): ?>
                                        <button class="btn btn-sm btn-outline-primary" id="issueAddFinalBtn" disabled>
                                            <i class="fas fa-plus"></i> Add Issue
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Page URLs Card -->
                                <div class="card mb-3 mx-3 mt-3" id="pageUrlsCard" style="display: none; border-left: 3px solid #0d6efd;">
                                    <div class="card-header bg-light" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#pageUrlsList">
                                        <i class="fas fa-chevron-right" id="urlsToggleIcon" style="transition: transform 0.3s ease;"></i>
                                        <i class="fas fa-link ms-2"></i>
                                        <strong>Page URLs</strong>
                                        <span class="badge bg-secondary ms-2" id="urlsCount">0</span>
                                    </div>
                                    <div class="collapse" id="pageUrlsList">
                                        <div class="card-body">
                                            <ul class="list-unstyled mb-0" id="pageUrlsListContent">
                                                <!-- URLs will be populated here -->
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card-body">
                                    <ul class="nav nav-tabs mb-3" id="pageIssueTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="final-issues-tab" data-bs-toggle="tab" data-bs-target="#final_issues_tab" type="button">Final Issues <span class="badge bg-secondary ms-1" id="finalIssuesCountBadge">0</span></button>
                                        </li>
                                    </ul>

            <div class="tab-content">
                                        <div class="tab-pane fade show active" id="final_issues_tab" role="tabpanel">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="small text-muted">
                                                    Issues added by users for the final report.
                                                    <?php if ($_SESSION['role'] === 'client'): ?>
                                                    Use the Update button to change status or add a regression comment.
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($_SESSION['role'] !== 'client'): ?>
                                                <div class="d-flex gap-2 issue-expand-actions">
                                                    <button class="btn btn-sm btn-outline-secondary" id="finalDeleteSelected" disabled>Delete Selected</button>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($_SESSION['role'] === 'client'): ?>
                                            <div class="row g-2 align-items-end mb-3">
                                                <div class="col-md-6">
                                                    <label for="clientIssueSearch" class="form-label">Search issues</label>
                                                    <input type="search" class="form-control" id="clientIssueSearch" placeholder="Search by issue title or details">
                                                </div>
                                                <div class="col-md-3">
                                                    <label for="clientIssueStatusFilter" class="form-label">Filter by status</label>
                                                    <select class="form-select" id="clientIssueStatusFilter">
                                                        <option value="">All statuses</option>
                                                        <option value="open">Open</option>
                                                        <option value="in_progress">In Progress</option>
                                                        <option value="fixed">Fixed</option>
                                                        <option value="resolved">Resolved</option>
                                                        <option value="reopened">Reopened</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <?php if ($_SESSION['role'] !== 'client'): ?>
                                                            <th style="width:30px;"><input type="checkbox" id="finalSelectAll"></th>
                                                            <?php endif; ?>
                                                            <th style="width:80px;">Issue Key</th>
                                                            <th>Issue Title</th>
                                                            <th style="width:100px;">Severity</th>
                                                            <th style="width:100px;">Priority</th>
                                                            <th style="width:120px;">Status</th>
                                                            <?php if ($_SESSION['role'] !== 'client'): ?>
                                                            <th style="width:120px;">QA Status</th>
                                                            <th style="width:120px;">Reporter</th>
                                                            <th style="width:120px;">QA Name</th>
                                                            <?php endif; ?>
                                                            <th style="width:150px;">Pages</th>
                                                            <th style="width:120px;">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="finalIssuesBody">
                                                        <tr><td colspan="<?php echo ($_SESSION['role'] === 'client') ? '7' : '11'; ?>" class="text-muted text-center">Select a page to view issues.</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="issues_common" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-0">Common Issues</h6>
                            <div class="small text-muted"><?php echo $_SESSION['role'] === 'client' ? 'Read-only summary of issues that apply to multiple pages.' : 'Manage issues that apply to multiple pages.'; ?></div>
                        </div>
                        <?php if ($_SESSION['role'] !== 'client'): ?>
                        <button class="btn btn-sm btn-outline-primary" id="commonAddBtn"><i class="fas fa-plus"></i> Add Common Issue</button>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <th style="width:30px;"><input type="checkbox" id="commonSelectAll"></th>
                                    <?php endif; ?>
                                    <th>Common Issue Title</th>
                                    <th>Pages</th>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <th style="width:110px;">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="commonIssuesBody">
                                <tr><td colspan="<?php echo ($_SESSION['role'] === 'client') ? '2' : '4'; ?>" class="text-muted text-center">No common issues added yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0">
                        <?php if ($_SESSION['role'] === 'client'): ?>
                        Shared issues are maintained by the delivery team when the same problem appears on multiple pages.
                        <?php else: ?>
                        Tip: If a final issue applies to more than one page, fill the "Common Issue Title" field while adding it.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Final Issue Modal -->
            <div class="modal fade" id="finalIssueModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header d-block pb-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <h5 class="modal-title mb-0" id="finalEditorTitle">Final Issue Editor</h5>
                                    <div class="small text-muted">Manage issue title, details, and metadata</div>
                                    <div class="small mt-1" id="finalIssuePresenceIndicator" aria-live="polite"></div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            
                            <!-- Compact Title Row -->
                            <div class="row g-2">
                                <!-- Issue Title Container -->
                                <div class="col-lg-8">
                                    <div class="issue-title-compact" id="customIssueTitleWrap">
                                        <!-- Issue title input injected by JS -->
                                    </div>
                                </div>
                                
                                <!-- Common Issue Title (shows when multiple pages selected) -->
                                <div class="col-lg-4<?php echo $_SESSION['role'] === 'client' ? ' d-none' : ''; ?>">
                                    <div id="finalIssueCommonTitleWrap" class="d-none">
                                        <label class="form-label mb-1 small text-muted fw-bold">Common Title</label>
                                        <input type="text" class="form-control form-control-sm" id="finalIssueCommonTitle" placeholder="Common title for multi-page issue">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-body pt-2">
                            <input type="hidden" id="finalIssueEditId" value="">
                            <input type="hidden" id="finalIssueExpectedUpdatedAt" value="">
                            <div class="row g-3">
                                <div class="col-lg-8">
                                    <label class="form-label mb-1 fw-bold">Issue Details</label>
                                    <div class="d-flex justify-content-end mb-1<?php echo $_SESSION['role'] === 'client' ? ' d-none' : ''; ?>">
                                        <button class="btn btn-xs btn-outline-info" id="btnResetToTemplate">
                                            <i class="fas fa-undo"></i> Reset to Template
                                        </button>
                                    </div>
                                    <textarea id="finalIssueDetails" class="issue-summernote"></textarea>

                                    <!-- Chat and History Tabs (Moved here) -->
                                    <div class="mt-4 pt-3 border-top">
                                        <ul class="nav nav-tabs" id="finalIssueTabs" role="tablist">
                                            <li class="nav-item">
                                                <button class="nav-link active py-2 fw-bold" id="btnShowChat" data-bs-toggle="tab" data-bs-target="#tabChat">Chat / Comments</button>
                                            </li>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <li class="nav-item">
                                        <button class="nav-link py-2 fw-bold" id="btnShowHistory" data-bs-toggle="tab" data-bs-target="#tabHistory">Edit History</button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link py-2 fw-bold" id="btnShowVisitHistory" data-bs-toggle="tab" data-bs-target="#tabVisitHistory">Visit History</button>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                                <div class="tab-content mt-3">
                                    <div class="tab-pane fade show active" id="tabChat">
                                                <div class="issue-chat-container">
                                                    <div class="mb-3">
                                                        <?php if ($_SESSION['role'] !== 'client'): ?>
                                                        <label class="form-label small fw-bold">Comment Type</label>
                                                        <select id="finalIssueCommentType" class="form-select form-select-sm mb-2" style="max-width: 200px;">
                                                            <option value="normal">Normal Comment</option>
                                                            <option value="regression">Regression Comment</option>
                                                        </select>
                                                        <?php endif; ?>
                                                        <textarea id="finalIssueCommentEditor" class="issue-summernote"></textarea>
                                                    </div>
                                                    <div class="text-end mb-3">
                                                        <button class="btn btn-sm btn-primary" id="finalIssueAddCommentBtn">
                                                            <i class="fas fa-paper-plane me-1"></i> Add Comment
                                                        </button>
                                                    </div>
                                                    <div id="finalIssueCommentsList" class="small text-muted border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                                                        <div class="text-center py-5">
                                                            <i class="fas fa-comments fa-3x mb-3 opacity-25"></i>
                                                            <p>No comments yet.</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <div class="tab-pane fade" id="tabHistory">
                                        <div id="historyEntries" class="small border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                                            <div class="text-center py-5 text-muted">Loading history...</div>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="tabVisitHistory">
                                        <div id="visitHistoryEntries" class="small border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                                            <div class="text-center py-5 text-muted">Loading visit history...</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                                <div class="col-lg-4 issue-metadata">
                                    <label class="form-label">Issue Status</label>
                                    <select id="finalIssueStatus" class="form-select form-select-sm">
                                        <?php foreach ($issueStatuses as $status): ?>
                                            <option value="<?php echo htmlspecialchars($status['id']); ?>" style="color: <?php echo htmlspecialchars($status['color']); ?>;">
                                                <?php echo htmlspecialchars($status['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="form-label mt-2<?php echo $_SESSION['role'] === 'client' ? ' d-none' : ''; ?>">QA Status (Multi-select)</label>
                                    <select id="finalIssueQaStatus" class="form-select form-select-sm issue-select2-tags<?php echo $_SESSION['role'] === 'client' ? ' d-none' : ''; ?>" multiple>
                                        <?php foreach ($qaStatuses as $qs): ?>
                                            <option value="<?php echo htmlspecialchars($qs['status_key']); ?>"><?php echo htmlspecialchars($qs['status_label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="form-label mt-2">Page Name(s)</label>
                                    <select id="finalIssuePages" class="form-select form-select-sm issue-select2" multiple>
                                        <?php foreach ($projectPages as $p): ?>
                                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['page_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="d-grid gap-1 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary<?php echo $_SESSION['role'] === 'client' ? ' d-none' : ''; ?>" id="btnOpenUrlSelectionModal">
                                            <i class="fas fa-link me-1"></i> Manage Grouped URLs
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#finalIssueGroupedUrlsPreview" aria-expanded="false">
                                            <i class="fas fa-chevron-down me-1"></i> View Grouped URLs (<span id="groupedUrlsPreviewCount">0</span>)
                                        </button>
                                        <div class="collapse" id="finalIssueGroupedUrlsPreview">
                                            <div class="border rounded p-2 bg-light small">
                                                <ul class="mb-0 ps-3" id="finalIssueGroupedUrlsPreviewList"></ul>
                                            </div>
                                        </div>
                                        <div class="small text-muted" id="urlSelectionSummary">Pages: 0 | Grouped URLs: 0 selected</div>
                                    </div>
                                    <div class="d-none" aria-hidden="true">
                                        <select id="finalIssueGroupedUrls" class="form-select form-select-sm issue-select2" multiple></select>
                                    </div>
                                    <?php if ($_SESSION['role'] !== 'client'): ?>
                                    <label class="form-label mt-2">Reporter Name(s)</label>
                                    <select id="finalIssueReporters" class="form-select form-select-sm issue-select2" multiple>
                                        <?php foreach ($projectUsers as $u): ?>
                                            <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                    <!-- Dynamic Metadata Container -->
                                    <div id="finalIssueMetadataContainer" class="<?php echo $_SESSION['role'] === 'client' ? 'd-none' : ''; ?>"></div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary" id="finalIssueSaveBtn">Save</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- URLs Selection Modal -->
            <div class="modal fade" id="urlSelectionModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Manage Page Name(s) & Grouped URLs</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label fw-bold">Page Name(s)</label>
                            <select id="urlModalPages" class="form-select issue-select2" multiple></select>
                            <div class="form-text mb-3">Select one or multiple pages for this issue.</div>

                            <label class="form-label fw-bold">Grouped URLs</label>
                            <select id="urlModalGroupedUrls" class="form-select issue-select2-tags" multiple></select>
                            <div class="form-text">Search, select, or type custom URL and press Enter to add it.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" id="btnCopyGroupedUrls">
                                <i class="fas fa-copy me-1"></i> Copy Selected URLs
                            </button>
                            <button type="button" class="btn btn-primary" id="btnApplyUrlSelection" data-bs-dismiss="modal">
                                Apply
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Common Issue Modal -->
            <div class="modal fade" id="commonIssueModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="commonEditorTitle">New Common Issue</h5>
                                <div class="small text-muted">Title + pages + details.</div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="commonIssueEditId" value="">
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <label class="form-label">Common Issue Title</label>
                                    <input type="text" class="form-control" id="commonIssueTitle" placeholder="Common issue title">
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label">Page Name(s)</label>
                                    <select id="commonIssuePages" class="form-select issue-select2" multiple>
                                        <?php foreach ($projectPages as $p): ?>
                                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['page_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Details</label>
                                    <textarea id="commonIssueDetails" class="issue-summernote"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary" id="commonIssueSaveBtn">Save</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Issue Image Modal -->
            <div class="modal fade issue-image-modal" id="issueImageModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title mb-0">Image Preview</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-3" style="overflow: hidden;">
                            <div class="text-center">
                                <img id="issueImagePreview" src="" alt="" class="img-fluid" style="max-height: 55vh; object-fit: contain;">
                            </div>
                            <div id="issueImageAltText" class="mt-2 p-2 bg-light rounded small" style="display: none;">
                                <strong>Alt Text:</strong> <span id="issueImageAltTextContent"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
 <!-- end #issues tab-pane -->
