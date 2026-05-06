// Global JavaScript Functions

// Confirm before delete
function confirmDelete(message = "Are you sure you want to delete this? This action cannot be undone.", callback) {
    if (typeof confirmModal === 'function') {
        confirmModal(message, callback, {
            title: 'Confirm Delete',
            icon: '<i class="fas fa-exclamation-triangle text-danger me-2"></i>',
            confirmText: 'Delete',
            confirmClass: 'btn-danger'
        });
    } else {
        if (confirm(message)) {
            if (typeof callback === 'function') callback();
        }
    }
}

// Confirm form submission
function confirmForm(formId, message = "Are you sure?") {
    const submitForm = function () {
        const form = document.getElementById(formId);
        if (form) form.submit();
    };

    if (typeof confirmModal === 'function') {
        confirmModal(message, function (ok) {
            if (ok) submitForm();
        }, {
            title: 'Confirm Action',
            icon: '<i class="fas fa-question-circle text-primary me-2"></i>',
            confirmText: 'Confirm',
            confirmClass: 'btn-primary'
        });
    } else if (confirm(message)) {
        submitForm();
    }
    return false;
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function () {
    // Initialize Bootstrap tooltips (avoid jQuery UI conflict)
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        tooltipTriggerList.forEach(function (el) {
            // Skip hidden or detached elements to prevent TooltipUI "n is undefined" error
            if (el && el.offsetParent !== null || el.closest('body')) {
                try { new bootstrap.Tooltip(el); } catch (e) {}
            }
        });
    }
});

// Auto-hide only alerts that explicitly opt-in with `alert-auto` class
// Persistent informational alerts (e.g. "No projects") should NOT include this class.
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        if (typeof $ !== 'undefined') {
            $('.alert.alert-auto').fadeOut('slow');
        } else {
            // Fallback to vanilla JS
            const alerts = document.querySelectorAll('.alert.alert-auto');
            alerts.forEach(function (alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function () {
                    alert.style.display = 'none';
                }, 500);
            });
        }
    }, 3000);
});

// File upload preview
function previewFile(input, previewId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) {
            if (typeof $ !== 'undefined') {
                $('#' + previewId).attr('src', e.target.result);
            } else {
                const img = document.getElementById(previewId);
                if (img) img.src = e.target.result;
            }
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Form validation
function validateForm(formId) {
    var form = document.getElementById(formId);
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return false;
    }
    return true;
}

// AJAX status update
function updateStatus(elementId, url, data) {
    if (typeof $ !== 'undefined') {
        $.ajax({
            url: url,
            type: 'POST',
            data: data,
            success: function (response) {
                $('#' + elementId).html(response);
                if (typeof showToast === 'function') {
                    showToast('Status updated successfully!', 'success');
                }
            },
            error: function () {
                if (typeof showToast === 'function') {
                    showToast('Error updating status!', 'error');
                }
            }
        });
    } else {
        console.warn('jQuery not available for AJAX status update');
    }
}

// Toast notifications - uses global showToast() from header.php
// The global showToast() function is defined in includes/header.php and uses pmsGlobalToastContainer
// No need to redefine it here - it's already available globally

// Initialize DataTables with common settings
function initDataTable(tableId) {
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        return $(tableId).DataTable({
            "pageLength": 25,
            "order": [[0, "desc"]],
            "language": {
                "search": "Filter:",
                "lengthMenu": "Show _MENU_ entries",
                "info": "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    } else {
        console.warn('DataTables not available');
        return null;
    }
}

// Real-time updates (for chat, notifications)
function startRealTimeUpdates() {
    if (typeof $ !== 'undefined') {
        setInterval(function () {
            // Check for new messages/updates
            $.get('/api/check-updates', function (data) {
                if (data.new_messages > 0) {
                    updateNotificationBadge(data.new_messages);
                }
            });
        }, 30000); // Every 30 seconds
    }
}

function updateNotificationBadge(count) {
    if (typeof $ !== 'undefined') {
        $('#notificationBadge').text(count).show();
    } else {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            badge.textContent = count;
        }
    }
}

// Date picker initialization
function initDatePickers() {
    if (typeof $ !== 'undefined' && $.fn.datepicker) {
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });
    }
}

// Multi-select enhancements
function initMultiSelect() {
    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('.multi-select').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    }
}

// Initialize everything when document is ready
document.addEventListener('DOMContentLoaded', function () {
    if (typeof $ !== 'undefined') {
        $(document).ready(function () {
            // Initialize DataTables
            $('.dataTable').each(function () {
                if (!$.fn.DataTable.isDataTable(this)) {
                    initDataTable(this);
                }
            });

            // Initialize date pickers
            if ($('.datepicker').length) {
                initDatePickers();
            }

            // Initialize multi-select
            if ($('.multi-select').length) {
                initMultiSelect();
            }

            // Start real-time updates if user is logged in
            if (typeof userId !== 'undefined') {
                startRealTimeUpdates();
            }
        });
    }
});

