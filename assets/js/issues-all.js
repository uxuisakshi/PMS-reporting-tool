/**
 * issues-all.js
 * Extracted from modules/projects/issues_all.php inline script (line ~360)
 * Requires: window.ProjectConfig to be set before this file loads
 */

var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
var allIssues = [];
var filteredIssues = [];
var loadIssuesDebounceTimer = null;
var currentPage = 1;
var perPage = 50;

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

    // Update both top and bottom pagination bars
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

        // Smart page numbers - show max 5 around current to avoid horizontal scroll
        var pages = [];
        if (totalPages <= 5) {
            for (var i = 1; i <= totalPages; i++) pages.push(i);
        } else {
            pages = [1];
            if (currentPage > 3) pages.push('...');
            var rangeStart = Math.max(2, currentPage - 1);
            var rangeEnd = Math.min(totalPages - 1, currentPage + 1);
            // Adjust range to always show 3 numbers in middle
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

        // Next
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
                    var table = document.getElementById('issuesTable');
                    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    });
}

function getIssuesAllSelectedIds() {
    return Array.from(document.querySelectorAll('.issues-all-select:checked')).map(function (checkbox) {
        return String(checkbox.value || '');
    }).filter(Boolean);
}

function updateIssuesAllSelectionState() {
    var markBtn = document.getElementById('allIssuesMarkClientReadyBtn');
    var selectAll = document.getElementById('issuesSelectAll');
    // Only consider visible (current page) checkboxes
    var checkboxes = Array.from(document.querySelectorAll('#issuesTableBody .issues-all-select'));
    var checked = checkboxes.filter(function (checkbox) { return checkbox.checked; });

    if (markBtn) {
        markBtn.disabled = checked.length === 0;
    }

    if (selectAll) {
        selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }
}

