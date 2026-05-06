/**
 * tab-pages.js
 * Extracted from modules/projects/partials/tab_pages.php inline scripts
 * Requires window.ProjectConfig.projectId, window.ProjectConfig.baseDir
 * and window._tabPagesConfig.projectTitle for export
 */
(function () {
    // ── Export Pages to Excel ──
    var exportBtn = document.getElementById('exportPagesBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            if (typeof XLSX === 'undefined') {
                alert('Excel library not loaded. Please refresh the page.');
                return;
            }

            var projectTitle = (window._tabPagesConfig && window._tabPagesConfig.projectTitle)
                ? window._tabPagesConfig.projectTitle
                : 'Project';

            var wb = XLSX.utils.book_new();

            var pagesRows = [['Page No.', 'Page Name', 'Unique URL', 'Grouped URLs', 'FT Tester', 'AT Tester', 'QA', 'Page Status', 'Notes']];
            document.querySelectorAll('#uniquePagesTable tbody tr[id^="unique-row-"]').forEach(function (tr) {
                var cells = tr.querySelectorAll('td');
                if (cells.length < 10) return;
                var pageNo     = cells[1].textContent.trim();
                var pageName   = (cells[2].querySelector('.page-name-display') || cells[2]).textContent.trim();
                var uniqueUrl  = cells[3].textContent.trim();
                var groupedList = cells[4].querySelectorAll('.grouped-url-item');
                var groupedUrls = [];
                groupedList.forEach(function (el) { groupedUrls.push(el.textContent.trim()); });
                var ftText     = cells[5].textContent.replace(/\s+/g, ' ').trim();
                var atText     = cells[6].textContent.replace(/\s+/g, ' ').trim();
                var qaText     = cells[7].textContent.replace(/\s+/g, ' ').trim();
                var statusText = cells[8].textContent.trim();
                var notesText  = (cells[9].querySelector('.notes-display') || cells[9]).textContent.trim();
                pagesRows.push([pageNo, pageName, uniqueUrl, groupedUrls.join('\n'), ftText, atText, qaText, statusText, notesText]);
            });
            var wsPages = XLSX.utils.aoa_to_sheet(pagesRows);
            wsPages['!cols'] = [{wch:12},{wch:30},{wch:40},{wch:50},{wch:25},{wch:25},{wch:25},{wch:20},{wch:30}];
            XLSX.utils.book_append_sheet(wb, wsPages, 'Project Pages');

            var urlRows = [['URL', 'Unique Page No.']];
            document.querySelectorAll('#allUrlsTable tbody tr[id^="grouped-row-"]').forEach(function (tr) {
                var cells = tr.querySelectorAll('td');
                if (cells.length < 5) return;
                var url = cells[1].textContent.trim();
                var uniqueSel = cells[2].querySelector('select');
                var uniquePage = uniqueSel
                    ? (uniqueSel.options[uniqueSel.selectedIndex] ? uniqueSel.options[uniqueSel.selectedIndex].text : '')
                    : cells[2].textContent.trim();
                urlRows.push([url, uniquePage]);
            });
            var wsUrls = XLSX.utils.aoa_to_sheet(urlRows);
            wsUrls['!cols'] = [{wch:60},{wch:30}];
            XLSX.utils.book_append_sheet(wb, wsUrls, 'All URLs');

            var safeTitle = projectTitle.replace(/[^a-zA-Z0-9_\- ]/g, '').trim() || 'Project';
            XLSX.writeFile(wb, safeTitle + ' - Pages & URLs.xlsx');
        });
    }

    // ── Export Client Report ──
    var clientReportBtn = document.getElementById('exportClientReportBtn');
    if (clientReportBtn) {
        clientReportBtn.addEventListener('click', function () {
            var baseDir   = window.ProjectConfig ? window.ProjectConfig.baseDir   : '';
            var projectId = window.ProjectConfig ? window.ProjectConfig.projectId : 0;
            var csrfToken = window._csrfToken || '';
            var exportUrl = baseDir + '/api/export_client_report.php?project_id=' + encodeURIComponent(projectId) + '&format=excel&client_ready_only=1';

            if (csrfToken !== '') {
                exportUrl += '&csrf_token=' + encodeURIComponent(csrfToken);
            }

            clientReportBtn.disabled = true;
            clientReportBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Preparing...';
            window.location.href = exportUrl;
            setTimeout(function () {
                clientReportBtn.disabled = false;
                clientReportBtn.innerHTML = '<i class="fas fa-file-excel me-1"></i> Export Client Report';
            }, 3000);
        });
    }
}());
