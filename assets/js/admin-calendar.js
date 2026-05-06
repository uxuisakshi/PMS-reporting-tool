/* Admin Calendar JS - extracted from modules/admin/calendar.php */

/* ---- Status helpers (depend on window.AdminCalendarConfig injected inline) ---- */
function availabilityStatusLabel(statusKey) {
    var key = String(statusKey || '').toLowerCase();
    var meta = (window.AdminCalendarConfig && window.AdminCalendarConfig.statusMeta && window.AdminCalendarConfig.statusMeta[key])
        ? window.AdminCalendarConfig.statusMeta[key] : null;
    return (meta && meta.status_label) ? String(meta.status_label) : key.replace(/_/g, ' ');
}

function availabilityStatusBadgeClass(statusKey, withBgPrefix) {
    var key = String(statusKey || '').toLowerCase();
    var meta = (window.AdminCalendarConfig && window.AdminCalendarConfig.statusMeta && window.AdminCalendarConfig.statusMeta[key])
        ? window.AdminCalendarConfig.statusMeta[key] : null;
    var color = (meta && meta.badge_color) ? String(meta.badge_color).toLowerCase() : 'secondary';
    var allowed = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark', 'light'];
    if (allowed.indexOf(color) === -1) color = 'secondary';
    return withBgPrefix ? ('bg-' + color) : color;
}

function ucfirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&"'<>]/g, function (s) {
        return ({'&':'&amp;','"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;'})[s];
    });
}