// Global ARIA tabs keyboard behavior (APG automatic activation pattern)
(function () {
    function safeId(prefix) {
        return prefix + Math.random().toString(36).slice(2, 10);
    }

    function isVisible(el) {
        if (!el) return false;
        const style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden';
    }

    function getTabPanelsContainer(tab) {
        if (!tab) return null;
        const root = tab.closest('.modal, .card, .tab-content, body');
        return root || document;
    }

    function getTabPanel(tab) {
        if (!tab) return null;
        let target = tab.getAttribute('data-bs-target') || tab.getAttribute('href');
        if (!target) return null;
        if (target.indexOf('#') === -1) return null;
        target = target.slice(target.indexOf('#'));
        if (!target || target === '#') return null;
        const scope = getTabPanelsContainer(tab);
        return (scope && scope.querySelector(target)) || document.querySelector(target);
    }

    function getTabControls(tablist) {
        if (!tablist) return [];
        const raw = tablist.querySelectorAll('button[data-bs-toggle="tab"],button[data-bs-toggle="pill"],a[data-bs-toggle="tab"],a[data-bs-toggle="pill"],[role="tab"]');
        return Array.from(raw).filter((tab) => !tab.disabled && tab.getAttribute('aria-disabled') !== 'true');
    }

    function applyTabState(tablist) {
        const tabs = getTabControls(tablist);
        if (!tabs.length) return;

        let activeTab = tabs.find((tab) => tab.classList.contains('active') || tab.getAttribute('aria-selected') === 'true');
        if (!activeTab) activeTab = tabs[0];

        tabs.forEach((tab) => {
            if (!tab.id) tab.id = safeId('tab_');
            tab.setAttribute('role', 'tab');
            const selected = tab === activeTab;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.setAttribute('tabindex', selected ? '0' : '-1');

            const panel = getTabPanel(tab);
            if (panel) {
                if (!panel.id) panel.id = safeId('tabpanel_');
                tab.setAttribute('aria-controls', panel.id);
                panel.setAttribute('role', 'tabpanel');
                panel.setAttribute('aria-labelledby', tab.id);
                panel.setAttribute('tabindex', '0');
                if (selected) {
                    panel.removeAttribute('hidden');
                } else if (!panel.classList.contains('active') && !panel.classList.contains('show')) {
                    panel.setAttribute('hidden', 'hidden');
                }
            }
        });
    }

    function activateTab(tab, focusAfter = true) {
        if (!tab) return;
        try {
            if (window.bootstrap && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(tab).show();
            } else {
                tab.click();
            }
        } catch (e) {
            tab.click();
        }
        if (focusAfter) tab.focus();
    }

    function moveFocusAndActivate(tablist, currentTab, direction) {
        const tabs = getTabControls(tablist).filter(isVisible);
        if (!tabs.length) return;
        const currentIndex = Math.max(0, tabs.indexOf(currentTab));
        let nextIndex = currentIndex;

        if (direction === 'first') nextIndex = 0;
        else if (direction === 'last') nextIndex = tabs.length - 1;
        else if (direction === 'next') nextIndex = (currentIndex + 1) % tabs.length;
        else if (direction === 'prev') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;

        const nextTab = tabs[nextIndex];
        if (nextTab) activateTab(nextTab, true);
    }

    function onTablistKeydown(e) {
        const tab = e.target.closest('[role="tab"],button[data-bs-toggle="tab"],button[data-bs-toggle="pill"],a[data-bs-toggle="tab"],a[data-bs-toggle="pill"]');
        if (!tab) return;
        const tablist = tab.closest('[role="tablist"], .nav-tabs, .nav-pills');
        if (!tablist) return;

        const orientation = (tablist.getAttribute('aria-orientation') || 'horizontal').toLowerCase();
        const key = e.key;
        let handled = false;

        if (orientation === 'vertical') {
            if (key === 'ArrowDown') {
                moveFocusAndActivate(tablist, tab, 'next');
                handled = true;
            } else if (key === 'ArrowUp') {
                moveFocusAndActivate(tablist, tab, 'prev');
                handled = true;
            }
        } else {
            if (key === 'ArrowRight') {
                moveFocusAndActivate(tablist, tab, 'next');
                handled = true;
            } else if (key === 'ArrowLeft') {
                moveFocusAndActivate(tablist, tab, 'prev');
                handled = true;
            }
        }

        if (key === 'Home') {
            moveFocusAndActivate(tablist, tab, 'first');
            handled = true;
        } else if (key === 'End') {
            moveFocusAndActivate(tablist, tab, 'last');
            handled = true;
        }

        if (handled) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    function initAriaTabs(root) {
        const context = root || document;
        const tablists = context.querySelectorAll('[role="tablist"], .nav-tabs, .nav-pills');
        tablists.forEach((tablist) => {
            if (!tablist.hasAttribute('role')) tablist.setAttribute('role', 'tablist');
            applyTabState(tablist);
        });
    }

    document.addEventListener('keydown', onTablistKeydown, true);
    document.addEventListener('shown.bs.tab', function (e) {
        const tab = e.target;
        const tablist = tab && tab.closest ? tab.closest('[role="tablist"], .nav-tabs, .nav-pills') : null;
        if (tablist) applyTabState(tablist);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAriaTabs(document); });
    } else {
        initAriaTabs(document);
    }

    const observer = new MutationObserver(function (mutations) {
        for (let i = 0; i < mutations.length; i++) {
            const m = mutations[i];
            if (!m.addedNodes || !m.addedNodes.length) continue;
            for (let j = 0; j < m.addedNodes.length; j++) {
                const node = m.addedNodes[j];
                if (!node || node.nodeType !== 1) continue;
                if (node.matches && (node.matches('[role="tablist"]') || node.matches('.nav-tabs') || node.matches('.nav-pills') || node.querySelector('[role="tablist"], .nav-tabs, .nav-pills'))) {
                    initAriaTabs(node);
                }
            }
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
