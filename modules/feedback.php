<?php
/**
 * Global User Feedback Form
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
$auth->requireLogin();

$baseDir = getBaseDir();
$pageTitle = 'Send Feedback';
$currentUserId = $_SESSION['user_id'];

$db = Database::getInstance();
$usersStmt = $db->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND id != ? ORDER BY full_name ASC");
$usersStmt->execute([$currentUserId]);
$activeUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>
<!-- Summernote -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="container mt-4 mb-5" style="max-width: 800px;" id="feedbackApp" data-base-dir="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h2><i class="fas fa-comment-dots text-primary"></i> Send Feedback</h2>
            <p class="text-muted">We value your input. Let us know how we can improve your portal experience or report any issues you are facing.</p>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form id="globalFeedbackForm">
                <input type="hidden" name="action" value="submit_feedback">
                <input type="hidden" name="is_generic" value="1">

                <div class="mb-3">
                    <label class="form-label fw-bold">Send To</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="sendToAdmin" name="send_to_admin" value="1" checked>
                        <label class="form-check-label" for="sendToAdmin">Admin</label>
                    </div>
                    <select id="recipientSelect" name="recipient_ids[]" multiple class="form-select">
                        <?php foreach ($activeUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>">
                                <?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $u['role'])), ENT_QUOTES, 'UTF-8'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Hold Ctrl/Cmd to select multiple recipients. Leave empty to send to Admin only.</small>
                </div>

                <div class="mb-4">
                    <label for="feedbackContent" class="form-label fw-bold">Message</label>
                    <div id="feedbackContent"></div>
                </div>

                <div class="d-flex justify-content-end">
                    <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">Cancel</a>
                    <button type="submit" id="submitFeedbackBtn" class="btn btn-primary px-4">
                        <i class="fas fa-paper-plane me-1"></i> Submit Feedback
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- My Feedback History -->
    <div class="mt-5" id="myFeedbackSection">
        <h5><i class="fas fa-history me-2"></i>My Feedback History</h5>
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" id="myFeedbackSearch" class="form-control form-control-sm" placeholder="Search feedback...">
            </div>
            <div class="col-md-3">
                <select id="myFeedbackStatusFilter" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button id="myFeedbackClearFilters" class="btn btn-sm btn-outline-secondary w-100">Clear</button>
            </div>
            <div class="col-md-3 text-end text-muted small pt-2">
                Showing <span id="myFeedbackVisibleCount">-</span> items
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="myFeedbackTable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Preview</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="myFeedbackTableBody">
                    <tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <nav aria-label="Feedback pagination" id="feedbackPaginationNav" style="display: none;">
            <ul class="pagination pagination-sm justify-content-center" id="feedbackPagination"></ul>
        </nav>
    </div>
</div>

<!-- View Feedback Details Modal -->
<div class="modal fade" id="viewFeedbackDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Feedback Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="feedbackDetailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo $baseDir; ?>/assets/js/feedback.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/feedback.js'); ?>"></script>
<script nonce="<?php echo htmlspecialchars($_SESSION['csp_nonce'] ?? '', ENT_QUOTES); ?>">
$(document).ready(function() {
    var baseDir = document.getElementById('feedbackApp').dataset.baseDir || '';

    // Init Select2 for recipient dropdown
    $('#recipientSelect').select2({
        placeholder: 'Select recipients (optional)...',
        allowClear: true,
        width: '100%'
    });

    // Load feedback history
    var currentPage = 1;
    function loadFeedbackHistory(page) {
        page = page || 1;
        currentPage = page;
        fetch(baseDir + '/api/feedback.php?action=list_my_feedbacks&page=' + page)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var tbody = document.getElementById('myFeedbackTableBody');
                if (!data.success || !data.feedbacks || data.feedbacks.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No feedback submitted yet.</td></tr>';
                    document.getElementById('myFeedbackVisibleCount').textContent = '0';
                    document.getElementById('feedbackPaginationNav').style.display = 'none';
                    return;
                }
                var rows = '';
                data.feedbacks.forEach(function(fb) {
                    var rawText = fb.content ? fb.content.replace(/<[^>]+>/g, '') : '';
                    var preview = rawText.substring(0, 80).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    var date = new Date(fb.created_at).toLocaleDateString();
                    var status = fb.status || 'open';
                    var statusLabels = {'open':'Open','in_progress':'In Progress','resolved':'Resolved','closed':'Closed'};
                    var statusColors = {'open':'secondary','in_progress':'primary','resolved':'success','closed':'dark'};
                    rows += '<tr data-search="' + preview.toLowerCase() + '" data-status="' + status + '" data-type="">'
                        + '<td class="text-nowrap">' + date + '</td>'
                        + '<td>' + preview + (rawText.length >= 80 ? '...' : '') + '</td>'
                        + '<td><span class="badge bg-' + (statusColors[status]||'secondary') + '">' + (statusLabels[status]||status) + '</span></td>'
                        + '<td><button class="btn btn-sm btn-outline-primary" onclick="viewFeedbackDetails(' + fb.id + ')">View</button></td>'
                        + '</tr>';
                });
                tbody.innerHTML = rows;
                
                // Update pagination
                if (data.pagination && data.pagination.total_pages > 1) {
                    renderPagination(data.pagination);
                    document.getElementById('feedbackPaginationNav').style.display = 'block';
                } else {
                    document.getElementById('feedbackPaginationNav').style.display = 'none';
                }
                
                document.getElementById('myFeedbackVisibleCount').textContent = data.pagination ? data.pagination.total_count : data.feedbacks.length;
            })
            .catch(function() {
                document.getElementById('myFeedbackTableBody').innerHTML = '<tr><td colspan="4" class="text-center text-muted">Failed to load history.</td></tr>';
            });
    }
    
    function renderPagination(pagination) {
        var paginationEl = document.getElementById('feedbackPagination');
        var html = '';
        var currentPage = pagination.current_page;
        var totalPages = pagination.total_pages;
        
        // Previous button
        html += '<li class="page-item' + (currentPage === 1 ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="#" onclick="loadFeedbackHistory(' + (currentPage - 1) + '); return false;">Previous</a>';
        html += '</li>';
        
        // Page numbers
        var startPage = Math.max(1, currentPage - 2);
        var endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += '<li class="page-item"><a class="page-link" href="#" onclick="loadFeedbackHistory(1); return false;">1</a></li>';
            if (startPage > 2) {
                html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }
        
        for (var i = startPage; i <= endPage; i++) {
            html += '<li class="page-item' + (i === currentPage ? ' active' : '') + '">';
            html += '<a class="page-link" href="#" onclick="loadFeedbackHistory(' + i + '); return false;">' + i + '</a>';
            html += '</li>';
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            html += '<li class="page-item"><a class="page-link" href="#" onclick="loadFeedbackHistory(' + totalPages + '); return false;">' + totalPages + '</a></li>';
        }
        
        // Next button
        html += '<li class="page-item' + (currentPage === totalPages ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="#" onclick="loadFeedbackHistory(' + (currentPage + 1) + '); return false;">Next</a>';
        html += '</li>';
        
        paginationEl.innerHTML = html;
    }

    loadFeedbackHistory();

    // Submit form
    $('#globalFeedbackForm').on('submit', function(e) {
        e.preventDefault();
        var content = $('#feedbackContent').summernote('code');
        if (!content || content === '<p><br></p>') {
            showToast('Please enter your feedback message.', 'warning');
            return;
        }
        var btn = document.getElementById('submitFeedbackBtn');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'submit_feedback');
        fd.append('is_generic', '1');
        fd.append('send_to_admin', document.getElementById('sendToAdmin').checked ? '1' : '0');
        fd.append('content', content);
        fd.append('csrf_token', window._csrfToken || '');
        // Add selected recipients
        $('#recipientSelect option:selected').each(function() {
            fd.append('recipient_ids[]', $(this).val());
        });

        fetch(baseDir + '/api/feedback.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.success) {
                    $('#feedbackContent').summernote('code', '');
                    $('#recipientSelect').val(null).trigger('change');
                    showToast('Feedback submitted successfully!', 'success');
                    loadFeedbackHistory();
                } else {
                    showToast(data.message || 'Failed to submit feedback.', 'danger');
                }
            })
            .catch(function() { btn.disabled = false; showToast('Request failed.', 'danger'); });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