/* ---- FullCalendar init ---- */
document.addEventListener('DOMContentLoaded', function() {
    var FC = window.FullCalendar || (typeof FullCalendar !== 'undefined' ? FullCalendar : null);
    if (!FC) { console.error('FullCalendar failed to load'); return; }

    var cfg = window.AdminCalendarConfig || {};
    var eventsUrl = cfg.eventsUrl || '';
    var editRequestsUrl = cfg.editRequestsUrl || '';
    var statusFilterStorageKey = 'admin_calendar_status_filters_v1';
    var hoursFilterStorageKey = 'admin_calendar_hours_filters_v1';
    var calendarEl = document.getElementById('calendar');

    function getSelectedFilters() {
        var checkboxes = document.querySelectorAll('.status-filter-check:checked');
        var filters = Array.from(checkboxes).map(function(cb){ return cb.value; });
        return filters.length > 0 ? filters.join(',') : 'none';
    }

    function getSelectedFilterSet() {
        return new Set(
            Array.from(document.querySelectorAll('.status-filter-check:checked')).map(function(cb) {
                return String(cb.value || '').toLowerCase();
            })
        );
    }

    function getSelectedHourFilterSet() {
        return new Set(
            Array.from(document.querySelectorAll('.hours-filter-check:checked')).map(function(cb) {
                return String(cb.value || '').toLowerCase();
            })
        );
    }

    function isStatusVisibleByFilter(statusType, selectedSet) {
        var key = String(statusType || '').toLowerCase();
        if (!selectedSet || selectedSet.size === 0) return false;
        if (selectedSet.has(key)) return true;
        if ((key === 'on_leave' || key === 'sick_leave') && selectedSet.has('leave')) return true;
        return false;
    }

    function isHoursVisibleByFilter(hoursCategory, selectedSet) {
        // If both checked or neither checked → show all
        if (!selectedSet || selectedSet.size === 0) return true;
        var hasUnder = selectedSet.has('under_8_hours');
        var hasCompliant = selectedSet.has('compliant');
        if (hasUnder && hasCompliant) return true;  // both on → show all
        if (!hasUnder && !hasCompliant) return true; // both off → show all
        // If only one is checked, check if the event matches that category
        var key = String(hoursCategory || '').toLowerCase();
        if (!key) return true; // If no category set, show by default
        return selectedSet.has(key);
    }

    function applyCombinedFiltersToRenderedEvents() {
        var selectedSet = getSelectedFilterSet();
        var selectedHourSet = getSelectedHourFilterSet();
        var eventEls = calendarEl.querySelectorAll('.fc-event, .fc-daygrid-event');
        
        eventEls.forEach(function(el) {
            // Skip edit request events — they are controlled by their own toggle
            if (el.classList.contains('fc-edit-request-event')) return;

            var statusType   = String(el.getAttribute('data-status-type') || '').toLowerCase();
            var hoursCategory = String(el.getAttribute('data-hours-category') || '').toLowerCase();

            // If no status-type set yet (event not fully mounted), skip — will be handled on next eventsSet
            if (!statusType) return;

            var statusVisible = isStatusVisibleByFilter(statusType, selectedSet);
            
            // Always use isHoursVisibleByFilter - it handles empty category properly now
            var hoursVisible = isHoursVisibleByFilter(hoursCategory, selectedHourSet);

            el.style.display = (statusVisible && hoursVisible) ? '' : 'none';
        });
    }

    function applySavedStatusFilters() {
        try {
            var raw = localStorage.getItem(statusFilterStorageKey);
            if (!raw) return;
            var saved = JSON.parse(raw);
            if (!Array.isArray(saved)) return;
            document.querySelectorAll('.status-filter-check').forEach(function(cb) {
                cb.checked = saved.indexOf(cb.value) !== -1;
            });
        } catch (e) {}
    }

    function saveStatusFilters() {
        try {
            var selected = Array.from(document.querySelectorAll('.status-filter-check:checked')).map(function(cb){ return cb.value; });
            localStorage.setItem(statusFilterStorageKey, JSON.stringify(selected));
        } catch (e) {}
    }

    function applySavedHourFilters() {
        try {
            var raw = localStorage.getItem(hoursFilterStorageKey);
            if (!raw) return;
            var saved = JSON.parse(raw);
            if (!Array.isArray(saved)) return;
            document.querySelectorAll('.hours-filter-check').forEach(function(cb) {
                cb.checked = saved.indexOf(cb.value) !== -1;
            });
        } catch (e) {}
    }

    function saveHourFilters() {
        try {
            var selected = Array.from(document.querySelectorAll('.hours-filter-check:checked')).map(function(cb){ return cb.value; });
            localStorage.setItem(hoursFilterStorageKey, JSON.stringify(selected));
        } catch (e) {}
    }

    function getSelectedUserId() {
        var select = document.getElementById('userSelect');
        return select ? select.value : '';
    }

    function getEventsUrl() {
        var userId = getSelectedUserId();
        return eventsUrl + (userId ? '&user_id=' + encodeURIComponent(userId) : '');
    }

    function adjustPopoverPosition(popover, dayEl) {
        if (!popover) return;
        var calendarContainer = document.querySelector('.calendar-container');
        if (!calendarContainer) return;
        var containerRect = calendarContainer.getBoundingClientRect();
        var popoverRect = popover.getBoundingClientRect();
        if (popoverRect.right > containerRect.right) {
            var ol = parseInt(popover.style.left) || 0;
            popover.style.left = (ol - (popoverRect.right - containerRect.right) - 20) + 'px';
        }
        if (popoverRect.left < containerRect.left) {
            var ol2 = parseInt(popover.style.left) || 0;
            popover.style.left = (ol2 + (containerRect.left - popoverRect.left) + 20) + 'px';
        }
        if (popoverRect.bottom > containerRect.bottom) {
            var ot = parseInt(popover.style.top) || 0;
            popover.style.top = (ot - (popoverRect.bottom - containerRect.bottom) - 20) + 'px';
        }
        if (popoverRect.top < containerRect.top) {
            var ot2 = parseInt(popover.style.top) || 0;
            popover.style.top = (ot2 + (containerRect.top - popoverRect.top) + 20) + 'px';
        }
    }

    function fetchEditRequests(fetchInfo, successCallback, failureCallback) {
        var selectedUserId = getSelectedUserId();
        var url = editRequestsUrl;
        if (selectedUserId && selectedUserId !== 'all') {
            url += '&user_id=' + encodeURIComponent(selectedUserId);
        }
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data.success && data.requests) {
                    var targetUserId = selectedUserId && selectedUserId !== 'all' ? parseInt(selectedUserId, 10) : null;
                    var events = data.requests
                        .filter(function(req){ return !targetUserId || Number(req.user_id) === targetUserId; })
                        .map(function(req) {
                            var reqStatus = (req.status || '').toLowerCase();
                            var reqType = (req.request_type || 'edit').toLowerCase();
                            var color = '#17a2b8';
                            if (reqStatus === 'approved') color = '#28a745';
                            else if (reqStatus === 'rejected') color = '#dc3545';
                            else if (reqStatus === 'used') color = '#343a40';
                            var statusLabel = reqStatus ? reqStatus.charAt(0).toUpperCase() + reqStatus.slice(1) : 'Unknown';
                            var typeLabel = reqType === 'delete' ? 'Delete Request' : 'Edit Request';
                            return {
                                title: typeLabel + ' [' + statusLabel + '] - ' + req.user_name,
                                start: req.req_date,
                                color: color,
                                textColor: '#ffffff',
                                extendedProps: {
                                    isEditRequest: true,
                                    requestId: req.id,
                                    userId: req.user_id,
                                    userName: req.user_name,
                                    reason: req.reason,
                                    requestStatus: reqStatus,
                                    requestType: reqType
                                }
                            };
                        });
                    successCallback(events);
                } else { successCallback([]); }
            })
            .catch(function(err){ failureCallback(err); });
    }

    function isEditRequestEvent(ev) {
        if (!ev) return false;
        var props = ev.extendedProps || {};
        if (props.isEditRequest) return true;
        try {
            var src = typeof ev.getSource === 'function' ? ev.getSource() : null;
            if (src && src.id === 'editRequests') return true;
        } catch (e) {}
        var title = (typeof ev.title === 'string') ? ev.title : '';
        return title.indexOf('Edit Request') === 0 || title.indexOf('Delete Request') === 0;
    }

    function refreshMainEvents() {
        document.getElementById('calendar-loading').style.display = 'flex';
        var source = window.calendar.getEventSourceById('mainEvents');
        if (source) source.remove();
        window.calendar.addEventSource({
            id: 'mainEvents',
            url: getEventsUrl(),
            success: function(events) {
                document.getElementById('calendar-loading').style.display = 'none';
                if (events.length === 0 && typeof showToast === 'function') {
                    showToast('No events found for the selected filters', 'info');
                }
            },
            failure: function() {
                document.getElementById('calendar-loading').style.display = 'none';
                if (typeof showToast === 'function') {
                    showToast('Failed to load calendar events. Please try again.', 'danger');
                }
            }
        });
    }

    function toggleEditRequestsOverlay() {
        var toggleEl = document.getElementById('filterEditRequests');
        var showEditRequests = toggleEl ? !!toggleEl.checked : false;
        var source = window.calendar.getEventSourceById('editRequests');
        calendarEl.querySelectorAll('.fc-edit-request-event').forEach(function(el) {
            el.style.display = showEditRequests ? '' : 'none';
        });
        if (!showEditRequests) {
            if (source) source.remove();
            window.calendar.getEvents().forEach(function(ev) { if (isEditRequestEvent(ev)) ev.remove(); });
            if (typeof window.calendar.updateSize === 'function') window.calendar.updateSize();
            return;
        }
        if (!source) {
            source = window.calendar.addEventSource({ id: 'editRequests', events: fetchEditRequests });
        }
        if (source && typeof source.refetch === 'function') source.refetch();
        else if (typeof window.calendar.refetchEvents === 'function') window.calendar.refetchEvents();
        if (typeof window.calendar.updateSize === 'function') window.calendar.updateSize();
    }
    window.__adminCalendarToggleEditRequests = toggleEditRequestsOverlay;

    var defaultView = window.innerWidth < 768 ? 'dayGridDay' : 'dayGridMonth';
    var plugins = [];
    if (FC.dayGridPlugin) plugins.push(FC.dayGridPlugin);
    if (FC.interactionPlugin) plugins.push(FC.interactionPlugin);

    window.calendar = new FC.Calendar(calendarEl, {
        plugins: plugins,
        initialView: defaultView,
        dayMaxEventRows: false,
        views: {
            dayGridMonth: { dayMaxEventRows: 4 },
            dayGridDay: { dayMaxEventRows: false, dayHeaderFormat: { weekday: 'short', day: 'numeric', month: 'short' } }
        },
        moreLinkClick: function(info) {
            var popover = info.view.calendar.el.querySelector('.fc-popover');
            if (popover) setTimeout(function(){ adjustPopoverPosition(popover, info.dayEl); }, 10);
            return 'popover';
        },
        moreLinkText: function(num){ return '+' + num + ' more'; },
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridDay' },
        eventDisplay: 'block',
        eventContent: function(arg) {
            if (!arg || !arg.view || arg.view.type !== 'dayGridDay') return;
            var props = arg.event.extendedProps || {};
            var card = document.createElement('div');
            card.className = 'fc-day-detail';
            var head = document.createElement('div');
            head.className = 'd-flex justify-content-between align-items-start mb-1';
            var title = document.createElement('div');
            title.className = 'fc-day-detail-title me-2';
            title.textContent = props.user_full_name || arg.event.title;
            head.appendChild(title);
            var badges = document.createElement('div');
            badges.className = 'd-flex gap-1 flex-wrap justify-content-end';
            var statusRaw = props.statusType || props.status || '';
            if (statusRaw) {
                var statusLabel = availabilityStatusLabel(statusRaw);
                var badgeClass = availabilityStatusBadgeClass(statusRaw, true);
                var statusBadge = document.createElement('span');
                statusBadge.className = 'badge ' + badgeClass + ' badge-status';
                statusBadge.textContent = statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1);
                badges.appendChild(statusBadge);
            }
            if (props.total_hours !== undefined && props.total_hours !== null) {
                var hoursBadge = document.createElement('span');
                hoursBadge.className = 'badge bg-light text-dark border badge-status';
                hoursBadge.textContent = parseFloat(props.total_hours).toFixed(2) + ' h';
                badges.appendChild(hoursBadge);
            }
            head.appendChild(badges);
            card.appendChild(head);
            if (props.notes) {
                var notes = document.createElement('p');
                notes.className = 'small text-muted mb-0';
                notes.textContent = props.notes.replace(/<[^>]*>?/gm, '');
                card.appendChild(notes);
            }
            return { domNodes: [card] };
        },
        eventDidMount: function(info) {
            try {
                var el = info.el || (info.elms && info.elms[0]);
                if (el && info.event && info.event.extendedProps) {
                    var props = info.event.extendedProps || {};
                    if (props.fullTitle && props.fullTitle !== info.event.title) {
                        el.setAttribute('title', props.fullTitle);
                        el.setAttribute('data-bs-toggle', 'tooltip');
                        el.setAttribute('data-bs-placement', 'top');
                        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) new bootstrap.Tooltip(el);
                    }
                    if (isEditRequestEvent(info.event)) {
                        el.classList.add('fc-edit-request-event');
                        var toggleEl = document.getElementById('filterEditRequests');
                        if (toggleEl && !toggleEl.checked) el.style.display = 'none';
                    }
                    if (props.user_id) el.setAttribute('data-user-id', props.user_id);
                    if (props.user_full_name) el.setAttribute('data-user-fullname', props.user_full_name);
                    if (props.role) el.setAttribute('data-user-role', props.role);
                    
                    // Always set status-type if available
                    if (props.statusType) {
                        el.setAttribute('data-status-type', props.statusType);
                    }
                    
                    // Calculate and set hours category for all events with hours data
                    var actualHours = 0;
                    if (typeof props.total_hours !== 'undefined' && props.total_hours !== null) {
                        actualHours = parseFloat(props.total_hours || 0);
                    } else if (typeof props.totalHours !== 'undefined' && props.totalHours !== null) {
                        actualHours = parseFloat(props.totalHours || 0);
                    }
                    
                    // Set hours category even if statusType is not present
                    if (actualHours > 0 || props.total_hours !== undefined || props.totalHours !== undefined) {
                        var expectedHours = 8;
                        if (props.consolidated && typeof props.userCount !== 'undefined') {
                            expectedHours = Math.max(1, parseInt(props.userCount, 10) || 1) * 8;
                        }
                        var hoursCategory = actualHours >= expectedHours ? 'compliant' : 'under_8_hours';
                        el.setAttribute('data-hours-category', hoursCategory);
                    }
                    
                    // Apply filters
                    var selectedSet = getSelectedFilterSet();
                    var selectedHourSet = getSelectedHourFilterSet();
                    var statusVisible = props.statusType ? isStatusVisibleByFilter(props.statusType, selectedSet) : true;
                    var hoursCategory = el.getAttribute('data-hours-category') || '';
                    var hoursVisible = isHoursVisibleByFilter(hoursCategory, selectedHourSet);
                    
                    if (!statusVisible || !hoursVisible) {
                        el.style.display = 'none';
                    }
                    if (typeof info.event.startStr !== 'undefined') el.setAttribute('data-date', info.event.startStr);
                }
            } catch (e) {}
        },
        eventsSet: function(){ applyCombinedFiltersToRenderedEvents(); },
        viewDidMount: function(info) {
            var container = document.querySelector('.calendar-container');
            if (container) {
                if (info.view.type === 'dayGridDay') {
                    container.style.minHeight = '700px';
                    container.style.maxHeight = '90vh';
                    container.style.overflowY = 'auto';
                } else {
                    container.style.minHeight = '600px';
                    container.style.maxHeight = 'none';
                    container.style.overflowY = 'hidden';
                }
            }
            setTimeout(function(){ if (window.calendar) window.calendar.updateSize(); }, 100);
        },
        eventClick: function(info) {
            if (info.event.extendedProps.isEditRequest) {
                showEditRequestModal({
                    userName: info.event.extendedProps.userName,
                    reason: info.event.extendedProps.reason,
                    date: info.event.startStr
                });
                return false;
            }
            var uid = info.event.extendedProps.user_id || info.event.extendedProps.userId;
            var date = info.event.startStr;
            var role = info.event.extendedProps.role || '';
            if (info.event.extendedProps.consolidated) {
                showConsolidatedModal(info.event.extendedProps.userList, date, info.event.extendedProps.statusType);
                return false;
            }
            if (uid) {
                try { info.jsEvent && info.jsEvent.preventDefault(); info.jsEvent && info.jsEvent.stopPropagation(); } catch(e){}
                fetchUserHoursAndShow(uid, date, role);
                return false;
            }
            var notes = info.event.extendedProps.notes;
            var msg = 'User: ' + info.event.title + '\nRole: ' + info.event.extendedProps.role;
            if (notes) msg += '\nNotes: ' + notes;
            if (typeof showToast === 'function') showToast(msg, 'info');
        },
        height: 'auto',
        contentHeight: 600,
        handleWindowResize: true,
        windowResizeDelay: 100
    });

    document.fonts.ready.then(function() {
        setTimeout(function() {
            window.calendar.render();
            refreshMainEvents();
            toggleEditRequestsOverlay();
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1 && node.classList && node.classList.contains('fc-popover')) {
                            setTimeout(function(){ adjustPopoverPosition(node); }, 50);
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
            setTimeout(function(){ window.calendar.updateSize(); }, 300);
        }, 100);
    });

    document.querySelectorAll('.status-filter-check').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            saveStatusFilters();
            applyCombinedFiltersToRenderedEvents();
        });
    });

    document.querySelectorAll('.hours-filter-check').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            saveHourFilters();
            applyCombinedFiltersToRenderedEvents();
        });
    });

    var editRequestsToggle = document.getElementById('filterEditRequests');
    if (editRequestsToggle) {
        editRequestsToggle.addEventListener('change', toggleEditRequestsOverlay);
        editRequestsToggle.addEventListener('click', function(){ setTimeout(toggleEditRequestsOverlay, 0); });
    }

    var userSelect = document.getElementById('userSelect');
    if (userSelect) {
        userSelect.addEventListener('change', function() {
            refreshMainEvents();
            var editSource = window.calendar.getEventSourceById('editRequests');
            var showEditRequests = document.getElementById('filterEditRequests').checked;
            if (showEditRequests && editSource && typeof editSource.refetch === 'function') editSource.refetch();
            else if (showEditRequests) toggleEditRequestsOverlay();
            else if (editSource) editSource.remove();
        });
    }

    applySavedStatusFilters();
    applySavedHourFilters();
    applyCombinedFiltersToRenderedEvents();
});