function escapeAttr(text) {
    return String(text == null ? '' : text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function normalizeIssueImageSrc(src) {
    var rawSrc = String(src || '').trim();
    if (!rawSrc) return rawSrc;

    try {
        var parsed = new URL(rawSrc, window.location.origin);
        var pathname = parsed.pathname || '';
        var prefixMatch = pathname.match(/^\/(?:(PMS(?:-UAT)?)\/)?((?:uploads\/|assets\/uploads\/|api\/public_image\.php|api\/secure_file\.php).*)$/i);
        if (!prefixMatch) {
            return rawSrc;
        }

        var normalizedBaseDir = String(baseDir || '').replace(/\/+$/, '');
        var normalizedTarget = '/' + String(prefixMatch[2] || '').replace(/^\/+/, '');
        if (/^\/(?:assets\/uploads|uploads)\/(issues|chat)\//i.test(normalizedTarget)) {
            var relativePath = normalizedTarget.replace(/^\//, '');
            return (normalizedBaseDir ? normalizedBaseDir : '')
                + '/api/secure_file.php?path='
                + encodeURIComponent(relativePath)
                + (parsed.hash || '');
        }

        var normalizedPath = (normalizedBaseDir ? normalizedBaseDir : '') + normalizedTarget;
        if (!normalizedPath) normalizedPath = normalizedTarget;
        if (normalizedPath.charAt(0) !== '/') normalizedPath = '/' + normalizedPath;

        if (/^(?:https?:)?\/\//i.test(rawSrc)) {
            parsed.pathname = normalizedPath;
            return parsed.toString();
        }

        return normalizedPath + (parsed.search || '') + (parsed.hash || '');
    } catch (e) {
        return rawSrc;
    }
}

function tryRecoverIssueImageElement(img) {
    if (!img || img.dataset.issueImageRecoveryAttempted === '1') return false;
    var currentSrc = img.getAttribute('src') || img.src || '';
    var normalizedSrc = normalizeIssueImageSrc(currentSrc);
    if (!normalizedSrc || normalizedSrc === currentSrc) return false;
    img.dataset.issueImageRecoveryAttempted = '1';
    img.setAttribute('src', normalizedSrc);
    return true;
}

// Fallback decorateIssueImages if not loaded from view_issues.js
function decorateIssueImages(html) {
    if (!html) return '';
    return String(html).replace(/<img\b([^>]*)>/gi, function (_, attrs) {
        var newAttrs = attrs;
        newAttrs = newAttrs.replace(/\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i, function (match, dq, sq, bare) {
            var currentSrc = dq || sq || bare || '';
            return 'src="' + escapeAttr(normalizeIssueImageSrc(currentSrc)) + '"';
        });
        if (/class\s*=/.test(attrs)) {
            newAttrs = attrs.replace(/class\s*=(["\'])([^"\']*)\1/, 'class="$2 issue-image-thumb"');
        } else {
            newAttrs = 'class="issue-image-thumb" ' + attrs;
        }
        if (!/loading\s*=/.test(newAttrs)) newAttrs += ' loading="lazy"';
        if (!/style\s*=/.test(newAttrs)) newAttrs += ' style="max-width: 100%; height: auto; cursor: pointer;"';
        return '<img ' + newAttrs + '>';
    });
}

// Fallback openIssueImageModal
function openIssueImageModal(src) {
    var modal = document.getElementById('issueImageModal');
    var previewImg = document.getElementById('issueImagePreview');
    if (modal && previewImg) {
        previewImg.src = normalizeIssueImageSrc(src);
        previewImg.onerror = function () {
            if (tryRecoverIssueImageElement(this)) {
                return;
            }
            this.alt = 'Failed to load image: ' + src;
            this.style.border = '2px solid #dc3545';
            this.style.padding = '20px';
            this.style.backgroundColor = '#f8d7da';
        };
        previewImg.onload = function () {
            this.style.border = '';
            this.style.padding = '';
            this.style.backgroundColor = '';
        };
        new bootstrap.Modal(modal).show();
    } else {
        window.open(src, '_blank');
    }
}

// Load all issues with debouncing
function loadIssues(options) {
    var opts = options || {};
    var preserveFilters = !!opts.preserveFilters;
    var keepPage        = !!opts.keepPage;
    var silentErrors    = !!opts.silentErrors;
    var immediate       = !!opts.immediate;

    if (loadIssuesDebounceTimer) {
        clearTimeout(loadIssuesDebounceTimer);
        loadIssuesDebounceTimer = null;
    }

    var doLoad = function() {
        return performLoadIssues(preserveFilters, silentErrors, 0, keepPage);
    };

    if (immediate) return doLoad();

    return new Promise(function (resolve) {
        loadIssuesDebounceTimer = setTimeout(function () {
            doLoad().then(resolve);
        }, 300);
    });
}

function performLoadIssues(preserveFilters, silentErrors, retryCount, keepPage) {
    retryCount = retryCount || 0;
    var maxRetries = 3;
    var controller = new AbortController();
    var timeoutId  = setTimeout(function () { controller.abort(); }, 10000);

    return fetch(baseDir + '/api/issues.php?action=get_all&project_id=' + projectId, {
        signal: controller.signal,
        headers: { 'Cache-Control': 'no-cache' }
    })
    .then(function (response) {
        clearTimeout(timeoutId);
        if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        return response.text().then(function(text) {
            return JSON.parse(text.replace(/^\uFEFF/, ''));
        });
    })
    .then(function (data) {
        if (data.success) {
            // Natural sort by issue_key (e.g. MuthootOne-1, MuthootOne-2, ... MuthootOne-10)
            allIssues = (data.issues || []).sort(function(a, b) {
                var ka = String(a.issue_key || '');
                var kb = String(b.issue_key || '');
                // Split prefix and numeric suffix: "MuthootOne-10" -> ["MuthootOne", 10]
                var ma = ka.match(/^(.*?)(\d+)$/);
                var mb = kb.match(/^(.*?)(\d+)$/);
                if (ma && mb) {
                    if (ma[1] !== mb[1]) return ma[1].localeCompare(mb[1]);
                    return parseInt(ma[2], 10) - parseInt(mb[2], 10);
                }
                return ka.localeCompare(kb);
            });
            if (preserveFilters) { 
                applyFilters({ keepPage: keepPage }); 
            } else { 
                filteredIssues = allIssues; 
                if (!keepPage) currentPage = 1;
                updateCounts(); 
                renderIssues(); 
            }
        } else {
            throw new Error(data.message || 'Failed to load issues');
        }
    })
    .catch(function (error) {
        clearTimeout(timeoutId);
        if (retryCount < maxRetries && (error.name === 'AbortError' || error.message.includes('Failed to fetch') || error.message.includes('CONNECTION_RESET'))) {
            return new Promise(function (resolve) {
                setTimeout(function () { performLoadIssues(preserveFilters, true, retryCount + 1).then(resolve); }, Math.pow(2, retryCount) * 1000);
            });
        }
        if (!silentErrors) showError('Failed to load issues: ' + error.message);
        throw error;
    });
}

// Render issues table
function renderIssues() {
    var tbody    = document.getElementById('issuesTableBody');
    var userRole = window.ProjectConfig ? window.ProjectConfig.userRole : '';
    var isClient = (userRole === 'client');
    var colspan  = isClient ? 5 : 8;

    if (filteredIssues.length === 0) {
        tbody.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center py-5"><i class="fas fa-inbox fa-3x text-muted mb-3"></i><p class="text-muted">No issues found</p></td></tr>';
        renderPagination();
        return;
    }

    var pagedIssues = getPagedIssues();

    tbody.innerHTML = pagedIssues.map(function (issue) {
        var mainRow = '<tr class="issue-row" data-issue-id="' + issue.id + '" style="cursor: pointer;">';

        if (!isClient) {
            mainRow += '<td class="text-center checkbox-cell" style="width: 40px; min-width: 40px; overflow: visible !important;"><input type="checkbox" class="issues-all-select" value="' + escapeAttr(issue.id) + '" aria-label="Select issue ' + escapeAttr(issue.issue_key || issue.id) + '"></td>';
        }

        // Truncate pages display - show max 2 pages, rest in tooltip
        var pagesHtml = '<span class="text-muted">No pages</span>';
        if (issue.pages) {
            var pagesList = issue.pages.split(', ');
            if (pagesList.length <= 2) {
                pagesHtml = escapeHtml(issue.pages);
            } else {
                var shown = pagesList.slice(0, 2).join(', ');
                var rest = pagesList.slice(2).join(', ');
                pagesHtml = escapeHtml(shown) +
                    ' <span class="badge bg-secondary" title="' + escapeAttr(rest) + '" style="cursor:help;">+' + (pagesList.length - 2) + ' more</span>';
            }
        }

        var descPreview = '';
        var rawDesc = issue.description || '';
        if (rawDesc) {
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = rawDesc;
            var plainDesc = (tempDiv.textContent || tempDiv.innerText || '').trim();
            if (plainDesc.length > 0) {
                descPreview = plainDesc.substring(0, 100) + (plainDesc.length > 100 ? '...' : '');
            }
        }

        mainRow +=
            '<td><button class="btn btn-link p-0 me-2 text-muted chevron-toggle" style="border:none;background:none;"><i class="fas fa-chevron-right chevron-icon" id="chevron-' + issue.id + '"></i></button>' +
            '<span class="badge bg-primary">' + escapeHtml(issue.issue_key) + '</span></td>' +
            '<td>' + (issue.common_title ? '<div class="text-truncate-cell" title="' + escapeAttr(issue.common_title) + '">' + escapeHtml(issue.common_title) + '</div><small class="text-muted text-truncate-cell" title="' + escapeAttr(issue.title) + '">' + escapeHtml(issue.title) + '</small>' : '<div class="text-truncate-cell fw-bold" title="' + escapeAttr(issue.title) + '">' + escapeHtml(issue.title) + '</div>' + (descPreview ? '<div class="small text-muted text-truncate-cell" title="' + escapeAttr(descPreview) + '">' + escapeHtml(descPreview) + '</div>' : '')) + '</td>' +
            '<td><small>' + pagesHtml + '</small></td>' +
            '<td><span class="status-badge" style="background-color:' + issue.status_color + ';color:white;">' + escapeHtml(issue.status_name) + '</span></td>';

        if (!isClient) {
            var qaHtml = (issue.qa_statuses && issue.qa_statuses.length > 0)
                ? issue.qa_statuses.map(function (qs) {
                    var bg = getBootstrapColor(qs.color || 'secondary');
                    return '<span class="qa-status-badge" style="background-color:' + bg + '!important;color:' + getContrastColor(bg) + '!important;">' + escapeHtml(qs.label) + '</span>';
                }).join(' ')
                : '<span class="text-muted">-</span>';
            mainRow += '<td>' + getClientReadyBadge(issue.client_ready) + '</td>' +
                '<td>' + qaHtml + '</td>' +
                '<td><small>' + (issue.reporters ? escapeHtml(issue.reporters) : '<span class="text-muted">-</span>') + '</small></td>' +
                '<td><button class="btn btn-sm btn-outline-primary edit-btn me-1" data-issue-id="' + issue.id + '" title="Edit"><i class="fas fa-edit"></i></button>' +
                '<button class="btn btn-sm btn-outline-danger delete-btn" data-issue-id="' + issue.id + '" title="Delete"><i class="fas fa-trash"></i></button></td>';
        } else {
            mainRow += '<td><button class="btn btn-sm btn-outline-primary issue-open" data-issue-id="' + issue.id + '" title="Update status or add comment"><i class="fas fa-pen-to-square me-1"></i>Update</button></td>';
        }

        mainRow += '</tr><tr id="issue-details-' + issue.id + '" style="display:none;"><td colspan="' + colspan + '" class="p-0">' +
            '<div class="bg-light p-4 border-top"><div class="row">' +
            '<div class="col-md-8"><h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Issue Details</h6>' +
            '<div class="card"><div class="card-body issue-content">' + (decorateIssueImages(issue.description || '') || '<p class="text-muted">No details provided.</p>') + '</div></div></div>' +
            '<div class="col-md-4"><h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2"></i>Metadata</h6><div class="card"><div class="card-body">' +
            (issue.common_title ? '<div class="mb-2"><strong>Common Title:</strong><br>' + escapeHtml(issue.common_title) + '</div>' : '') +
            '<div class="mb-2"><strong>Issue Key:</strong><br><span class="badge bg-primary">' + escapeHtml(issue.issue_key) + '</span></div>' +
            '<div class="mb-2"><strong>Status:</strong><br><span class="status-badge" style="background-color:' + issue.status_color + ';color:white;">' + escapeHtml(issue.status_name) + '</span></div>' +
            '<div class="mb-2"><strong>Severity:</strong><br><span class="badge bg-warning text-dark">' + escapeHtml((issue.severity || 'N/A').toUpperCase()) + '</span></div>' +
            '<div class="mb-2"><strong>Priority:</strong><br><span class="badge bg-info text-dark">' + escapeHtml((issue.priority || 'N/A').toUpperCase()) + '</span></div>';

        if (!isClient) {
            var qaMetaHtml = (issue.qa_statuses && issue.qa_statuses.length > 0)
                ? issue.qa_statuses.map(function (qs) {
                    var bg = getBootstrapColor(qs.color || 'secondary');
                    return '<span class="qa-status-badge" style="background-color:' + bg + '!important;color:' + getContrastColor(bg) + '!important;">' + escapeHtml(qs.label) + '</span>';
                }).join(' ')
                : '<span class="text-muted">N/A</span>';
            mainRow += '<div class="mb-2"><strong>Client Ready:</strong><br>' + getClientReadyBadge(issue.client_ready) + '</div>' +
                '<div class="mb-2"><strong>QA Status:</strong><br>' + qaMetaHtml + '</div>' +
                '<div class="mb-2"><strong>Reporter(s):</strong><br>' + (issue.reporters ? escapeHtml(issue.reporters) : '<span class="text-muted">N/A</span>') + '</div>';
        }

        // Pages - bullet list with scrollable container
        if (issue.pages) {
            var pagesList = issue.pages.split(', ');
            mainRow += '<div class="mb-2"><strong>Page(s):</strong> <span class="badge bg-secondary ms-1">' + pagesList.length + '</span>';
            mainRow += '<div class="mt-1 border rounded bg-white p-2" style="max-height:120px;overflow-y:auto;">';
            mainRow += '<ul class="list-unstyled mb-0 small">';
            pagesList.forEach(function(p) {
                mainRow += '<li><i class="fas fa-file-alt text-muted me-1"></i>' + escapeHtml(p.trim()) + '</li>';
            });
            mainRow += '</ul></div></div>';
        } else {
            mainRow += '<div class="mb-2"><strong>Page(s):</strong><br><span class="text-muted">No pages</span></div>';
        }

        if (issue.grouped_urls && issue.grouped_urls.length > 0) {
            mainRow += '<div class="mb-2"><strong>Grouped URLs:</strong> <span class="badge bg-info ms-1">' + issue.grouped_urls.length + '</span>' +
                '<button class="btn btn-link p-0 ms-1 text-primary" style="font-size:12px;text-decoration:none;" onclick="toggleGroupedUrls(' + issue.id + ',event)"><small>Show/Hide</small></button>' +
                '<div id="grouped-urls-content-' + issue.id + '" style="display:none;margin-top:4px;">' +
                '<div class="border rounded bg-white p-2" style="max-height:120px;overflow-y:auto;">' +
                '<ul class="list-unstyled mb-0 small">' +
                issue.grouped_urls.map(function (u) {
                    return '<li class="mb-1 text-break"><a href="' + escapeHtml(u) + '" target="_blank" class="text-decoration-none"><i class="fas fa-link me-1 text-primary"></i>' + escapeHtml(u) + '</a></li>';
                }).join('') +
                '</ul></div></div></div>';
        }

        if (window.ProjectConfig && window.ProjectConfig.metadataFields) {
            window.ProjectConfig.metadataFields.forEach(function (field) {
                // Skip fields already hardcoded in main issue details or rendered above
                if (['severity', 'priority', 'status', 'issue_status', 'issue_key'].indexOf(field.field_key) !== -1) return;
                // Check metadata object first, then direct issue property
                var value = (issue.metadata && issue.metadata[field.field_key] !== undefined)
                    ? issue.metadata[field.field_key]
                    : issue[field.field_key];
                if (value && value.length > 0) {
                    var displayValue = Array.isArray(value) ? value.join(', ') : value;
                    mainRow += '<div class="mb-2"><strong>' + escapeHtml(field.field_label) + ':</strong><br>' + escapeHtml(displayValue) + '</div>';
                }
            });
        }

        if (!isClient) {
            mainRow += '<div class="mb-2"><strong>Created:</strong><br><small class="text-muted">' + new Date(issue.created_at).toLocaleString() + '</small></div>' +
                '<div class="mb-2"><strong>Updated:</strong><br><small class="text-muted">' + new Date(issue.updated_at).toLocaleString() + '</small></div>';
        }

        mainRow += '</div></div></div></div></div></td></tr>';
        return mainRow;
    }).join('');

    attachEventListeners();
    updateIssuesAllSelectionState();
    renderPagination();

    document.dispatchEvent(new CustomEvent('pms:issueTableUpdated'));
}

function updateCounts() {
    // totalCount and filteredCount elements removed - info shown in pagination
}

function applyFilters(options) {
    var opts = options || {};
    var searchTerm    = document.getElementById('searchInput').value.toLowerCase();
    var pageFilter    = ($('#filterPage').val()   || []);
    var statusFilter  = ($('#filterStatus').val() || []);
    var qaStatusFilterEl  = document.getElementById('filterQAStatus');
    var qaStatusFilter    = qaStatusFilterEl ? ($(qaStatusFilterEl).val() || []) : [];
    var reporterFilterEl  = document.getElementById('filterReporter');
    var reporterFilter    = reporterFilterEl ? ($(reporterFilterEl).val() || []) : [];

    filteredIssues = allIssues.filter(function (issue) {
        if (searchTerm) {
            var stripHtml = function (h) { return h ? String(h).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''; };
            var qaLabels  = Array.isArray(issue.qa_statuses) ? issue.qa_statuses.map(function (qs) { return String(qs.label || ''); }).join(' ') : String(issue.qa_statuses || '');
            var commonTitle = Array.isArray(issue.common_title) ? issue.common_title.join(' ') : String(issue.common_title || '');
            var text = [issue.issue_key, issue.title, commonTitle, issue.pages, issue.status_name, qaLabels, issue.reporters, stripHtml(issue.description)].filter(Boolean).join(' ').toLowerCase();
            if (!text.includes(searchTerm)) return false;
        }
        if (pageFilter.length > 0 && !pageFilter.includes('')) {
            if (!issue.page_ids || !pageFilter.some(function (pid) { return issue.page_ids.includes(parseInt(pid)); })) return false;
        }
        if (statusFilter.length > 0 && !statusFilter.includes('')) {
            if (!statusFilter.includes(String(issue.status_id))) return false;
        }
        if (qaStatusFilter.length > 0 && !qaStatusFilter.includes('')) {
            if (!issue.qa_status_keys || !qaStatusFilter.some(function (qas) { return issue.qa_status_keys.includes(qas); })) return false;
        }
        if (reporterFilter.length > 0 && !reporterFilter.includes('')) {
            if (!issue.reporter_ids || !reporterFilter.some(function (rid) { return issue.reporter_ids.includes(parseInt(rid)); })) return false;
        }
        return true;
    });
    if (!opts.keepPage) {
        currentPage = 1; // reset to first page on filter change
    }
    updateCounts();
    renderIssues();
}

function attachEventListeners() {
    document.querySelectorAll('.issues-all-select').forEach(function (checkbox) {
        checkbox.addEventListener('click', function (e) { e.stopPropagation(); });
        checkbox.addEventListener('change', updateIssuesAllSelectionState);
    });
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); editIssue(this.dataset.issueId); });
    });
    document.querySelectorAll('.issue-open').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); editIssue(this.dataset.issueId); });
    });
    document.querySelectorAll('.delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); deleteIssue(this.dataset.issueId); });
    });
    document.querySelectorAll('.issue-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.edit-btn') || e.target.closest('.delete-btn') || e.target.closest('.issues-all-select')) return;
            var issueId    = this.dataset.issueId;
            var detailsRow = document.getElementById('issue-details-' + issueId);
            var chevron    = document.getElementById('chevron-' + issueId);
            if (!detailsRow) return;
            if (detailsRow.style.display === 'none' || !detailsRow.style.display) {
                detailsRow.style.display = 'table-row';
                if (chevron) { chevron.classList.remove('fa-chevron-right'); chevron.classList.add('fa-chevron-down'); }
                setTimeout(attachImageHandlers, 100);
            } else {
                detailsRow.style.display = 'none';
                if (chevron) { chevron.classList.remove('fa-chevron-down'); chevron.classList.add('fa-chevron-right'); }
            }
        });
    });
    attachImageHandlers();
}

