/**
 * issue_navigation.js
 * Implements "Label & Zap" (Alt+E) and "Arrow & Edit" (Arrow keys) navigation for issues.
 */

window.IssueNavigation = (function() {
    var config = {
        rowSelector: '.issue-row, .issue-expandable-row',
        editBtnSelector: '.edit-btn, .final-edit, .common-edit, .issue-open',
        activeRowClass: 'issue-nav-active',
        badgeClass: 'issue-nav-badge'
    };

    var state = {
        zapModeTarget: 'none', // 'none', 'edit', 'expand'
        activeIndex: -1,
        badges: [],
        navigationShortcutsActive: false
    };

    var ZAP_KEYS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

    function init(customConfig) {
        if (customConfig) {
            Object.assign(config, customConfig);
        }
        injectStyles();
        document.addEventListener('keydown', handleGlobalKeydown);
        // Listen for table updates to reposition badges
        document.addEventListener('pms:issueTableUpdated', function() {
            if (state.zapModeTarget !== 'none') {
                refreshZapBadges();
            }
        });
        // Listen for window resize
        window.addEventListener('resize', function() {
            if (state.zapModeTarget !== 'none') {
                repositionBadges();
            }
        });
        // Clear active row or Zap mode if user clicks elsewhere
        document.addEventListener('click', function(e) {
            if (state.zapModeTarget !== 'none') {
                // In expand mode, clicking a row is intentional — don't dismiss badges
                // Only dismiss if clicking completely outside the table rows
                var clickedRow = e.target.closest(config.rowSelector);
                var isEditMode = (state.zapModeTarget === 'edit');
                if (isEditMode || !clickedRow) {
                    clearZapMode();
                }
            }
            if (!e.target.closest(config.rowSelector)) {
                clearActiveRow();
                deactivateShortcuts();
            }
        });

        // Wire shortcuts button and column resizer after DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', afterDOMReady);
        } else {
            afterDOMReady();
        }
    }

    function afterDOMReady() {
        setTimeout(function() {
            initColumnResize();
        }, 300);
        var btn = document.getElementById('kbShortcutsBtn');
        if (btn) {
            btn.addEventListener('click', function() { showShortcutsModal(); });
        }
    }

    function injectStyles() {
        if (document.getElementById('issue-nav-styles')) return;
        var style = document.createElement('style');
        style.id = 'issue-nav-styles';
        style.textContent = `
            .${config.activeRowClass} {
                background-color: rgba(13, 110, 253, 0.12) !important;
                outline: 2px solid #0d6efd !important;
                outline-offset: -2px;
                box-shadow: inset 0 0 0 9999px rgba(13, 110, 253, 0.08) !important;
            }
            .${config.badgeClass} {
                position: absolute;
                background: #0d6efd;
                color: white;
                font-size: 11px;
                font-weight: bold;
                padding: 2px 6px;
                border-radius: 4px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.3);
                z-index: 10000;
                transform: translate(-50%, -50%);
                pointer-events: none;
                text-transform: uppercase;
                /* Fix truncation "..." artifacts */
                white-space: nowrap !important;
                overflow: visible !important;
                text-overflow: clip !important;
                display: flex;
                align-items: center;
                justify-content: center;
                line-height: 1;
            }
            .${config.badgeClass}.badge-expand {
                background: #198754; /* Green for expand */
            }
            .col-resizer {
                position: absolute;
                right: 0;
                top: 0;
                height: 100%;
                width: 6px;
                cursor: col-resize;
                background: transparent;
                user-select: none;
                border-right: 2px solid rgba(100,116,139,0.25);
                z-index: 1;
            }
            .col-resizer:hover, .col-resizer.resizing {
                background: rgba(13,110,253,0.2);
                border-right-color: #0d6efd;
            }
            /* Prevent expanded detail rows from widening table columns */
            .fixed-issue-table td[colspan] {
                overflow: hidden;
                white-space: normal;
                padding: 0 !important;
                max-width: 0; /* Force respect for table-layout: fixed */
            }
            .fixed-issue-table td[colspan] > div {
                width: 100%;
                box-sizing: border-box;
                min-width: 0;
                overflow: hidden;
                word-break: break-word;
                overflow-wrap: anywhere;
            }
            .fixed-issue-table td[colspan] img,
            .fixed-issue-table td[colspan] iframe {
                max-width: 100% !important;
                height: auto !important;
            }
            .fixed-issue-table td[colspan] pre,
            .fixed-issue-table td[colspan] code {
                max-width: 100% !important;
                overflow-x: auto !important;
                display: block !important;
                word-break: normal !important;
                white-space: pre !important;
            }
        `;
        document.head.appendChild(style);
    }
    function handleGlobalKeydown(e) {
        // Alt + J or Alt + K: Activate shortcuts and move
        if (e.altKey && (e.key.toLowerCase() === 'j' || e.key.toLowerCase() === 'k')) {
            e.preventDefault();
            if (!state.navigationShortcutsActive) {
                state.navigationShortcutsActive = true;
                if (window.showToast) window.showToast('Navigation shortcuts enabled (J/K)', 'success');
            }
            handleArrowKeydown(e);
            return;
        }

        // Alt + E toggles Zap Mode for Edit
        if (e.altKey && e.code === 'KeyE') {
            e.preventDefault();
            scrollToTable();
            toggleZapMode('edit');
            return;
        }

        // Alt + O toggles Zap Mode for Expand
        if (e.altKey && e.code === 'KeyO') {
            e.preventDefault();
            scrollToTable();
            toggleZapMode('expand');
            return;
        }

        // Alt + A: Add Issue
        if (e.altKey && e.code === 'KeyA') {
            var addBtn = document.getElementById('addIssueBtn') || 
                         document.getElementById('issueAddFinalBtn') || 
                         document.getElementById('commonAddBtn');
            if (addBtn && !addBtn.disabled && addBtn.offsetWidth > 0) {
                e.preventDefault();
                addBtn.click();
                return;
            }
        }

        // Alt + S: Save Issue (only if modal is open)
        if (e.altKey && e.code === 'KeyS') {
            var saveBtn = document.getElementById('finalIssueSaveBtn') || 
                          document.getElementById('commonIssueSaveBtn');
            if (saveBtn && !saveBtn.disabled && saveBtn.offsetParent !== null) {
                e.preventDefault();
                saveBtn.click();
                return;
            }
        }

        // Deactivation: Escape
        if (e.key === 'Escape' && state.navigationShortcutsActive) {
            deactivateShortcuts();
            // Don't return, might want to clear other things
        }

        if (state.zapModeTarget !== 'none') {
            handleZapKeydown(e);
            return;
        }

        // Standard Arrow Navigation
        handleArrowKeydown(e);
    }

    function deactivateShortcuts() {
        if (!state.navigationShortcutsActive) return;
        state.navigationShortcutsActive = false;
        clearActiveRow();
        if (window.showToast) window.showToast('Navigation shortcuts disabled', 'info');
    }

    function scrollToTable() {
        var headerNav = document.querySelector('header .navbar.sticky-top');
        var headerHeight = headerNav ? headerNav.offsetHeight : 70;

        // Check if any actual data rows are visible below the sticky header
        var dataRows = document.querySelectorAll(config.rowSelector);
        var anyRowVisible = false;
        for (var i = 0; i < dataRows.length; i++) {
            var r = dataRows[i].getBoundingClientRect();
            if (r.top < window.innerHeight && r.bottom > headerHeight + 10) {
                anyRowVisible = true;
                break;
            }
        }

        if (!anyRowVisible && dataRows.length > 0) {
            // Scroll so first data row is just below the sticky header
            var firstRect = dataRows[0].getBoundingClientRect();
            var scrollTarget = window.pageYOffset + firstRect.top - headerHeight - 10;
            window.scrollTo({ top: scrollTarget, behavior: 'instant' });
        }
    }

    function toggleZapMode(mode) {
        if (state.zapModeTarget === mode) {
            clearZapMode();
        } else {
            enterZapMode(mode);
        }
    }

    function enterZapMode(mode) {
        clearZapMode();
        state.zapModeTarget = mode;
        
        var headerNav = document.querySelector('header .navbar.sticky-top');
        var headerHeight = headerNav ? headerNav.offsetHeight : 70;
        
        var selector = (mode === 'edit') ? config.editBtnSelector : config.rowSelector;
        var elements = Array.from(document.querySelectorAll(selector))
            .filter(el => {
                var rect = el.getBoundingClientRect();
                return el.offsetWidth > 0 && el.offsetHeight > 0 && rect.top >= headerHeight;
            });

        elements.forEach((el, index) => {
            if (index >= ZAP_KEYS.length) return;
            var key = ZAP_KEYS[index];
            var rect = el.getBoundingClientRect();
            
            var badge = document.createElement('div');
            badge.className = config.badgeClass;
            if (mode === 'expand') {
                badge.classList.add('badge-expand');
            }
            badge.textContent = key;
            
            if (mode === 'edit') {
                badge.style.top = (window.scrollY + rect.top) + 'px';
                badge.style.left = (window.scrollX + rect.left) + 'px';
            } else {
                // For expand (rows), position on the left side of the row vertically centered
                badge.style.top = (window.scrollY + rect.top + (rect.height / 2)) + 'px';
                badge.style.left = (window.scrollX + rect.left + 25) + 'px';
            }
            
            badge.style.zIndex = '10001'; // Higher than most headers
            
            document.body.appendChild(badge);
            state.badges.push({ key: key, el: el, element: badge });
        });

        if (state.badges.length === 0) {
            state.zapModeTarget = 'none';
        }
    }

    function handleZapKeydown(e) {
        if (e.key === 'Escape') {
            clearZapMode();
            return;
        }

        var key = e.key.toUpperCase();
        var match = state.badges.find(b => b.key === key);
        if (match) {
            e.preventDefault();
            match.el.click();
            
            // Only clear Zap mode for Edit, allow multiple toggles for Expand
            if (state.zapModeTarget !== 'expand') {
                clearZapMode();
            } else {
                // Scroll the toggled row into view if it shifted
                scrollRowToVisible(match.el);
                // Reposition badges after row expands/collapses (DOM shifts)
                setTimeout(repositionBadges, 220);
            }
        }
    }

    function scrollRowToVisible(row) {
        if (!row) return;
        // Wait for potential animation or layout shift
        setTimeout(function() {
            var headerNav = document.querySelector('header .navbar.sticky-top');
            var headerHeight = headerNav ? headerNav.offsetHeight : 70;
            var rect = row.getBoundingClientRect();
            
            // Check if expansion is likely (height > 100 usually means it has content or was just toggled)
            // Or just check if bottom is off-screen.
            // Aggressive approach: If expanding, bring the row header to the top of viewport.
            // We'll consider a row "needs scrolling" if it's too high up (under header) 
            // OR if it's near the bottom half of the screen (to make room for expansion).
            var threshold = window.innerHeight * 0.7; // If row is in bottom 30% of screen, scroll it up

            if (rect.top < headerHeight + 5 || rect.top > threshold || rect.bottom > window.innerHeight - 20) {
                var scrollTo = window.pageYOffset + rect.top - headerHeight - 10;
                window.scrollTo({ top: scrollTo, behavior: 'smooth' });
            }
        }, 220); // Sync better with animation
    }

    function repositionBadges() {
        var headerNav = document.querySelector('header .navbar.sticky-top');
        var headerHeight = headerNav ? headerNav.offsetHeight : 70;
        state.badges.forEach(function(b) {
            if (!b.el || !document.body.contains(b.el)) return;
            var rect = b.el.getBoundingClientRect();
            if (rect.height === 0) {
                b.element.style.display = 'none';
                return;
            }
            b.element.style.display = 'flex';
            b.element.style.top  = (window.scrollY + rect.top + (rect.height / 2)) + 'px';
            b.element.style.left = (window.scrollX + rect.left + 25) + 'px';
        });
    }

    function refreshZapBadges() {
        if (state.zapModeTarget === 'none') return;
        
        var mode = state.zapModeTarget;
        var headerNav = document.querySelector('header .navbar.sticky-top');
        var headerHeight = headerNav ? headerNav.offsetHeight : 70;
        var selector = (mode === 'edit') ? config.editBtnSelector : config.rowSelector;
        
        var newElements = Array.from(document.querySelectorAll(selector))
            .filter(el => {
                var rect = el.getBoundingClientRect();
                return el.offsetWidth > 0 && el.offsetHeight > 0 && rect.top >= headerHeight;
            });

        // Re-map references if elements have changed (e.g. after AJAX re-render)
        state.badges.forEach((b, index) => {
            if (newElements[index]) {
                b.el = newElements[index];
            }
        });

        repositionBadges();
    }

    function clearZapMode() {
        state.zapModeTarget = 'none';
        state.badges.forEach(b => b.element.remove());
        state.badges = [];
    }

    function handleArrowKeydown(e) {
        // Don't interfere if typing in inputs or if a naturally focusable element is active
        var active = document.activeElement;
        var focusableTags = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'];
        if (focusableTags.includes(active.tagName) || active.isContentEditable) {
            return;
        }

        var rows = Array.from(document.querySelectorAll(config.rowSelector))
            .filter(r => r.offsetWidth > 0 && r.offsetHeight > 0);
        
        if (rows.length === 0) return;

        if (e.key === 'ArrowDown' || (e.key === 'j' && (e.altKey || state.navigationShortcutsActive))) {
            e.preventDefault();
            if (state.activeIndex === -1) scrollToTable();
            state.activeIndex = Math.min(state.activeIndex + 1, rows.length - 1);
            updateActiveRow(rows);
        } else if (e.key === 'ArrowUp' || (e.key === 'k' && (e.altKey || state.navigationShortcutsActive))) {
            e.preventDefault();
            if (state.activeIndex === -1) scrollToTable();
            state.activeIndex = Math.max(state.activeIndex - 1, 0);
            updateActiveRow(rows);
        } else if (e.key === 'Enter' || e.key === 'e') {
            if (state.activeIndex >= 0 && state.activeIndex < rows.length) {
                var btn = rows[state.activeIndex].querySelector(config.editBtnSelector);
                if (btn) {
                    e.preventDefault();
                    btn.click();
                }
            }
        } else if (e.key === 'x' || e.code === 'Space') {
            // Expand/collapse row on Space or X
            if (state.activeIndex >= 0 && state.activeIndex < rows.length) {
                e.preventDefault();
                var targetRow = rows[state.activeIndex];
                targetRow.click();
                scrollRowToVisible(targetRow);
            }
        } else if (e.key === 'Escape') {
            clearActiveRow();
        }
    }

    function updateActiveRow(rows) {
        clearActiveRow();
        if (state.activeIndex >= 0) {
            var row = rows[state.activeIndex];
            row.classList.add(config.activeRowClass);

            // Move DOM focus to the row so Tab/Shift+Tab navigates naturally from here
            if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '-1');
            row.focus({ preventScroll: true });

            var headerNav = document.querySelector('header .navbar.sticky-top');
            var headerHeight = headerNav ? headerNav.offsetHeight : 70;
            
            var rect = row.getBoundingClientRect();
            // If row top is under the header or too close to it
            if (rect.top < headerHeight + 10) {
                window.scrollBy({ top: rect.top - headerHeight - 20, behavior: 'smooth' });
            } 
            // If row bottom is below the viewport
            else if (rect.bottom > window.innerHeight) {
                window.scrollBy({ top: rect.bottom - window.innerHeight + 20, behavior: 'smooth' });
            }
        }
    }

    function clearActiveRow() {
        document.querySelectorAll('.' + config.activeRowClass).forEach(r => r.classList.remove(config.activeRowClass));
    }

    function initColumnResize() {
        var tables = document.querySelectorAll('table.resizable-table');
        tables.forEach(function(table) {
            var resizers = table.querySelectorAll('.col-resizer');
            var isResizing = false, currentResizer = null, startX = 0, startWidth = 0;
            resizers.forEach(function(resizer, index) {
                resizer.setAttribute('tabindex', '0');
                resizer.setAttribute('role', 'separator');
                resizer.setAttribute('aria-label', 'Resize column ' + (index + 1));
                resizer.addEventListener('mousedown', function(e) {
                    isResizing = true;
                    currentResizer = resizer;
                    startX = e.clientX;
                    startWidth = parseInt(window.getComputedStyle(resizer.parentElement).width, 10);
                    resizer.classList.add('resizing');
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    e.preventDefault();
                });
                resizer.addEventListener('keydown', function(e) {
                    var th = this.parentElement;
                    var w = parseInt(window.getComputedStyle(th).width, 10);
                    if (e.key === 'ArrowLeft') th.style.width = Math.max(40, w - 10) + 'px';
                    else if (e.key === 'ArrowRight') th.style.width = (w + 10) + 'px';
                    else return;
                    e.preventDefault();
                    e.stopPropagation(); // prevent nav interference
                });
                resizer.addEventListener('focus', function() { this.classList.add('focused'); });
                resizer.addEventListener('blur', function() { this.classList.remove('focused'); });
            });
            document.addEventListener('mousemove', function(e) {
                if (!isResizing || !currentResizer) return;
                var newW = Math.max(40, startWidth + (e.clientX - startX));
                currentResizer.parentElement.style.width = newW + 'px';
                e.preventDefault();
            });
            document.addEventListener('mouseup', function() {
                if (isResizing && currentResizer) {
                    currentResizer.classList.remove('resizing');
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    isResizing = false;
                    currentResizer = null;
                }
            });
        });
    }

    function showShortcutsModal() {
        var existing = document.getElementById('issueNavShortcutsModal');
        if (existing) {
            var m = bootstrap.Modal.getOrCreateInstance(existing);
            m.show();
            return;
        }
        document.body.insertAdjacentHTML('beforeend', `
        <div class="modal fade" id="issueNavShortcutsModal" tabindex="-1" aria-labelledby="issueNavShortcutsModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="issueNavShortcutsModalLabel"><i class="fas fa-keyboard me-2"></i>Keyboard Shortcuts</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body p-0">
                <table class="table table-sm table-hover mb-0">
                  <thead class="table-light"><tr><th>Shortcut</th><th>Action</th></tr></thead>
                  <tbody>
                    <tr><td><kbd>Alt</kbd> + <kbd>E</kbd></td><td>Label &amp; Zap — show badges on <strong>Edit</strong> buttons. Press the letter to edit that issue.</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>O</kbd></td><td>Label &amp; Zap — show badges on <strong>rows</strong>. Press letter to <strong>expand / collapse</strong> that row. Badges stay until <kbd>Esc</kbd>.</td></tr>
                    <tr class="table-light"><td colspan="2" class="fw-semibold text-muted small pt-2 pb-1">Arrow Navigation</td></tr>
                    <tr><td><kbd>J</kbd> / <kbd>↓</kbd></td><td>Move selection down to next issue row.</td></tr>
                    <tr><td><kbd>K</kbd> / <kbd>↑</kbd></td><td>Move selection up to previous issue row.</td></tr>
                    <tr><td><kbd>Space</kbd> / <kbd>X</kbd></td><td>Expand or collapse the currently highlighted row.</td></tr>
                    <tr><td><kbd>Enter</kbd> / <kbd>E</kbd></td><td>Open the <strong>Edit</strong> modal for the highlighted row.</td></tr>
                    <tr class="table-light"><td colspan="2" class="fw-semibold text-muted small pt-2 pb-1">Global Actions</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>A</kbd></td><td><strong>Add Issue</strong> — opens the new issue editor modal.</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>S</kbd></td><td><strong>Save Issue</strong> — saves changes when editor is open.</td></tr>
                    <tr><td><kbd>Esc</kbd></td><td>Clear active row highlight or dismiss badge overlay.</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="modal-footer">
                <span class="text-muted small">Press <kbd>Esc</kbd> to close</span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>`);
        var el = document.getElementById('issueNavShortcutsModal');
        el.addEventListener('hidden.bs.modal', function() { el.remove(); });
        bootstrap.Modal.getOrCreateInstance(el).show();
    }

    return {
        init: init
    };
})();