/* ---- Summernote toolbar a11y helpers ---- */
function enableAdminToolbarKeyboardA11y($el) {
    if (!window.jQuery || !$el || !$el.length) return;
    var $toolbar = $el.next('.note-editor').find('.note-toolbar').first();
    if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;

    function getItems() {
        return $toolbar.find('.note-btn-group button').filter(function() {
            var $b = jQuery(this);
            return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length && $b.attr('aria-hidden') !== 'true';
        });
    }
    function setActiveIndex(idx) {
        var $items = getItems();
        if (!$items.length) return;
        var next = Math.max(0, Math.min(idx, $items.length - 1));
        $items.attr('tabindex', '-1');
        $items.eq(next).attr('tabindex', '0');
        $toolbar.data('kbdIndex', next);
    }
    function ensureOneTabStop() {
        var $items = getItems();
        if (!$items.length) return;
        if (!$items.filter('[tabindex="0"]').length) {
            $items.attr('tabindex', '-1');
            $items.eq(0).attr('tabindex', '0');
        }
    }
    function handleToolbarArrowNav(e) {
        var key = e.key || (e.originalEvent && e.originalEvent.key);
        if (key !== 'ArrowRight' && key !== 'ArrowLeft' && key !== 'Home' && key !== 'End') return;
        var $items = getItems();
        if (!$items.length) return;
        var activeEl = document.activeElement;
        var idx = $items.index(activeEl);
        if (idx < 0 && activeEl && activeEl.closest) {
            var parentBtn = activeEl.closest('button');
            if (parentBtn) idx = $items.index(parentBtn);
        }
        if (idx < 0) {
            var savedIdx = parseInt($toolbar.data('kbdIndex'), 10);
            if (!isNaN(savedIdx) && savedIdx >= 0 && savedIdx < $items.length) idx = savedIdx;
        }
        if (idx < 0) idx = $items.index($items.filter('[tabindex="0"]').first());
        if (idx < 0) idx = 0;
        e.preventDefault();
        if (e.stopPropagation) e.stopPropagation();
        if (key === 'Home') idx = 0;
        else if (key === 'ArrowRight') idx = (idx + 1) % $items.length;
        else if (key === 'ArrowLeft') idx = (idx - 1 + $items.length) % $items.length;
        setActiveIndex(idx);
        var $target = $items.eq(idx);
        $target.focus();
        if (document.activeElement !== $target.get(0)) setTimeout(function(){ $target.focus(); }, 0);
    }
    $toolbar.attr('role', 'toolbar');
    if (!$toolbar.attr('aria-label')) $toolbar.attr('aria-label', 'Editor toolbar');
    setActiveIndex(0);
    $toolbar.on('focusin', 'button', function() {
        var idx = getItems().index(this);
        if (idx >= 0) setActiveIndex(idx);
    });
    $toolbar.on('click', 'button', function() {
        var idx = getItems().index(this);
        if (idx >= 0) setActiveIndex(idx);
    });
    $toolbar.on('keydown', handleToolbarArrowNav);
    if (!$toolbar.data('kbdA11yNativeKeyBound')) {
        $toolbar.get(0).addEventListener('keydown', handleToolbarArrowNav, true);
        $toolbar.data('kbdA11yNativeKeyBound', true);
    }
    var observer = new MutationObserver(function(){ ensureOneTabStop(); });
    observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
    $toolbar.data('kbdA11yObserver', observer);
    var fixTimer = setInterval(ensureOneTabStop, 1000);
    $toolbar.data('kbdA11yTimer', fixTimer);
    ensureOneTabStop();
    $toolbar.data('kbdA11yBound', true);
}