function attachImageHandlers() {
    var images = document.querySelectorAll('#issuesTableBody img, #issuesTableBody .issue-image-thumb');
    var imageIndex = 0;
    var batchSize  = 10;
    function processBatch() {
        Array.from(images).slice(imageIndex, imageIndex + batchSize).forEach(function (img) {
            img.style.cursor = 'pointer';
            if (img._imageClickHandler) img.removeEventListener('click', img._imageClickHandler);
            img._imageClickHandler = function (e) {
                e.stopPropagation(); e.preventDefault();
                var src = this.getAttribute('src');
                if (src && typeof openIssueImageModal === 'function') openIssueImageModal(src);
                else if (src) window.open(src, '_blank');
            };
            img.addEventListener('click', img._imageClickHandler);
            if (!img.hasAttribute('loading')) img.setAttribute('loading', 'lazy');
            if (!img._errorHandlerAttached) {
                img.onerror = function () {
                    if (tryRecoverIssueImageElement(this)) {
                        return;
                    }
                    this.style.border = '2px solid #dc3545';
                    this.style.backgroundColor = '#f8d7da';
                    this.title = 'Image failed to load: ' + this.src;
                    this.alt = 'Failed to load image';
                };
                img.onload  = function () { this.style.border = ''; this.style.backgroundColor = ''; this.title = 'Click to view full size'; };
                img._errorHandlerAttached = true;
            }
        });
        imageIndex += batchSize;
        if (imageIndex < images.length) setTimeout(processBatch, 50);
    }
    if (images.length > 0) processBatch();
}

