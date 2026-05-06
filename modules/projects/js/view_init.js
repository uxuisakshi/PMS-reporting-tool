/**
 * view_init.js - Project view page: tab init, sub-tab handling, phase/page status, project status
 * Depends on window.ProjectConfig being set before this script loads.
 */
(function () {
    var cfg = window.ProjectConfig || {};
    var projectId = cfg.projectId || 0;
    var baseDir = cfg.baseDir || '';

    // --- Tab restore on page load ---
    (function () {
        var allowedTabs = { '#phases': true, '#pages': true, '#team': true, '#performance': true, '#assets': true, '#activity': true, '#feedback': true, '#production-hours': true };
        var target = '#phases';
        try {
            var params = new URLSearchParams(window.location.search || '');
            var qTab = (params.get('tab') || '').trim();
            if (qTab && allowedTabs['#' + qTab]) {
                target = '#' + qTab;
            } else {
                var stored = localStorage.getItem('pms_project_tab_' + projectId);
                if (stored && allowedTabs[stored]) target = stored;
            }
        } catch (e) {}
        if (target === '#phases') return;
        var allBtns = document.querySelectorAll('#projectTabs .nav-link');
        var allPanes = document.querySelectorAll('#projectTabsContent > .tab-pane');
        allBtns.forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
        allPanes.forEach(function (p) { p.classList.remove('show', 'active'); });
        var btn = document.querySelector('#projectTabs .nav-link[data-bs-target="' + target + '"]');
        var pane = document.querySelector(target);
        if (btn) { btn.classList.add('active'); btn.setAttribute('aria-selected', 'true'); }
        if (pane) pane.classList.add('show', 'active');
    })();

    // --- DOMContentLoaded handlers ---
    document.addEventListener('DOMContentLoaded', function () {
        clearAllFiltersOnLoad();

        var pagesSubTabs = document.querySelectorAll('#pagesSubTabs .nav-link');
        var pagesTabPanes = document.querySelectorAll('#pages_main, #project_pages_sub, #all_urls_sub');

        function hideAllPanes() {
            pagesTabPanes.forEach(function (pane) {
                pane.classList.remove('show', 'active');
                pane.style.display = 'none';
                pane.style.height = '0';
                pane.style.overflow = 'hidden';
                pane.style.opacity = '0';
                pane.style.visibility = 'hidden';
            });
        }

        function showPane(pane) {
            pane.classList.add('show', 'active');
            pane.style.display = 'block';
            pane.style.height = 'auto';
            pane.style.overflow = 'visible';
            pane.style.opacity = '1';
            pane.style.visibility = 'visible';
        }

        function activateTab(tabId, paneId) {
            pagesSubTabs.forEach(function (t) { t.classList.remove('active'); });
            hideAllPanes();
            var tab = document.querySelector(tabId);
            if (tab) tab.classList.add('active');
            var pane = document.querySelector(paneId);
            if (pane) showPane(pane);
        }

        hideAllPanes();
        var activeTabPane = '#project_pages_sub';
        var activeTabBtn = '#project-sub-tab';

        if (window.location.hash) {
            var hash = window.location.hash;
            if (hash === '#all_urls_sub' || hash === '#allurls-sub-tab') {
                activeTabPane = '#all_urls_sub'; activeTabBtn = '#allurls-sub-tab';
            } else if (hash === '#project_pages_sub' || hash === '#project-sub-tab') {
                activeTabPane = '#project_pages_sub'; activeTabBtn = '#project-sub-tab';
            }
        } else {
            var lastActiveTab = localStorage.getItem('pagesSubTab_' + projectId);
            if (lastActiveTab === 'all_urls') { activeTabPane = '#all_urls_sub'; activeTabBtn = '#allurls-sub-tab'; }
            else if (lastActiveTab === 'project_pages') { activeTabPane = '#project_pages_sub'; activeTabBtn = '#project-sub-tab'; }
        }

        activateTab(activeTabBtn, activeTabPane);

        pagesSubTabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                var targetId = this.getAttribute('data-bs-target');
                var tabId = '#' + this.id;
                if (targetId === '#all_urls_sub') {
                    localStorage.setItem('pagesSubTab_' + projectId, 'all_urls');
                    window.location.hash = 'all_urls_sub';
                } else if (targetId === '#project_pages_sub') {
                    localStorage.setItem('pagesSubTab_' + projectId, 'project_pages');
                    window.location.hash = 'project_pages_sub';
                }
                activateTab(tabId, targetId);
            });
        });

        // Column resizing
        function initColumnResize() {
            var tables = document.querySelectorAll('#uniquePagesTable, #allUrlsTable, #issuesPageList table.resizable-table');
            if (!tables.length) return;
            tables.forEach(function (table) {
                var resizers = table.querySelectorAll('.col-resizer');
                var isResizing = false, currentResizer = null, startX = 0, startWidth = 0, selectedResizer = null;
                resizers.forEach(function (resizer, index) {
                    resizer.setAttribute('tabindex', '0');
                    resizer.setAttribute('role', 'button');
                    resizer.setAttribute('aria-label', 'Resize column ' + (index + 1) + '. Use arrow keys to resize.');
                    resizer.addEventListener('mousedown', function (e) {
                        isResizing = true; currentResizer = resizer;
                        startX = e.clientX;
                        startWidth = parseInt(window.getComputedStyle(resizer.parentElement).width, 10);
                        resizer.classList.add('resizing');
                        document.body.style.cursor = 'col-resize';
                        document.body.style.userSelect = 'none';
                        e.preventDefault();
                    });
                    resizer.addEventListener('keydown', function (e) {
                        var th = this.parentElement;
                        var currentWidth = parseInt(window.getComputedStyle(th).width, 10);
                        var newWidth = currentWidth;
                        if (e.key === 'ArrowLeft') newWidth = Math.max(50, currentWidth - 10);
                        else if (e.key === 'ArrowRight') newWidth = currentWidth + 10;
                        else if (e.key === 'ArrowUp') newWidth = Math.max(50, currentWidth - 5);
                        else if (e.key === 'ArrowDown') newWidth = currentWidth + 5;
                        else if (e.key === 'Home') newWidth = 50;
                        else if (e.key === 'End') newWidth = 300;
                        else return;
                        if (newWidth !== currentWidth) th.style.width = newWidth + 'px';
                        e.preventDefault();
                    });
                    resizer.addEventListener('focus', function () { this.classList.add('focused'); });
                    resizer.addEventListener('blur', function () { this.classList.remove('focused', 'selected'); if (selectedResizer === this) selectedResizer = null; });
                });
                document.addEventListener('mousemove', function (e) {
                    if (!isResizing || !currentResizer) return;
                    var newWidth = Math.max(50, startWidth + (e.clientX - startX));
                    currentResizer.parentElement.style.width = newWidth + 'px';
                    e.preventDefault();
                });
                document.addEventListener('mouseup', function () {
                    if (isResizing && currentResizer) {
                        currentResizer.classList.remove('resizing');
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        isResizing = false; currentResizer = null;
                    }
                });
            });
        }

        function initTableTooltips() {
            var tables = document.querySelectorAll('#uniquePagesTable, #allUrlsTable');
            if (!tables.length) return;
            tables.forEach(function (table) {
                var cells = table.querySelectorAll('td:not(.dropdown-cell)');
                cells.forEach(function (cell) {
                    if (cell.scrollWidth > cell.clientWidth) {
                        var fullText = cell.textContent.trim();
                        if (fullText.length > 30) { cell.setAttribute('title', fullText); cell.style.cursor = 'help'; }
                    }
                });
                window.addEventListener('resize', function () {
                    setTimeout(function () {
                        cells.forEach(function (cell) {
                            if (cell.scrollWidth > cell.clientWidth) {
                                var fullText = cell.textContent.trim();
                                if (fullText.length > 30) { cell.setAttribute('title', fullText); cell.style.cursor = 'help'; }
                            } else { cell.removeAttribute('title'); cell.style.cursor = ''; }
                        });
                    }, 100);
                });
            });
        }

        function clearAllFiltersOnLoad() {
            ['uniqueFilter', 'uniqueFilterUser', 'uniqueFilterEnv', 'uniqueFilterQa',
             'allUrlsFilter', 'allUrlsUniqueFilter', 'allUrlsMappingFilter'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
        }

        setTimeout(function () { initColumnResize(); initTableTooltips(); }, 500);

        // Focus assign button after redirect
        var urlParams = new URLSearchParams(window.location.search);
        var focusAssignBtn = urlParams.get('focus_assign_btn');
        if (focusAssignBtn) {
            var pagesTab = document.querySelector('#pages-tab');
            var uniquePagesSubTab = document.querySelector('#project-sub-tab');
            if (pagesTab && uniquePagesSubTab) {
                new bootstrap.Tab(pagesTab).show();
                setTimeout(function () {
                    new bootstrap.Tab(uniquePagesSubTab).show();
                    setTimeout(function () {
                        var assignBtn = document.querySelector('button[data-bs-target="#assignPageModal-' + focusAssignBtn + '"]');
                        if (assignBtn) {
                            assignBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            assignBtn.focus();
                            assignBtn.classList.add('btn-warning');
                            setTimeout(function () { assignBtn.classList.remove('btn-warning'); assignBtn.classList.add('btn-outline-primary'); }, 2000);
                        }
                    }, 300);
                }, 300);
            }
            urlParams.delete('focus_assign_btn');
            var newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '') + window.location.hash;
            window.history.replaceState({}, '', newUrl);
        }

        // Phase status updates
        $('.phase-status-update').on('change', function () {
            var phaseId = $(this).data('phase-id');
            var projId = $(this).data('project-id');
            var newStatus = $(this).val();
            var $select = $(this);
            $.ajax({
                url: baseDir + '/api/update_phase.php', type: 'POST',
                data: { phase_id: phaseId, project_id: projId, field: 'status', value: newStatus },
                success: function (response) {
                    if (response.success) {
                        var $row = $select.closest('tr');
                        $row.addClass('table-success');
                        setTimeout(function () { $row.removeClass('table-success'); }, 2000);
                        if (typeof showToast === 'function') showToast('Phase status updated', 'success');
                    } else {
                        if (typeof showToast === 'function') showToast('Failed to update phase status: ' + (response.message || 'Unknown error'), 'danger');
                        $select.val($select.data('original-value') || 'not_started');
                    }
                },
                error: function (xhr, status, error) {
                    if (typeof showToast === 'function') showToast('Error updating phase status: ' + error, 'danger');
                    $select.val($select.data('original-value') || 'not_started');
                }
            });
        });
        $('.phase-status-update').each(function () { $(this).data('original-value', $(this).val()); });

        // Page status updates
        $('.page-status-update').on('change', function () {
            var pageId = $(this).data('page-id');
            var projId = $(this).data('project-id');
            var newStatus = $(this).val();
            var $select = $(this);
            $.ajax({
                url: baseDir + '/api/update_page_status.php', type: 'POST',
                data: { page_id: pageId, project_id: projId, status: newStatus },
                success: function (response) {
                    if (response.success) {
                        var $row = $select.closest('tr');
                        $row.addClass('table-success');
                        setTimeout(function () { $row.removeClass('table-success'); }, 2000);
                        if (typeof showToast === 'function') showToast('Page status updated', 'success');
                    } else {
                        if (typeof showToast === 'function') showToast('Failed to update page status: ' + (response.message || 'Unknown error'), 'danger');
                        $select.val($select.data('original-value') || 'not_started');
                    }
                },
                error: function (xhr, status, error) {
                    if (typeof showToast === 'function') showToast('Error updating page status: ' + error, 'danger');
                    $select.val($select.data('original-value') || 'not_started');
                }
            });
        });

        // Edit Phase modal
        $('.edit-phase-btn').on('click', function () {
            $('#edit_phase_id').val($(this).data('phase-id'));
            $('#edit_phase_name').val(String($(this).data('phase-name')).replace(/_/g, ' ').replace(/\b\w/g, function (l) { return l.toUpperCase(); }));
            $('#edit_start_date').val($(this).data('start-date') || '');
            $('#edit_end_date').val($(this).data('end-date') || '');
            $('#edit_planned_hours').val($(this).data('planned-hours') || '');
            $('#edit_status').val($(this).data('status') || 'not_started');
        });

        $('#edit_start_date, #edit_end_date').on('change', function () {
            var startDate = $('#edit_start_date').val();
            var endDate = $('#edit_end_date').val();
            if (startDate && endDate && new Date(endDate) < new Date(startDate)) {
                if (typeof showToast === 'function') showToast('End date cannot be before start date', 'danger');
                if ($(this).attr('id') === 'edit_end_date') $('#edit_end_date').val('');
                else $('#edit_start_date').val('');
            }
        });
        $('#edit_start_date').on('change', function () {
            var v = $(this).val();
            if (v) $('#edit_end_date').attr('min', v); else $('#edit_end_date').removeAttr('min');
        });

        // Asset type toggle
        $('input[name="asset_type"]').on('change', function () {
            var assetType = $(this).val();
            $('#link_fields, #file_fields, #text_fields').hide();
            $('#main_url').prop('required', false);
            $('#asset_file').prop('required', false);
            if (assetType === 'link') { $('#link_fields').show(); $('#main_url').prop('required', true); }
            else if (assetType === 'file') { $('#file_fields').show(); $('#asset_file').prop('required', true); }
            else if (assetType === 'text') {
                $('#text_fields').show();
                if (!$('#text_content_editor').data('summernote')) {
                    $('#text_content_editor').summernote({ height: 200, toolbar: [['style', ['style']], ['font', ['bold', 'italic', 'underline', 'clear']], ['para', ['ul', 'ol', 'paragraph']], ['insert', ['link']]] });
                }
            }
        });

        // Page toggle btn
        $('.page-toggle-btn').on('click', function () {
            var $icon = $(this).find('.toggle-icon');
            var $collapse = $($(this).data('bs-target'));
            $collapse.on('show.bs.collapse', function () { $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up'); });
            $collapse.on('hide.bs.collapse', function () { $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down'); });
        });

        // Production hours tab init
        setTimeout(function () {
            var productionHoursPane = document.getElementById('production-hours');
            if (productionHoursPane && productionHoursPane.classList.contains('active')) {
                if (typeof window.initProductionHours === 'function') window.initProductionHours();
            }
        }, 500);
    });

    // --- Project status update ---
    $(document).ready(function () {
        $('#projectStatusDropdown').on('change', function () {
            var projId = $(this).data('project-id');
            var newStatus = $(this).val();
            var $dropdown = $(this);
            if (!$dropdown.data('original-status')) $dropdown.data('original-status', newStatus);
            if (!confirm('Are you sure you want to change the project status?')) {
                $dropdown.val($dropdown.data('original-status')); return;
            }
            $dropdown.prop('disabled', true);
            $.ajax({
                url: baseDir + '/api/update_project_status.php', type: 'POST',
                data: { project_id: projId, status: newStatus }, dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        $dropdown.data('original-status', newStatus);
                        if (typeof showToast === 'function') showToast('Project status updated successfully!', 'success');
                    } else {
                        $dropdown.val($dropdown.data('original-status'));
                        if (typeof showToast === 'function') showToast(response.message || 'Failed to update project status', 'danger');
                    }
                },
                error: function (xhr, status, error) {
                    $dropdown.val($dropdown.data('original-status'));
                    if (typeof showToast === 'function') showToast('Error updating project status: ' + error, 'danger');
                },
                complete: function () { $dropdown.prop('disabled', false); }
            });
        });
    });

    function clearAllFiltersOnLoad() {
        ['uniqueFilter', 'uniqueFilterUser', 'uniqueFilterEnv', 'uniqueFilterQa',
         'allUrlsFilter', 'allUrlsUniqueFilter', 'allUrlsMappingFilter'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
    }
})();