function focusAdminEditorToolbar($el) {
    if (!window.jQuery || !$el || !$el.length) return;
    var $toolbar = $el.next('.note-editor').find('.note-toolbar').first();
    if (!$toolbar.length) return;
    var $items = $toolbar.find('.note-btn-group button').filter(function() {
        var $b = jQuery(this);
        return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length && $b.attr('aria-hidden') !== 'true';
    });
    if (!$items.length) return;
    $items.attr('tabindex', '-1');
    $items.eq(0).attr('tabindex', '0').focus();
}

/* ---- Admin Edit Modal ---- */
$(document).ready(function(){
    try {
        if ($.fn.summernote) {
            $('#adminEditModal').on('shown.bs.modal', function() {
                var snOpts = {
                    height: 120,
                    toolbar: [
                        ['style', ['bold', 'italic', 'underline', 'clear']],
                        ['font', ['strikethrough']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link']],
                        ['view', ['codeview']]
                    ]
                };
                $('#a_personal_note').summernote(Object.assign({}, snOpts, { callbacks: {
                    onInit: function() {
                        var $e = $('#a_personal_note');
                        setTimeout(function(){ enableAdminToolbarKeyboardA11y($e); }, 0);
                        setTimeout(function(){ enableAdminToolbarKeyboardA11y($e); }, 200);
                    },
                    onKeydown: function(e) {
                        if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                            e.preventDefault(); focusAdminEditorToolbar($('#a_personal_note'));
                        }
                    }
                }}));
                $('#a_notes').summernote(Object.assign({}, snOpts, { callbacks: {
                    onInit: function() {
                        var $e = $('#a_notes');
                        setTimeout(function(){ enableAdminToolbarKeyboardA11y($e); }, 0);
                        setTimeout(function(){ enableAdminToolbarKeyboardA11y($e); }, 200);
                    },
                    onKeydown: function(e) {
                        if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) {
                            e.preventDefault(); focusAdminEditorToolbar($('#a_notes'));
                        }
                    }
                }}));
                disableAdminEditing();
            });
            $('#adminEditModal').on('hide.bs.modal', function() {
                try { $('#a_personal_note').summernote('destroy'); $('#a_notes').summernote('destroy'); } catch(e) {}
            });
        }
    } catch(e) {}
});