function editIssue(issueId) {
    var issueData = allIssues.find(function (i) { return i.id == issueId; });
    if (!issueData) { showError('Issue not found. ID: ' + issueId); return; }

    function getValidProjectPageIds() {
        if (!window.ProjectConfig || !Array.isArray(window.ProjectConfig.projectPages)) return [];
        return window.ProjectConfig.projectPages.map(function (page) { return String(page.id); });
    }

    function normalizeProjectPageIds(pageIds) {
        var validPageIds = getValidProjectPageIds();
        return (Array.isArray(pageIds) ? pageIds : []).map(function (pageId) {
            return String(pageId || '').trim();
        }).filter(function (pageId, index, list) {
            return pageId && validPageIds.indexOf(pageId) !== -1 && list.indexOf(pageId) === index;
        });
    }

    var issue = {
        id: issueData.id, issue_key: issueData.issue_key, title: issueData.title,
        details: issueData.description, common_title: issueData.common_title || '',
        status_id: issueData.status_id, status: issueData.status_name,
        pages: normalizeProjectPageIds(issueData.page_ids || []), grouped_urls: Array.isArray(issueData.grouped_urls) ? issueData.grouped_urls : [],
        reporters: issueData.reporter_ids || [], qa_status: issueData.qa_status_keys || [],
        severity: issueData.severity || 'medium', priority: issueData.priority || 'medium',
        updated_at: issueData.updated_at || null,
        latest_history_id: issueData.latest_history_id != null ? issueData.latest_history_id : 0,
        reporter_qa_status_map: (function () {
            var raw = issueData.reporter_qa_status_map;
            if (Array.isArray(raw)) {
                for (var i = 0; i < raw.length; i++) {
                    try { var p = (typeof raw[i] === 'string') ? JSON.parse(raw[i]) : raw[i]; if (p && typeof p === 'object' && !Array.isArray(p)) return p; } catch (e) {}
                }
                return {};
            }
            if (typeof raw === 'string') { try { return JSON.parse(raw); } catch (e) { return {}; } }
            return (raw && typeof raw === 'object') ? raw : {};
        })(),
        assignee_ids: Array.isArray(issueData.assignee_ids) && issueData.assignee_ids.length
            ? issueData.assignee_ids.map(String)
            : (issueData.assignee_id ? [String(issueData.assignee_id)] : [])
    };

    if (issueData.metadata) {
        Object.keys(issueData.metadata).forEach(function (key) {
            if (!['common_title','severity','priority','grouped_urls','page_ids','qa_status','reporter_ids'].includes(key)) issue[key] = issueData.metadata[key];
        });
    }

    if (window.issueData) {
        if (issue.pages && issue.pages.length > 0) window.issueData.selectedPageId = issue.pages[0];
        else if (window.ProjectConfig && window.ProjectConfig.projectPages && window.ProjectConfig.projectPages.length > 0) window.issueData.selectedPageId = window.ProjectConfig.projectPages[0].id;
    }

    if (typeof openFinalEditor === 'function') openFinalEditor(issue);
    else showError('Issue editor not loaded. Please refresh the page.');
}

