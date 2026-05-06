<!-- Final Issue Modal -->
<?php if ($_SESSION['role'] === 'client'): ?>
<style>
:root {
    --client-issue-sidebar-width: min(310px, 100vw);
    --client-issue-dialog-width: min(960px, calc(100vw - 3rem));
    --client-issue-shell-top-offset: 1rem;
    --client-issue-shell-side-gap: 1rem;
}
body.app-shell {
    transition: padding-right .28s ease;
}
body.app-shell > header,
body.app-shell > header .navbar.sticky-top {
    transition: width .28s ease, right .28s ease;
}
#finalIssueModal.client-issue-sidebar-shell {
    position: fixed;
    inset: 0;
    z-index: 1045;
    pointer-events: none;
    background: transparent;
    transition: background .28s ease;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-panel {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: var(--client-issue-sidebar-width);
    max-height: 100vh;
    display: flex;
    flex-direction: column;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 14%);
    border-left: 1px solid #dce8f8;
    box-shadow: -24px 0 60px rgba(15, 23, 42, .18);
    transform: translateX(100%);
    transition: transform .28s ease;
    overflow: hidden;
    pointer-events: auto;
}
#finalIssueModal.client-issue-sidebar-shell.is-dialog-expanded {
    z-index: 1085;
    background: rgba(15, 23, 42, .12);
}
#finalIssueModal.client-issue-sidebar-shell.is-dialog-expanded .client-issue-sidebar-panel {
    top: var(--client-issue-shell-top-offset);
    left: 50%;
    right: auto;
    bottom: var(--client-issue-shell-side-gap);
    width: var(--client-issue-dialog-width);
    max-width: calc(100vw - (var(--client-issue-shell-side-gap) * 2));
    max-height: calc(100vh - var(--client-issue-shell-top-offset) - var(--client-issue-shell-side-gap));
    border: 1px solid #dce8f8;
    border-radius: 24px;
    box-shadow: 0 28px 80px rgba(15, 23, 42, .26);
    transform: translateX(-50%);
}
#finalIssueModal.client-issue-sidebar-shell.show .client-issue-sidebar-panel,
#finalIssueModal.client-issue-sidebar-shell.is-open .client-issue-sidebar-panel {
    transform: translateX(0);
}
#finalIssueModal.client-issue-sidebar-shell.show.is-dialog-expanded .client-issue-sidebar-panel,
#finalIssueModal.client-issue-sidebar-shell.is-open.is-dialog-expanded .client-issue-sidebar-panel {
    transform: translateX(-50%);
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
    padding: .6rem .7rem .4rem;
    border-bottom: 1px solid #e5edf7;
    background: rgba(255, 255, 255, .96);
    backdrop-filter: blur(10px);
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-actions {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    flex: 0 0 auto;
}
#finalIssueModal.client-issue-sidebar-shell .client-sidebar-icon-btn {
    width: 32px;
    height: 32px;
    border: 1px solid #d7e5f7;
    border-radius: 999px;
    background: #fff;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 16px rgba(15, 23, 42, .06);
}
#finalIssueModal.client-issue-sidebar-shell .client-sidebar-icon-btn:hover,
#finalIssueModal.client-issue-sidebar-shell .client-sidebar-icon-btn:focus {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-title-stack {
    min-width: 0;
}
#finalIssueModal.client-issue-sidebar-shell #finalEditorTitle {
    font-size: 1.02rem;
    line-height: 1.2;
    color: #0f172a;
    word-break: break-word;
}
#finalIssueModal.client-issue-sidebar-shell #finalIssuePresenceIndicator:empty {
    display: none;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-body {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
    padding: .35rem .55rem 0;
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row {
    display: flex;
    align-items: center;
    gap: .4rem;
    background: #f3f7fd;
    border: 1px solid #dce8f8;
    border-radius: 14px;
    padding: .32rem .38rem;
    margin-bottom: .3rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row .form-label {
    margin-bottom: .1rem !important;
    font-size: .74rem;
    line-height: 1.1;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row .form-select {
    min-height: 32px;
    padding-top: .18rem;
    padding-bottom: .18rem;
    padding-left: .55rem;
    font-size: .84rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-status-row #finalIssueSaveBtn {
    flex: 0 0 auto;
    min-width: 68px;
    height: 32px;
    border-radius: 999px;
    padding-inline: .65rem;
    font-size: .82rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-conversation {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    padding: 0;
    border: 1px solid #e6eef8;
    border-radius: 18px 18px 0 0;
    border-bottom: 0;
    background: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell #finalIssueCommentsList {
    flex: 1 1 auto;
    min-height: 0;
    max-height: none !important;
    overflow-y: auto;
    padding: .45rem !important;
    background: linear-gradient(180deg, #f8fbff 0%, #f3f7fd 100%) !important;
    border: 0 !important;
    border-radius: 18px 18px 0 0 !important;
}
#finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-footer {
    padding: .4rem .55rem .5rem;
    background: #f0f2f5;
    border-top: 1px solid #e6eef8;
    box-shadow: 0 -12px 24px rgba(15, 23, 42, .04);
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
    background: transparent;
    border-radius: 14px 14px 0 0;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer.collapsed {
    padding-bottom: 0;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-toggle {
    width: 100%;
    border-radius: 12px;
    border: 1px solid #ced4da;
    background: #ffffff;
    color: #0d6efd;
    font-weight: 600;
    margin: 0;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-toggle:focus,
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-toggle:focus-visible {
    outline: 3px solid #0d6efd !important;
    outline-offset: 2px;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, .25);
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-toggle.expanded {
    margin-bottom: 8px;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-body {
    display: none;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-body.open {
    display: block;
    padding-bottom: 8px;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap {
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    overflow: auto;
    height: 128px;
    min-width: 0;
    min-height: 108px;
    max-width: 100%;
    max-height: 260px;
    resize: vertical;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-editor {
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    display: flex;
    flex-direction: column;
    min-height: 100%;
    height: 100%;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-toolbar {
    display: flex;
    flex-wrap: nowrap;
    gap: 4px;
    overflow-x: auto;
    overflow-y: hidden;
    white-space: nowrap;
    border: 0;
    background: transparent;
    padding: 4px 0 0 0;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-toolbar .note-btn-group {
    display: inline-flex;
    flex-wrap: nowrap;
    margin-right: 0;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-editing-area {
    background: transparent;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-editable {
    min-height: 72px;
    max-height: none !important;
    height: 100% !important;
    padding: .55rem .7rem;
    resize: none !important;
    overflow: auto !important;
    background: #fff;
    border-radius: 10px;
    word-break: break-word;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-placeholder {
    left: .7rem !important;
    right: .7rem !important;
    white-space: normal !important;
    overflow-wrap: anywhere;
    line-height: 1.35;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-statusbar,
#finalIssueModal.client-issue-sidebar-shell .client-chat-editor-wrap .note-resizebar {
    display: none !important;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-compose-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-charcount {
    color: #6c757d;
    font-size: .78rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer #finalIssueAddCommentBtn {
    min-width: 92px;
    border-radius: 999px;
    padding-inline: .8rem;
    min-height: 34px;
    font-size: .84rem;
}
#finalIssueModal.client-issue-sidebar-shell .client-chat-composer .mt-2 {
    margin-top: .3rem !important;
}
#finalIssueModal.client-issue-sidebar-shell .message {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .22rem;
    padding: 0 !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    margin-bottom: .55rem !important;
}
#finalIssueModal.client-issue-sidebar-shell .message.own-message {
    align-items: flex-end;
}
#finalIssueModal.client-issue-sidebar-shell .message.other-message {
    align-items: flex-start;
}
#finalIssueModal.client-issue-sidebar-shell .message-author-chip {
    width: 24px;
    height: 24px;
    flex: 0 0 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #dbeafe;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    cursor: default;
    box-shadow: 0 4px 10px rgba(29, 78, 216, .08);
}
#finalIssueModal.client-issue-sidebar-shell .message-main {
    flex: 0 1 auto;
    min-width: 0;
    width: min(100%, 88%);
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
}
#finalIssueModal.client-issue-sidebar-shell .message-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .35rem;
    margin-bottom: .18rem !important;
    font-size: .74rem;
    line-height: 1.15;
    width: 100%;
    white-space: nowrap;
}
#finalIssueModal.client-issue-sidebar-shell .message-meta-left,
#finalIssueModal.client-issue-sidebar-shell .message-meta-right,
#finalIssueModal.client-issue-sidebar-shell .message-action-row {
    display: flex;
    align-items: center;
    gap: .25rem;
    min-width: 0;
}
#finalIssueModal.client-issue-sidebar-shell .message-meta-left {
    flex: 1 1 auto;
    overflow: hidden;
}
#finalIssueModal.client-issue-sidebar-shell .message-meta-right {
    flex: 0 0 auto;
    color: #64748b;
}
#finalIssueModal.client-issue-sidebar-shell .message-action-row {
    flex: 0 0 auto;
}
#finalIssueModal.client-issue-sidebar-shell .message-action-btn {
    width: 20px;
    height: 20px;
    padding: 0 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    background: transparent;
    color: #64748b;
    border-radius: 999px;
    text-decoration: none !important;
}
#finalIssueModal.client-issue-sidebar-shell .message-action-btn:hover,
#finalIssueModal.client-issue-sidebar-shell .message-action-btn:focus {
    background: rgba(148, 163, 184, .14);
    color: #0f172a;
}
#finalIssueModal.client-issue-sidebar-shell .message-action-btn.text-danger {
    color: #dc2626 !important;
}
#finalIssueModal.client-issue-sidebar-shell .message-action-btn.text-danger:hover,
#finalIssueModal.client-issue-sidebar-shell .message-action-btn.text-danger:focus {
    background: rgba(220, 38, 38, .12);
}
#finalIssueModal.client-issue-sidebar-shell .message-action-btn i {
    font-size: .72rem;
}
#finalIssueModal.client-issue-sidebar-shell .message-content {
    width: 100%;
    display: block;
    max-width: 100%;
    padding: .45rem .55rem !important;
    border-radius: 14px !important;
    word-break: break-word;
    font-size: .84rem;
    line-height: 1.32;
}
#finalIssueModal.client-issue-sidebar-shell .other-message .message-content {
    background: #ffffff !important;
    border: 1px solid #dce8f8;
}
#finalIssueModal.client-issue-sidebar-shell .own-message .message-content {
    background: linear-gradient(180deg, #dceeff 0%, #cfe5ff 100%) !important;
    border: 1px solid #bad8ff;
}
#finalIssueModal.client-issue-sidebar-shell .reply-preview {
    width: 100%;
    margin-bottom: .3rem !important;
    padding: .3rem .4rem !important;
    font-size: .76rem;
    line-height: 1.2;
    border-left-width: 2px !important;
}
body.client-issue-sidebar-open {
    overflow: auto !important;
    padding-right: 0 !important;
}
@media (min-width: 768px) {
    body.client-issue-sidebar-open.app-shell:not(.client-issue-sidebar-dialog-expanded) {
        padding-right: var(--client-issue-sidebar-width) !important;
    }
    body.client-issue-sidebar-open.app-shell:not(.client-issue-sidebar-dialog-expanded) > header {
        width: calc(100% - var(--client-issue-sidebar-width));
    }
    body.client-issue-sidebar-open.app-shell:not(.client-issue-sidebar-dialog-expanded) > header .navbar.sticky-top {
        left: 0;
        right: var(--client-issue-sidebar-width);
        width: calc(100% - var(--client-issue-sidebar-width));
    }
}
@media (max-width: 767.98px) {
    #finalIssueModal.client-issue-sidebar-shell .client-issue-sidebar-panel {
        width: 100vw;
        top: 0;
        max-height: 100vh;
    }
    #finalIssueModal.client-issue-sidebar-shell.is-dialog-expanded .client-issue-sidebar-panel {
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100vw;
        max-width: 100vw;
        max-height: 100vh;
        border-radius: 0;
        transform: translateX(0);
        border-right: 0;
        border-bottom: 0;
    }
    #finalIssueModal.client-issue-sidebar-shell .client-issue-status-row {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