function enableAdminEditing() {
    document.getElementById('a_status').disabled = false;
    var modal = document.getElementById('adminEditModal');
    if (modal) modal.classList.remove('admin-editor-readonly');
    setTimeout(function() {
        if ($.fn.summernote && $('#a_notes').summernote('code') !== undefined) {
            $('#a_notes').summernote('enable');
            $('#a_personal_note').summernote('enable');
            enableAdminToolbarKeyboardA11y($('#a_notes'));
            enableAdminToolbarKeyboardA11y($('#a_personal_note'));
        } else {
            document.getElementById('a_notes').readOnly = false;
            document.getElementById('a_personal_note').readOnly = false;
        }
    }, 200);
}

function disableAdminEditing() {
    document.getElementById('a_status').disabled = true;
    var modal = document.getElementById('adminEditModal');
    if (modal) modal.classList.add('admin-editor-readonly');
    setTimeout(function() {
        if ($.fn.summernote && $('#a_notes').summernote('code') !== undefined) {
            $('#a_notes').summernote('disable');
            $('#a_personal_note').summernote('disable');
        } else {
            document.getElementById('a_notes').readOnly = true;
            document.getElementById('a_personal_note').readOnly = true;
        }
    }, 200);
}

function loadAdminProductionHours(userId, date) {
    var cfg = window.AdminCalendarConfig || {};
    document.getElementById('adminHoursDate').textContent = '(' + date + ')';
    var url = cfg.userHoursUrl + '?user_id=' + encodeURIComponent(userId) + '&date=' + encodeURIComponent(date);
    fetch(url)
        .then(function(r){
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (data.success) {
                var totalHours = parseFloat(data.total_hours || 0);
                var utilizedHours = 0, benchHours = 0;
                document.getElementById('adminTotalHours').textContent = totalHours.toFixed(2) + ' hrs';
                if (data.entries && data.entries.length > 0) {
                    var html = '<div class="list-group list-group-flush">';
                    data.entries.forEach(function(entry) {
                        var hours = parseFloat(entry.hours_spent || 0);
                        var isUtilized = entry.is_utilized == 1 || entry.po_number !== 'OFF-PROD-001';
                        if (isUtilized) utilizedHours += hours; else benchHours += hours;
                        html += '<div class="list-group-item py-2"><div class="d-flex justify-content-between align-items-start"><div class="flex-grow-1">';
                        html += '<h6 class="mb-1">' + escapeHtml(entry.project_title || 'Unknown Project') + '</h6>';
                        if (entry.page_name) html += '<p class="mb-1 text-muted small">Page: ' + escapeHtml(entry.page_name) + '</p>';
                        if (entry.comments) html += '<p class="mb-0 small">' + escapeHtml(entry.comments) + '</p>';
                        html += '</div><div class="text-end"><span class="badge ' + (isUtilized ? 'bg-success' : 'bg-secondary') + '">' + hours.toFixed(2) + 'h</span></div></div></div>';
                    });
                    html += '</div>';
                    document.getElementById('adminHoursEntries').innerHTML = html;
                } else {
                    document.getElementById('adminHoursEntries').innerHTML = '<p class="text-muted text-center">No time logged for this date</p>';
                }
                document.getElementById('adminUtilizedHours').textContent = utilizedHours.toFixed(2);
                document.getElementById('adminBenchHours').textContent = benchHours.toFixed(2);
                if (totalHours > 0) {
                    document.getElementById('adminUtilizedProgress').style.width = ((utilizedHours / totalHours) * 100) + '%';
                    document.getElementById('adminBenchProgress').style.width = ((benchHours / totalHours) * 100) + '%';
                }
            } else {
                document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Failed to load: ' + escapeHtml(data.error || 'Unknown error') + '</p>';
            }
        })
        .catch(function(err) {
            document.getElementById('adminHoursEntries').innerHTML = '<p class="text-danger text-center">Error: ' + escapeHtml(err.message) + '</p>';
        });
}