function deleteIssue(issueId) {
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        var existing = document.getElementById('deleteConfirmModal');
        if (existing) existing.remove();
        document.body.insertAdjacentHTML('beforeend',
            '<div class="modal fade" id="deleteConfirmModal" tabindex="-1">' +
            '<div class="modal-dialog"><div class="modal-content">' +
            '<div class="modal-header bg-danger text-white"><h5 class="modal-title">Confirm Delete</h5>' +
            '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>' +
            '<div class="modal-body"><p>Are you sure you want to delete this issue?</p><p class="text-muted mb-0"><small>This action cannot be undone.</small></p></div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
            '<button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button></div>' +
            '</div></div></div>');
        var modalEl = document.getElementById('deleteConfirmModal');
        var modal   = new bootstrap.Modal(modalEl);
        modal.show();
        document.getElementById('confirmDeleteBtn').addEventListener('click', function () { modal.hide(); performDelete(issueId); });
        modalEl.addEventListener('hidden.bs.modal', function () { modalEl.remove(); });
    } else {
        if (!window.confirm('Are you sure you want to delete this issue?')) return;
        performDelete(issueId);
    }
}

function performDelete(issueId) {
    var fd = new FormData();
    fd.append('action', 'delete'); fd.append('ids', String(issueId)); fd.append('project_id', projectId);
    fetch(baseDir + '/api/issues.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { showSuccess('Issue deleted successfully'); loadIssues({ preserveFilters: true, silentErrors: true }); }
            else showError(data.message || data.error || 'Failed to delete issue');
        })
        .catch(function () { showError('Error deleting issue'); });
}