<div class="client-issue-sidebar-shell" id="finalIssueModal" aria-hidden="true">
    <div class="client-issue-sidebar-panel" role="dialog" aria-modal="false" aria-labelledby="finalEditorTitle">
        <div class="client-issue-sidebar-header">
            <div class="client-issue-title-stack">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                    <span class="badge bg-primary" id="clientIssueSidebarKey">Issue</span>
                    <span class="badge bg-secondary" id="finalIssueCommentCountInTitle">0 Comments</span>
                </div>
                <h5 class="mb-1" id="finalEditorTitle">Issue Title</h5>
                <div class="small mt-1" id="finalIssuePresenceIndicator" aria-live="polite"></div>
            </div>
            <div class="client-issue-sidebar-actions">
                <button type="button" class="client-sidebar-icon-btn client-sidebar-expand" aria-label="Expand dialog" title="Expand dialog">
                    <i class="fas fa-expand"></i>
                </button>
                <button type="button" class="btn-close client-sidebar-close" aria-label="Close"></button>
            </div>
        </div>

        <input type="hidden" id="finalIssueEditId" value="">
        <input type="hidden" id="finalIssueExpectedUpdatedAt" value="">

        <div class="client-issue-sidebar-body">
            <div class="client-issue-status-row issue-metadata">
                <div class="flex-grow-1 min-w-0">
                    <label class="form-label mb-1 fw-semibold">Issue Status</label>
                    <select id="finalIssueStatus" class="form-select form-select-sm">
                        <?php foreach ($issueStatuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status['id']); ?>" style="color: <?php echo htmlspecialchars($status['color'] ?? '#6c757d'); ?>;">
                                <?php echo htmlspecialchars($status['name'] ?? 'Unknown'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary btn-sm" id="finalIssueSaveBtn">Update</button>
            </div>

            <div id="finalIssueComments" class="client-issue-conversation issue-chat-container client-chat-container">
                <div id="finalIssueCommentsList" class="small text-muted border rounded p-3 bg-light client-comments-list">
                    <div class="text-center py-5">
                        <i class="fas fa-comments fa-3x mb-3 opacity-25"></i>
                        <p>No comments yet.</p>
                    </div>
                </div>
            </div>

            <div class="client-issue-sidebar-footer">
                <div class="client-chat-composer collapsed" id="finalIssueCommentComposer">
                    <button type="button" class="btn btn-sm client-chat-compose-toggle" id="finalIssueComposeToggle">
                        <i class="fas fa-comment-dots"></i> Compose
                    </button>
                    <div class="client-chat-compose-body" id="finalIssueComposeBody">
                        <div class="client-chat-editor-wrap compact-editor-wrap">
                            <textarea id="finalIssueCommentEditor" class="issue-summernote"></textarea>
                        </div>
                        <div class="client-chat-compose-actions mt-2">
                            <button class="btn btn-sm btn-success" id="finalIssueAddCommentBtn">
                                <i class="fas fa-paper-plane me-1"></i> Send
                            </button>
                            <span class="client-chat-charcount" id="finalIssueCommentCharCount">0/1000</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-none" aria-hidden="true">
                <div class="issue-title-compact" id="customIssueTitleWrap"></div>
                <input type="text" class="form-control form-control-sm" id="finalIssueCommonTitle" placeholder="Common title for multi-page issue">
                <textarea id="finalIssueDetails" class="issue-summernote"></textarea>
                <select id="finalIssuePages" class="form-select form-select-sm issue-select2" multiple>
                    <?php foreach ($projectPages as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"><?php
                            if (!empty($p['page_number'])) {
                                echo htmlspecialchars($p['page_number']) . ' - ' . htmlspecialchars($p['page_name']);
                            } else {
                                echo htmlspecialchars($p['page_name']);
                            }
                        ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="finalIssueGroupedUrlsPreview">
                    <div><ul id="finalIssueGroupedUrlsPreviewList"></ul></div>
                </div>
                <div id="groupedUrlsPreviewCount">0</div>
                <div id="urlSelectionSummary"></div>
                <select id="finalIssueGroupedUrls" class="form-select form-select-sm issue-select2" multiple></select>
                <div id="finalIssueMetadataContainer"></div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="modal fade" id="finalIssueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header d-block pb-2">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="modal-title mb-0 d-flex align-items-center gap-2" id="finalEditorTitleWrap">
                            <span id="finalEditorTitle">Final Issue Editor</span>
                            <span class="badge bg-secondary" id="finalIssueCommentCountInTitle">0 Comments</span>
                        </h5>
                        <div class="small text-muted">Manage issue title, details, and metadata</div>
                        <div class="small mt-1" id="finalIssuePresenceIndicator" aria-live="polite"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="row g-2">
                    <div class="col-lg-8">
                        <div class="issue-title-compact" id="customIssueTitleWrap">
                            <!-- Issue title input injected by JS -->
                        </div>
                    </div>

                    <div class="col-lg-4">
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
                        <div class="d-flex justify-content-end mb-1">
                            <button class="btn btn-xs btn-outline-info" id="btnResetToTemplate">
                                <i class="fas fa-undo"></i> Reset to Template
                            </button>
                        </div>
                        <textarea id="finalIssueDetails" class="issue-summernote"></textarea>

                        <div class="mt-4 pt-3 border-top">
                            <ul class="nav nav-tabs" id="finalIssueTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active py-2 fw-bold" id="btnShowChat" data-bs-toggle="tab" data-bs-target="#tabChat">
                                        Chat / Comments
                                        <span class="badge bg-secondary ms-1" id="finalIssueCommentCountBadge">0</span>
                                    </button>
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
                                    <div class="issue-chat-container" id="finalIssueComments">
                                        <?php if ($_SESSION['role'] !== 'client'): ?>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">Comment Type</label>
                                            <select id="finalIssueCommentType" class="form-select form-select-sm mb-2" style="max-width: 200px;">
                                                <option value="normal">Normal Comment</option>
                                                <option value="regression">Regression Comment</option>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        <div class="mb-3">
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
                                <option value="<?php echo htmlspecialchars($status['id']); ?>" style="color: <?php echo htmlspecialchars($status['color'] ?? '#6c757d'); ?>;">
                                    <?php echo htmlspecialchars($status['name'] ?? 'Unknown'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label mt-2">Page Name(s)</label>
                        <select id="finalIssuePages" class="form-select form-select-sm issue-select2" multiple>
                            <?php foreach ($projectPages as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php
                                    $label = '';
                                    if (!empty($p['page_number'])) {
                                        $label = htmlspecialchars($p['page_number']) . ' - ' . htmlspecialchars($p['page_name']);
                                    } else {
                                        $label = htmlspecialchars($p['page_name']);
                                    }
                                    echo $label;
                                ?></option>
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
                        <label class="form-label mt-2">Reporter Name(s)</label>
                        <select id="finalIssueReporters" class="form-select form-select-sm issue-select2" multiple>
                            <?php foreach ($projectUsers as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label mt-2">QA Name</label>
                        <select id="finalIssueAssignee" class="form-select form-select-sm issue-select2" multiple>
                            <?php foreach ($projectUsers as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="reporterQaStatusContainer" class="mt-2 d-none">
                            <label class="form-label mb-1">QA Status By Reporter</label>
                            <div id="reporterQaStatusRows" class="small border rounded p-2 bg-light"></div>
                            <small class="text-muted">This mapping is used for reporter-wise performance scoring.</small>
                        </div>
                        <!-- Dynamic Metadata Container -->
                        <div id="finalIssueMetadataContainer"></div>
                        
                        <!-- Client Ready Checkbox (only for QA, Project Lead, Admin) -->
                        <?php if (in_array($userRole, ['qa', 'project_lead', 'admin'])): ?>
                        <div class="mt-3 pt-3 border-top">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="finalIssueClientReady" value="1">
                                <label class="form-check-label fw-bold" for="finalIssueClientReady">
                                    <i class="fas fa-eye me-1 text-primary"></i> Client Ready
                                </label>
                                <div class="form-text small">Mark this issue as ready for client viewing</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <span id="draftSaveIndicator" class="text-muted small me-auto" style="display:none;transition:opacity 0.4s;"></span>
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="finalIssueSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
                <button type="button" class="btn btn-outline-danger me-auto" id="btnClearGroupedUrls">
                    <i class="fas fa-trash-alt me-1"></i> Clear All URLs
                </button>
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