function openAdminEditModal(userId, date, role, status, notes, personal_note) {
    document.getElementById('a_user_id').value = userId;
    document.getElementById('a_date').value = date;
    document.getElementById('a_status').value = status || 'available';
    var modalFooter = document.querySelector('#adminEditModal .modal-footer');
    modalFooter.querySelectorAll('.dynamic-save-btn').forEach(function(btn){ btn.remove(); });
    var editBtn = document.getElementById('adminEditBtn');
    editBtn.style.display = 'inline-block';
    editBtn.disabled = false;
    loadAdminProductionHours(userId, date);
    var modalEl = document.getElementById('adminEditModal');
    if (!modalEl) { if (typeof showToast === 'function') showToast('Modal not found', 'warning'); return; }
    var m = new bootstrap.Modal(modalEl);
    m.show();
    setTimeout(function() { loadAdminUserData(userId, date); disableAdminEditing(); }, 300);
}

function loadAdminUserData(userId, date) {
    var cfg = window.AdminCalendarConfig || {};
    var url = cfg.dailyStatusUrl + '?action=get_personal_note&date=' + encodeURIComponent(date) + '&user_id=' + encodeURIComponent(userId);
    fetch(url)
        .then(function(r){
            if (!r.ok) throw new Error('HTTP ' + r.status);
            var ct = r.headers.get('content-type');
            if (!ct || !ct.includes('application/json')) {
                return r.text().then(function(t){ throw new Error('Non-JSON response: ' + t.substring(0, 200)); });
            }
            return r.json();
        })
        .then(function(data) {
            if (data.success) {
                document.getElementById('a_status').value = data.status || 'not_updated';
                if ($.fn.summernote) {
                    try {
                        $('#a_notes').summernote('code', data.notes || '');
                        $('#a_personal_note').summernote('code', data.personal_note || '');
                    } catch(e) {
                        document.getElementById('a_notes').value = data.notes || '';
                        document.getElementById('a_personal_note').value = data.personal_note || '';
                    }
                } else {
                    document.getElementById('a_notes').value = data.notes || '';
                    document.getElementById('a_personal_note').value = data.personal_note || '';
                }
            } else {
                if (typeof showToast === 'function') showToast('Failed to load user data: ' + (data.error || 'Unknown error'), 'danger');
            }
        })
        .catch(function(err) {
            if (typeof showToast === 'function') showToast('Failed to load user data: ' + err.message, 'danger');
        });
}