function escapeHtml(text) { var d = document.createElement('div'); d.textContent = text; return d.innerHTML; }

function toggleGroupedUrls(issueId, event) {
    if (event) event.stopPropagation();
    var content = document.getElementById('grouped-urls-content-' + issueId);
    if (!content) return;
    if (content.style.display === 'none' || !content.style.display) {
        content.style.display = 'block';
    } else {
        content.style.display = 'none';
    }
}

function getContrastColor(hexColor) {
    var hex = hexColor.replace('#', '');
    var r = parseInt(hex.substr(0,2),16), g = parseInt(hex.substr(2,2),16), b = parseInt(hex.substr(4,2),16);
    return ((0.299*r + 0.587*g + 0.114*b)/255) > 0.5 ? '#000000' : '#ffffff';
}

function getBootstrapColor(colorName) {
    var map = { primary:'#0d6efd', secondary:'#6c757d', success:'#198754', danger:'#dc3545', warning:'#ffc107', info:'#0dcaf0', light:'#f8f9fa', dark:'#212529' };
    if (colorName && colorName.startsWith('#')) return colorName;
    return map[colorName] || map['secondary'];
}

function getClientReadyBadge(clientReady) {
    if (clientReady == 1 || clientReady === '1' || clientReady === true) {
        return '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Yes</span>';
    }
    return '<span class="badge bg-secondary"><i class="fas fa-times me-1"></i>No</span>';
}

