/**
 * issues-common-aligned.js
 * Modeled after issues-all.js but for Common cached/all list.
 * delegating table rendering to window.renderCommonIssues().
 */

(function($) {
    "use strict";

    var projectId = 0;
    var baseDir   = '';
    
    var allIssues = [];
    var filteredIssues = [];
    var loadIssuesDebounceTimer = null;
    var currentPage = 1;
    var perPage = 25;

    function getPagedIssues() {
        var start = (currentPage - 1) * perPage;
        return filteredIssues.slice(start, start + perPage);
    }

    function getTotalPages() {
        return Math.max(1, Math.ceil(filteredIssues.length / perPage));
    }

    function renderPagination() {
        var total = filteredIssues.length;
        var totalPages = getTotalPages();
        var start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
        var end = Math.min(currentPage * perPage, total);
        var infoText = total === 0 ? 'No issues' : 'Showing ' + start + '–' + end + ' of ' + total;

        ['Top', ''].forEach(function(suffix) {
            var info = document.getElementById('paginationInfo' + suffix);
            var controls = document.getElementById('paginationControls' + suffix);
            var bar = document.getElementById('paginationBar' + suffix);

            if (info) info.textContent = infoText;
            if (bar) bar.style.display = totalPages <= 1 ? 'none' : '';
            if (!controls) return;
            if (totalPages <= 1) { controls.innerHTML = ''; return; }

            var html = '';
            // Prev
            html += '<li class="page-item' + (currentPage === 1 ? ' disabled' : '') + '">' +
                '<a class="page-link" href="#" data-page="' + (currentPage - 1) + '" aria-label="Previous">&laquo;</a></li>';

            var pages = [];
            if (totalPages <= 5) {
                for (var i = 1; i <= totalPages; i++) pages.push(i);
            } else {
                pages = [1];
                if (currentPage > 3) pages.push('...');
                var rangeStart = Math.max(2, currentPage - 1);
                var rangeEnd = Math.min(totalPages - 1, currentPage + 1);
                if (currentPage <= 3) rangeEnd = Math.min(totalPages - 1, 4);
                if (currentPage >= totalPages - 2) rangeStart = Math.max(2, totalPages - 3);
                for (var p = rangeStart; p <= rangeEnd; p++) pages.push(p);
                if (currentPage < totalPages - 2) pages.push('...');
                pages.push(totalPages);
            }

            pages.forEach(function (p) {
                if (p === '...') {
                    html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                } else {
                    html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '">' +
                        '<a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
                }
            });

            html += '<li class="page-item' + (currentPage === totalPages ? ' disabled' : '') + '">' +
                '<a class="page-link" href="#" data-page="' + (currentPage + 1) + '" aria-label="Next">&raquo;</a></li>';

            controls.innerHTML = html;

            controls.querySelectorAll('a.page-link[data-page]').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    var pg = parseInt(this.getAttribute('data-page'));
                    if (pg >= 1 && pg <= getTotalPages() && pg !== currentPage) {
                        currentPage = pg;
                        renderIssues();
                        var table = document.getElementById('commonIssuesTable');
                        if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        });
    }

    function loadIssues(options) {
        var opts = options || {};
        var preserveFilters = !!opts.preserveFilters;
        var keepPage        = !!opts.keepPage;
        var silentErrors    = !!opts.silentErrors;
        var immediate       = !!opts.immediate;

        if (loadIssuesDebounceTimer) { clearTimeout(loadIssuesDebounceTimer); loadIssuesDebounceTimer = null; }
        if (immediate) return performLoadIssues(preserveFilters, silentErrors, 0, keepPage);

        return new Promise(function(resolve) {
            loadIssuesDebounceTimer = setTimeout(function() {
                performLoadIssues(preserveFilters, silentErrors, 0, keepPage).then(resolve);
            }, 300);
        });
    }

    function performLoadIssues(preserveFilters, silentErrors, retryCount, keepPage) {
        if (!projectId) return Promise.resolve();

        var tbody = document.getElementById('commonIssuesBody');
        if (tbody && !preserveFilters) {
            var userRole = window.ProjectConfig ? window.ProjectConfig.userRole : '';
            var colspan = (userRole === 'client') ? 3 : 5;
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading common issues...</p></td></tr>';
        }

        var url = baseDir + '/api/issues.php?action=common_get_all&project_id=' + encodeURIComponent(projectId);

        return fetch(url, { credentials: 'same-origin' })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                allIssues = (data.issues || []).map(function(it) {
                    // Mapping to match legacy renderer expectations
                    return {
                        id: String(it.id),
                        issue_id: String(it.id),
                        issue_key: it.issue_key || '',
                        key: it.issue_key || '',
                        title: it.title || 'Issue',
                        description: it.description || '',
                        details: it.description || '',
                        status: it.status_name || 'open',
                        status_name: it.status_name || 'open',
                        status_id: it.status_id || null,
                        qa_status: Array.isArray(it.qa_status_keys) ? it.qa_status_keys : [],
                        qa_status_keys: Array.isArray(it.qa_status_keys) ? it.qa_status_keys : [],
                        qa_statuses: it.qa_statuses || [],
                        severity: it.severity || 'medium',
                        priority: it.priority || 'medium',
                        pages: Array.isArray(it.page_ids) ? it.page_ids : [],
                        page_ids: Array.isArray(it.page_ids) ? it.page_ids : [],
                        reporters: it.reporter_ids || [],
                        reporter_ids: it.reporter_ids || [],
                        reporter_names: it.reporters || '',
                        reporter_qa_status_map: it.reporter_qa_status_map || {},
                        assignee_ids: it.assignee_ids || [],
                        metadata: it.metadata || {},
                        common_title: it.common_title || it.title || '',
                        grouped_urls: Array.isArray(it.grouped_urls) ? it.grouped_urls : [],
                        updated_at: it.updated_at || null,
                        latest_history_id: (it.latest_history_id != null ? it.latest_history_id : 0)
                    };
                });

                if (preserveFilters) {
                    applyFilters({ keepPage: keepPage });
                } else {
                    filteredIssues = allIssues;
                    if (!keepPage) currentPage = 1;
                    renderIssues();
                }
            } else {
                throw new Error(data.message || 'Failed to load common issues');
            }
        })
        .catch(function(err) {
            if (!silentErrors && window.showError) window.showError('Failed to load common issues: ' + err.message);
            throw err;
        });
    }

    function applyFilters(options) {
        var opts = options || {};
        var searchTerm = document.getElementById('searchInput') ? document.getElementById('searchInput').value.toLowerCase() : '';
        var statusFilter = ($('#filterStatus').val() || []);
        var qaStatusFilterEl = document.getElementById('filterQAStatus');
        var qaStatusFilter = qaStatusFilterEl ? ($(qaStatusFilterEl).val() || []) : [];
        var reporterFilterEl = document.getElementById('filterReporter');
        var reporterFilter = reporterFilterEl ? ($(reporterFilterEl).val() || []) : [];

        filteredIssues = allIssues.filter(function(issue) {
            if (searchTerm) {
                var stripHtml = function(h) { return h ? String(h).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''; };
                var text = [issue.issue_key, issue.title, issue.status, issue.reporters, stripHtml(issue.description)].filter(Boolean).join(' ').toLowerCase();
                if (!text.includes(searchTerm)) return false;
            }
            if (statusFilter.length > 0 && !statusFilter.includes('')) {
                if (!statusFilter.includes(String(issue.status_id))) return false;
            }
            if (qaStatusFilter.length > 0 && !qaStatusFilter.includes('')) {
                if (!issue.qa_status_keys.some(function(q) { return qaStatusFilter.includes(String(q)); })) return false;
            }
            if (reporterFilter.length > 0 && !reporterFilter.includes('')) {
                if (!issue.reporter_ids.some(function(r) { return reporterFilter.includes(String(r)); })) return false;
            }
            return true;
        });

        if (!opts.keepPage) currentPage = 1;
        renderIssues();
    }

    function renderIssues() {
        var tbody = document.getElementById('commonIssuesBody');
        if (!tbody) return;

        if (filteredIssues.length === 0) {
            var userRole = window.ProjectConfig ? window.ProjectConfig.userRole : '';
            var colspan = (userRole === 'client') ? 3 : 5;
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-5"><p class="text-muted">No common issues found matching your filters.</p></td></tr>';
            renderPagination();
            return;
        }

        var pagedIssues = getPagedIssues();

        if (window.issueData) {
            window.issueData.common = pagedIssues;
            if (typeof window.renderCommonIssues === 'function') {
                window.renderCommonIssues();
            }
        }

        renderPagination();
    }

    function init() {
        if (window.ProjectConfig) {
            projectId = window.ProjectConfig.projectId || 0;
            baseDir   = window.ProjectConfig.baseDir || '';
        }

        // Initialize Select2
        if ($.fn.select2) {
            $('#filterStatus').select2({ placeholder: 'All Statuses', allowClear: true, width: '100%' });
            $('#filterQAStatus').select2({ placeholder: 'All QA Statuses', allowClear: true, width: '100%' });
            $('#filterReporter').select2({ placeholder: 'All Reporters', allowClear: true, width: '100%' });
        }

        ['searchInput', 'filterStatus', 'filterQAStatus', 'filterReporter'].forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            if (id.startsWith('filter')) {
                $(el).on('change', function() { applyFilters(); });
            } else {
                el.addEventListener('input', function() { applyFilters(); });
            }
        });

        var clearBtn = document.getElementById('clearFilters');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                var si = document.getElementById('searchInput'); if (si) si.value = '';
                $('#filterStatus').val([]).trigger('change');
                $('#filterQAStatus').val([]).trigger('change');
                $('#filterReporter').val([]).trigger('change');
                applyFilters();
            });
        }

        var refreshBtn = document.getElementById('commonIssuesRefreshBtn');
        if (refreshBtn) refreshBtn.addEventListener('click', function() { loadIssues({ preserveFilters: true, keepPage: true }); });

        var addIssueBtn = document.getElementById('addIssueBtn');
        if (addIssueBtn) {
            addIssueBtn.addEventListener('click', function() {
                if (window.addCommonIssue) window.addCommonIssue();
            });
        }

        var perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) perPageSelect.addEventListener('change', function() {
            perPage = parseInt(this.value) || 25;
            currentPage = 1;
            renderIssues();
        });

        document.addEventListener('pms:issues-changed', function(e) {
            loadIssues({ preserveFilters: true, keepPage: true, silentErrors: true });
        });

        window.loadCommonIssues = loadIssues;
        loadIssues({ immediate: true });
    }

    $(document).ready(function() {
        init();
    });

})(jQuery);