document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'adminEditBtn') {
        enableAdminEditing();
        e.target.style.display = 'none';
        var modalFooter = document.querySelector('#adminEditModal .modal-footer');
        var cancelBtn = modalFooter.querySelector('.btn-secondary');
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-success dynamic-save-btn';
        saveBtn.textContent = 'Save Changes';
        saveBtn.onclick = function(){ $('#adminCalendarEditForm').submit(); };
        modalFooter.insertBefore(saveBtn, cancelBtn);
    }
});

document.addEventListener('click', function(e){
    var t = e.target;
    if (t && t.matches && t.matches('.open-admin-edit')) {
        var uid = t.getAttribute('data-user-id');
        var date = t.getAttribute('data-date');
        if (uid && date) openAdminEditModal(uid, date, '', '', '', '');
    }
});

$(document).on('submit', '#adminCalendarEditForm', function(e){
    e.preventDefault();
    var cfg = window.AdminCalendarConfig || {};
    var userId = document.getElementById('a_user_id').value;
    var date = document.getElementById('a_date').value;
    var status = document.getElementById('a_status').value;
    var notes = '', personal = '';
    if ($.fn.summernote) {
        try { notes = $('#a_notes').summernote('code'); personal = $('#a_personal_note').summernote('code'); }
        catch(e) { notes = document.getElementById('a_notes').value; personal = document.getElementById('a_personal_note').value; }
    } else {
        notes = document.getElementById('a_notes').value;
        personal = document.getElementById('a_personal_note').value;
    }
    $.ajax({
        url: cfg.dailyStatusUrl + '?date=' + encodeURIComponent(date),
        method: 'POST',
        data: { update_status: 1, user_id: userId, status: status, notes: notes, personal_note: personal },
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(resp){
            try { var j = typeof resp === 'object' ? resp : JSON.parse(resp); }
            catch(e){ if (typeof showToast === 'function') showToast('Unexpected response', 'danger'); return; }
            if (j.success) {
                if (window.calendar && typeof window.calendar.refetchEvents === 'function') window.calendar.refetchEvents();
                var m = bootstrap.Modal.getInstance(document.getElementById('adminEditModal'));
                if (m) m.hide();
                if (typeof showToast === 'function') showToast('Status updated successfully', 'success');
            } else {
                if (typeof showToast === 'function') showToast('Failed to update: ' + (j.error || 'Unknown error'), 'danger');
            }
        },
        error: function(xhr, status, error){
            if (typeof showToast === 'function') showToast('Request failed: ' + error, 'danger');
        }
    });
});

