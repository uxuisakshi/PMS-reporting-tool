/**
 * Feedback page: Summernote init, table filters, view details modal
 */
$(document).ready(function () {
    var baseDir = document.getElementById('feedbackApp').dataset.baseDir || '';

    // Initialize Summernote
    $('#feedbackContent').summernote({
        height: 200,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link']],
            ['view', ['fullscreen', 'codeview']]
        ],
        placeholder: 'Enter your feedback here...'
    });

    // Handle form reset
    $('button[type="reset"]').on('click', function () {
        $('#feedbackContent').summernote('code', '');
    });

    // My Feedback table filters
    function applyMyFeedbackFilters() {
        var $table = $('#myFeedbackTable');
        if (!$table.length) return;
        var search = String($('#myFeedbackSearch').val() || '').toLowerCase().trim();
        var status = String($('#myFeedbackStatusFilter').val() || '').toLowerCase().trim();
        var type   = String($('#myFeedbackTypeFilter').val() || '').toLowerCase().trim();

        var visible = 0;
        $table.find('tbody tr').each(function () {
            var $row      = $(this);
            var rowSearch = String($row.data('search') || '');
            var rowStatus = String($row.data('status') || '');
            var rowType   = String($row.data('type') || '');

            var show = (!search || rowSearch.indexOf(search) !== -1) &&
                       (!status || rowStatus === status) &&
                       (!type   || rowType   === type);
            $row.toggle(show);
            if (show) visible++;
        });
        $('#myFeedbackVisibleCount').text(visible);
    }

    $('#myFeedbackSearch, #myFeedbackStatusFilter, #myFeedbackTypeFilter').on('input change', applyMyFeedbackFilters);
    $('#myFeedbackClearFilters').on('click', function () {
        $('#myFeedbackSearch').val('');
        $('#myFeedbackStatusFilter').val('');
        $('#myFeedbackTypeFilter').val('');
        applyMyFeedbackFilters();
    });

    // View feedback details
    window.viewFeedbackDetails = function (feedbackId) {
        fetch(baseDir + '/api/feedback.php?action=get_user_feedback&feedback_id=' + encodeURIComponent(feedbackId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { showToast('Failed to load feedback details', 'danger'); return; }
                var fb = data.feedback;
                var html = '<div class="row">' +
                    '<div class="col-md-6"><h6>Feedback Information</h6>' +
                    '<p><strong>Date:</strong> ' + new Date(fb.created_at).toLocaleString() + '</p>' +
                    '<p><strong>Status:</strong> <span class="badge bg-primary">' + (fb.status || 'open') + '</span></p></div>' +
                    '<div class="col-md-6"><h6>Project Information</h6>' +
                    (fb.project_title
                        ? '<p><strong>Project:</strong> ' + fb.project_title + ' (' + fb.project_code + ')</p>'
                        : '<p><strong>Type:</strong> General Feedback</p>') +
                    '</div></div>';

                if (fb.recipients) {
                    html += '<div class="mt-3"><h6>Recipients</h6><p>' + fb.recipients + '</p></div>';
                }
                html += '<div class="mt-3"><h6>Content</h6>' +
                    '<div class="border p-3 rounded bg-light">' + sanitizeFeedbackHtml(fb.content) + '</div></div>';

                document.getElementById('feedbackDetailsContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('viewFeedbackDetailsModal')).show();
            })
            .catch(function () { showToast('Error loading feedback details', 'danger'); });
    };
});

/**
 * Sanitize HTML from server before injecting into DOM (XSS prevention).
 * Allows basic formatting tags only; strips scripts, event handlers, etc.
 */
function sanitizeFeedbackHtml(html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html;

    // Remove dangerous elements
    var dangerous = tmp.querySelectorAll('script,iframe,object,embed,form,input,button,link,meta,style,base');
    dangerous.forEach(function (el) { el.parentNode.removeChild(el); });

    // Strip event handler attributes from all elements
    tmp.querySelectorAll('*').forEach(function (el) {
        Array.from(el.attributes).forEach(function (attr) {
            if (/^on/i.test(attr.name) || /^javascript:/i.test(attr.value)) {
                el.removeAttribute(attr.name);
            }
        });
    });

    return tmp.innerHTML;
}

