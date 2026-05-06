/* Admin Device Permissions JS */

document.addEventListener('DOMContentLoaded', function () {

    // ── Confirm on save ──────────────────────────────────────────────────────
    document.querySelectorAll('form[data-confirm="device-perm"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var onConfirm = function () { form.submit(); };
            if (typeof confirmModal === 'function') {
                confirmModal('Save device permission changes for this user?', onConfirm);
            } else if (confirm('Save device permission changes for this user?')) {
                onConfirm();
            }
        });
    });

    // ── Pagination + Filter state ────────────────────────────────────────────
    var currentPage = 1;
    var perPage     = parseInt(document.getElementById('dpPerPage').value, 10);

    function getAllRows() {
        return Array.from(document.querySelectorAll('#dpTable tbody tr[data-name]'));
    }

    function getFilteredRows() {
        var search = (document.getElementById('dpSearch').value || '').toLowerCase().trim();
        var role   = (document.getElementById('dpFilterRole').value || '').toLowerCase();
        var status = (document.getElementById('dpFilterStatus').value || '').toLowerCase();
        var perm   = (document.getElementById('dpFilterPerm').value || '').toLowerCase();

        return getAllRows().filter(function (row) {
            var matchSearch = !search || row.dataset.name.includes(search);
            var matchRole   = !role   || row.dataset.role === role;
            var matchStatus = !status || row.dataset.status === status;
            var matchPerm   = !perm   || row.dataset.perm === perm;
            return matchSearch && matchRole && matchStatus && matchPerm;
        });
    }

    function renderPage() {
        var filtered = getFilteredRows();
        var total    = filtered.length;
        var totalPages = Math.max(1, Math.ceil(total / perPage));

        if (currentPage > totalPages) currentPage = totalPages;

        var start = (currentPage - 1) * perPage;
        var end   = start + perPage;

        // Hide all rows first
        getAllRows().forEach(function (row) { row.style.display = 'none'; });

        // Show only current page rows
        filtered.forEach(function (row, idx) {
            row.style.display = (idx >= start && idx < end) ? '' : 'none';
        });

        // Results info
        var infoEl = document.getElementById('dpResultsInfo');
        if (total === 0) {
            infoEl.textContent = 'No users found.';
        } else {
            var from = Math.min(start + 1, total);
            var to   = Math.min(end, total);
            infoEl.textContent = 'Showing ' + from + '–' + to + ' of ' + total + ' user' + (total !== 1 ? 's' : '');
        }

        // Pagination buttons
        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        var ul = document.getElementById('dpPagination');
        ul.innerHTML = '';

        function makeItem(label, page, disabled, active) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            var a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.innerHTML = label;
            if (!disabled && !active) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    currentPage = page;
                    renderPage();
                });
            }
            li.appendChild(a);
            ul.appendChild(li);
        }

        makeItem('&laquo;', currentPage - 1, currentPage === 1, false);

        // Show max 7 page buttons with ellipsis
        var pages = [];
        if (totalPages <= 7) {
            for (var i = 1; i <= totalPages; i++) pages.push(i);
        } else {
            pages = [1];
            if (currentPage > 3) pages.push('...');
            for (var p = Math.max(2, currentPage - 1); p <= Math.min(totalPages - 1, currentPage + 1); p++) {
                pages.push(p);
            }
            if (currentPage < totalPages - 2) pages.push('...');
            pages.push(totalPages);
        }

        pages.forEach(function (p) {
            if (p === '...') {
                makeItem('...', null, true, false);
            } else {
                makeItem(p, p, false, p === currentPage);
            }
        });

        makeItem('&raquo;', currentPage + 1, currentPage === totalPages, false);
    }

    // ── Event listeners ──────────────────────────────────────────────────────
    ['dpSearch', 'dpFilterRole', 'dpFilterStatus', 'dpFilterPerm'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function () { currentPage = 1; renderPage(); });
            el.addEventListener('change', function () { currentPage = 1; renderPage(); });
        }
    });

    document.getElementById('dpPerPage').addEventListener('change', function () {
        perPage = parseInt(this.value, 10);
        currentPage = 1;
        renderPage();
    });

    // ── Reset filters ────────────────────────────────────────────────────────
    window.dpResetFilters = function () {
        document.getElementById('dpSearch').value       = '';
        document.getElementById('dpFilterRole').value   = '';
        document.getElementById('dpFilterStatus').value = '';
        document.getElementById('dpFilterPerm').value   = '';
        currentPage = 1;
        renderPage();
    };

    // ── Initial render ───────────────────────────────────────────────────────
    renderPage();
});