function showSuccess(msg) { if (typeof showToast === 'function') showToast(msg, 'success'); }
function showError(msg)   { if (typeof showToast === 'function') showToast(msg, 'danger'); }

// ── Event listeners ──
document.getElementById('searchInput').addEventListener('input', applyFilters);
$('#filterPage').on('change', applyFilters);
$('#filterStatus').on('change', applyFilters);
var _qaEl = document.getElementById('filterQAStatus');
if (_qaEl) $(_qaEl).on('change', applyFilters);
var _repEl = document.getElementById('filterReporter');
if (_repEl) $(_repEl).on('change', applyFilters);

document.getElementById('clearFilters').addEventListener('click', function () {
    document.getElementById('searchInput').value = '';
    $('#filterPage').val([]).trigger('change');
    $('#filterStatus').val([]).trigger('change');
    if (_qaEl)  $(_qaEl).val([]).trigger('change');
    if (_repEl) $(_repEl).val([]).trigger('change');
    applyFilters();
});

document.getElementById('refreshBtn').addEventListener('click', function () { loadIssues({ preserveFilters: true }); });

var _issuesSelectAll = document.getElementById('issuesSelectAll');
if (_issuesSelectAll) {
    _issuesSelectAll.addEventListener('click', function (e) { e.stopPropagation(); });
    _issuesSelectAll.addEventListener('change', function () {
        var isChecked = !!this.checked;
        // Only select/deselect current page's visible checkboxes
        document.querySelectorAll('#issuesTableBody .issues-all-select').forEach(function (checkbox) {
            checkbox.checked = isChecked;
        });
        updateIssuesAllSelectionState();
    });
}

// Per-page dropdown
var _perPageSelect = document.getElementById('perPageSelect');
if (_perPageSelect) {
    _perPageSelect.addEventListener('change', function () {
        perPage = parseInt(this.value) || 50;
        currentPage = 1;
        renderIssues();
    });
}

var _markClientReadyBtn = document.getElementById('allIssuesMarkClientReadyBtn');
if (_markClientReadyBtn) {
    _markClientReadyBtn.addEventListener('click', function () {
        var selectedIds = getIssuesAllSelectedIds();
        if (!selectedIds.length) return;

        var proceed = function () {
            fetch(baseDir + '/api/issues.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=bulk_client_ready&issue_ids=' + encodeURIComponent(selectedIds.join(',')) + '&client_ready=1&project_id=' + encodeURIComponent(projectId)
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    throw new Error(data.message || data.error || 'Failed to mark issues as Client Ready');
                }

                showSuccess((data.updated || selectedIds.length) + ' issue(s) marked as Client Ready');
                if (_issuesSelectAll) {
                    _issuesSelectAll.checked = false;
                    _issuesSelectAll.indeterminate = false;
                }
                loadIssues({ preserveFilters: true, silentErrors: true, immediate: true });
            })
            .catch(function (error) {
                showError(error && error.message ? error.message : 'Failed to mark issues as Client Ready');
            });
        };

        var confirmMessage = 'Mark ' + selectedIds.length + ' issue(s) as Client Ready?';
        if (typeof confirmModal === 'function') {
            confirmModal(confirmMessage, proceed);
        } else if (window.confirm(confirmMessage)) {
            proceed();
        }
    });
}