/* ---- Consolidated & Edit Request Modals ---- */
function showConsolidatedModal(userList, date, statusType) {
    var statusLabel = availabilityStatusLabel(statusType);
    document.getElementById('consolidatedModalTitle').textContent = statusLabel + ' Users - ' + date + ' (' + userList.length + ' users)';
    var html = '<div class="row g-3">';
    userList.forEach(function(user) {
        var badgeClass = availabilityStatusBadgeClass(statusType, true);
        if (user.hours > 0 && user.hours < 8) badgeClass = 'bg-warning text-dark';
        var statusText = ucfirst(user.status.replace('_', ' '));
        html += '<div class="col-md-6"><div class="card h-100 shadow-sm border"><div class="card-body p-3">';
        html += '<div class="d-flex justify-content-between align-items-start mb-2">';
        html += '<h6 class="card-title mb-0 fw-bold text-truncate" style="max-width:70%;" title="' + escapeHtml(user.name) + '">' + escapeHtml(user.name) + '</h6>';
        html += '<span class="badge ' + badgeClass + '">' + user.hours.toFixed(1) + 'h</span></div>';
        html += '<div class="mb-2"><span class="badge bg-light text-dark border me-1">' + statusText + '</span></div>';
        if (user.notes) html += '<div class="p-2 bg-light rounded small text-muted mb-3 text-truncate">' + escapeHtml(user.notes) + '</div>';
        else html += '<div class="mb-3"></div>';
        html += '<button class="btn btn-sm btn-outline-primary w-100" onclick="openAdminEditModal(' + user.id + ', \'' + date + '\', \'\', \'' + user.status + '\', \'' + escapeHtml(user.notes) + '\', \'\')">';
        html += '<i class="fas fa-edit me-1"></i> Edit Status</button>';
        html += '</div></div></div>';
    });
    html += '</div>';
    document.getElementById('consolidatedContent').innerHTML = html;
    new bootstrap.Modal(document.getElementById('consolidatedModal')).show();
}

function showEditRequestModal(data) {
    try {
        document.getElementById('ermUser').textContent = data.userName || '';
        document.getElementById('ermDate').textContent = data.date || '';
        document.getElementById('ermReason').textContent = data.reason || 'No reason provided';
        var modalEl = document.getElementById('editRequestModal');
        if (!modalEl) return;
        new bootstrap.Modal(modalEl).show();
    } catch (e) {
        if (typeof showToast === 'function') showToast('Edit Request from: ' + (data.userName || '') + '\nReason: ' + (data.reason || ''), 'info');
    }
}

function fetchUserHoursAndShow(userId, date, role) {
    openAdminEditModal(userId, date, role || '', '', '', '');
}
