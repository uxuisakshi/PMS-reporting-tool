/**
 * issues-page-detail.js
 * Specialized script for modules/projects/issues_page_detail.php
 * Scoped to a single page's issues.
 * Synchronizes with window.issueData.pages[pageId].final for view_issues.js compatibility.
 * delegating table rendering to window.renderFinalIssues().
 */

(function($) {
    "use strict";

    var projectId = 0;
    var baseDir   = '';
    var pageId    = 0;
    
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
                        var table = document.getElementById('finalIssuesTable');
                        if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        });
    }

    function loadIssues(id, options) {
        var opts = options || {};
        if (id) pageId = id;
        
        var preserveFilters = !!opts.preserveFilters;
        var keepPage        = !!opts.keepPage;
        var silentErrors    = !!opts.silentErrors;
        var immediate       = !!opts.immediate;

        if (loadIssuesDebounceTimer) { clearTimeout(loadIssuesDebounceTimer); loadIssuesDebounceTimer = null; }
        if (immediate) return performLoadIssues(preserveFilters, keepPage, silentErrors);

        return new Promise(function(resolve) {
            loadIssuesDebounceTimer = setTimeout(function() {
                performLoadIssues(preserveFilters, keepPage, silentErrors).then(resolve);
            }, 300);
        });
    }

    function performLoadIssues(preserveFilters, keepPage, silentErrors) {
        if (!pageId) return Promise.resolve();

        var tbody = document.getElementById('finalIssuesBody');
        if (tbody && !preserveFilters) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading issues...</p></td></tr>';
        }

        var url = baseDir + '/api/issues.php?action=list&project_id=' + encodeURIComponent(projectId) + '&page_id=' + encodeURIComponent(pageId);

        return fetch(url, { credentials: 'same-origin' })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                allIssues = (data.issues || []).map(function(it) {
                    return {
                        id: String(it.id),
                        issue_key: it.issue_key || '',
                        title: it.title || 'Issue',
                        details: it.description || '',
                        status: it.status || 'open',
                        status_id: it.status_id || null,
                        qa_status: Array.isArray(it.qa_status) ? it.qa_status : (it.qa_status ? [it.qa_status] : []),
                        severity: it.severity || 'medium',
                        priority: it.priority || 'medium',
                        pages: Array.isArray(it.pages) ? it.pages : (it.pages ? String(it.pages).split(',').filter(Boolean) : []),
                        grouped_urls: it.grouped_urls || [],
                        reporter_name: it.reporter_name || null,
                        qa_name: it.qa_name || null,
                        assignee_id: it.assignee_id || null,
                        assignee_ids: Array.isArray(it.assignee_ids) && it.assignee_ids.length ? it.assignee_ids.map(String) : (it.assignee_id ? [String(it.assignee_id)] : []),
                        page_id: it.page_id || pageId,
                        client_ready: it.client_ready || 0,
                        environments: it.environments || [],
                        usersaffected: it.usersaffected || [],
                        wcagsuccesscriteria: it.wcagsuccesscriteria || [],
                        wcagsuccesscriterianame: it.wcagsuccesscriterianame || [],
                        wcagsuccesscriterialevel: it.wcagsuccesscriterialevel || [],
                        gigw30: it.gigw30 || [],
                        is17802: it.is17802 || [],
                        common_title: it.common_title || '',
                        reporters: it.reporters || [],
                        reporter_qa_status_map: it.reporter_qa_status_map || {},
                        has_comments: !!it.has_comments,
                        can_tester_delete: (it.can_tester_delete !== false),
                        created_at: it.created_at || null,
                        updated_at: it.updated_at || null,
                        latest_history_id: (it.latest_history_id != null ? Number(it.latest_history_id) : 0)
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
                throw new Error(data.message || 'Failed to load issues');
            }
        })
        .catch(function(err) {
            if (!silentErrors && window.showError) window.showError('Failed to load issues: ' + err.message);
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
                var text = [issue.issue_key, issue.title, issue.status, issue.reporter_name, stripHtml(issue.details)].filter(Boolean).join(' ').toLowerCase();
                if (!text.includes(searchTerm)) return false;
            }
            if (statusFilter.length > 0 && !statusFilter.includes('')) {
                if (!statusFilter.includes(String(issue.status_id))) return false;
            }
            if (qaStatusFilter.length > 0 && !qaStatusFilter.includes('')) {
                var currentQAs = Array.isArray(issue.qa_status) ? issue.qa_status : (issue.qa_status ? [issue.qa_status] : []);
                if (!currentQAs.some(function(q) { return qaStatusFilter.includes(String(q)); })) return false;
            }
            if (reporterFilter.length > 0 && !reporterFilter.includes('')) {
                var reporters = Array.isArray(issue.reporters) ? issue.reporters : (issue.reporter_id ? [issue.reporter_id] : []);
                if (!reporters.some(function(r) { return reporterFilter.includes(String(r)); })) return false;
            }
            return true;
        });

        if (!opts.keepPage) {
            currentPage = 1;
        }
        renderIssues();
    }

    function renderIssues() {
        var tbody = document.getElementById('finalIssuesBody');
        if (!tbody) return;

        if (filteredIssues.length === 0) {
            var userRole = window.ProjectConfig ? window.ProjectConfig.userRole : '';
            var colspan = (userRole === 'client') ? 5 : 10;
            tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-5"><p class="text-muted">No issues found matching your filters.</p></td></tr>';
            renderPagination();
            return;
        }

        var pagedIssues = getPagedIssues();

        if (window.issueData && window.issueData.pages) {
            if (!window.issueData.pages[pageId]) window.issueData.pages[pageId] = {};
            window.issueData.selectedPageId = pageId;
            window.issueData.pages[pageId].final = pagedIssues;

            if (typeof window.renderFinalIssues === 'function') {
                window.renderFinalIssues();
                var badge = document.getElementById('finalIssuesCountBadge');
                if (badge) badge.textContent = String(filteredIssues.length);
            }
        }

        renderPagination();
    }

    function init() {
        if (window.ProjectConfig) {
            projectId = window.ProjectConfig.projectId || 0;
            baseDir   = window.ProjectConfig.baseDir || '';
            pageId    = window.ProjectConfig.pageId || 0;
        }

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

        var refreshBtn = document.getElementById('refreshBtn');
        if (refreshBtn) refreshBtn.addEventListener('click', function() { loadIssues(pageId, { preserveFilters: true }); });

        var perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) perPageSelect.addEventListener('change', function() {
            perPage = parseInt(this.value) || 25;
            currentPage = 1;
            renderIssues();
        });

        document.addEventListener('pms:issues-changed', function(e) {
            loadIssues(pageId, { preserveFilters: true, keepPage: true });
        });

        window.loadFinalIssues = loadIssues;
        loadIssues(pageId, { immediate: true });
    }

    $(document).ready(function() {
        init();
    });

})(jQuery);