document.addEventListener('pms:issues-changed', function (e) {
    var detail  = e.detail || {};
    var action  = detail.action || '';
    var issueId = String(detail.issue_id || '');
    if (detail.source === 'internal' && action === 'delete' && issueId) {
        allIssues = allIssues.filter(function (i) { return String(i.id) !== issueId; });
        applyFilters();
        return;
    }
    loadIssues({ preserveFilters: true, keepPage: true, silentErrors: true });
});

// ── Initialization ──
function initializeAllIssuesPage() {
    $('#filterPage').select2({ placeholder: 'All Pages', allowClear: true, width: '100%' });
    $('#filterStatus').select2({ placeholder: 'All Statuses', allowClear: true, width: '100%' });
    if (_qaEl)  $(_qaEl).select2({ placeholder: 'All QA Statuses', allowClear: true, width: '100%' });
    if (_repEl) $(_repEl).select2({ placeholder: 'All Reporters', allowClear: true, width: '100%' });

    if (typeof decorateIssueImages === 'function') loadIssues({ immediate: true });
    else setTimeout(initializeAllIssuesPage, 100);
}
initializeAllIssuesPage();

// Handle expand parameter from URL
document.addEventListener('DOMContentLoaded', function () {
    var expandIssueId = new URLSearchParams(window.location.search).get('expand');
    if (!expandIssueId) return;
    var checkAndExpand = function () {
        if (allIssues.length > 0) {
            var issueRow = document.querySelector('[data-issue-id="' + expandIssueId + '"]');
            if (issueRow) {
                issueRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(function () {
                    issueRow.click();
                    issueRow.style.backgroundColor = '#fff3cd';
                    setTimeout(function () { issueRow.style.backgroundColor = ''; }, 3000);
                }, 500);
            } else {
                document.getElementById('clearFilters').click();
                setTimeout(checkAndExpand, 1000);
            }
        } else {
            setTimeout(checkAndExpand, 500);
        }
    };
    checkAndExpand();
});

document.addEventListener('DOMContentLoaded', function () { setTimeout(attachImageHandlers, 500); });

// Add Issue button
var _addIssueBtn = document.getElementById('addIssueBtn');
if (_addIssueBtn) {
    _addIssueBtn.addEventListener('click', function () {
        if (window.issueData && window.ProjectConfig && window.ProjectConfig.projectPages && window.ProjectConfig.projectPages.length > 0) {
            window.issueData.selectedPageId = window.ProjectConfig.projectPages[0].id;
        }
        if (typeof openFinalEditor === 'function') openFinalEditor(null);
        else showError('Issue editor not loaded. Please refresh the page.');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.ProjectConfig && !window.ProjectConfig.canUpdateIssueQaStatus) {
        jQuery('#finalIssueQaStatus').prop('disabled', true).trigger('change.select2');
        jQuery('#finalIssueQaStatus').attr('title', 'Only authorized users can update QA status.');
    }
    setTimeout(function () {
        var resetBtn = document.getElementById('btnResetToTemplate');
        if (!resetBtn) return;
        var newResetBtn = resetBtn.cloneNode(true);
        resetBtn.parentNode.replaceChild(newResetBtn, resetBtn);
        newResetBtn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            var currentContent = jQuery('#finalIssueDetails').summernote('code');
            var plainText = String(currentContent || '').replace(/<[^>]*>/g, '').trim();
            if (plainText && !confirm('This will replace the current content with the default template. Continue?')) return;
            var projectType = window.ProjectConfig ? (window.ProjectConfig.projectType || 'web') : 'web';
            fetch(baseDir + '/api/issue_templates.php?action=list&project_type=' + projectType, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var sections = data.default_sections || [];
                    if (!sections.length) { showError('No default template sections configured for this project type.'); return; }
                    var html = sections.map(function (s) {
                        var esc = String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        return '<p><strong>[' + esc + ']</strong></p><p><br></p><p><br></p>';
                    }).join('');
                    jQuery('#finalIssueDetails').summernote('code', html);
                    if (window.showToast) showToast('Template sections loaded', 'success');
                })
                .catch(function () { showError('Failed to load template sections. Please try again.'); });
        });
    }, 500);
});
