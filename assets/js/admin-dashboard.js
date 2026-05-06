/**
 * admin-dashboard.js - Admin dashboard pending requests widget
 */
(function () {
    var cfg = window.AdminDashboardConfig || {};
    var pendingSummaryUrl = cfg.pendingSummaryUrl || '';
    var devicesApiUrl = cfg.devicesApiUrl || '';
    var editRequestsUrl = cfg.editRequestsUrl || '';
    var dashboardUrl = cfg.dashboardUrl || '';

    function formatPendingTime(v) {
        if (!v) return '-';
        var d = new Date(v.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '-';
        return d.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
    }

    function renderPendingRequests(data) {
        var badge = document.getElementById('pendingTotalBadge');
        if (badge) badge.textContent = (Number(data.pendingTotalCount || 0)) + ' pending';

        var host = document.getElementById('pendingRequestsContent');
        if (!host) return;

        var total = Number(data.pendingTotalCount || 0);
        var buckets = Array.isArray(data.pendingBuckets) ? data.pendingBuckets : [];
        var feed = Array.isArray(data.pendingFeed) ? data.pendingFeed : [];

        if (total === 0) {
            host.innerHTML = '<span class="text-muted">No pending requests right now.</span>';
            return;
        }

        var bucketsHtml = '';
        buckets.forEach(function (bucket) {
            var c = Number(bucket && bucket.count ? bucket.count : 0);
            if (c <= 0) return;
            var link = escapeHtml(bucket.link || '#');
            var label = escapeHtml(bucket.label || 'Requests');
            bucketsHtml += '<div class="col-sm-6 col-xl-3">' +
                '<a href="' + link + '" class="text-decoration-none">' +
                '<div class="border rounded p-2 h-100">' +
                '<div class="small text-muted">' + label + '</div>' +
                '<div class="h5 mb-0 text-dark">' + c + '</div>' +
                '</div></a></div>';
        });

        var feedHtml = '';
        feed.forEach(function (item) {
            var actionKind = String(item.action_kind || '');
            var requestId = Number(item.request_id || 0);
            var userId = Number(item.user_id || 0);
            var reqDate = escapeHtml(item.req_date || '');
            var link = escapeHtml(item.link || '#');

            var actionButtons = '<a href="' + link + '" class="btn btn-sm btn-outline-secondary">Open</a>';
            if (actionKind === 'device' && requestId > 0) {
                actionButtons =
                    '<button type="button" class="btn btn-sm btn-success" onclick="respondDeviceRequestFromDashboard(' + requestId + ', \'approve\')">Accept</button> ' +
                    '<button type="button" class="btn btn-sm btn-danger" onclick="respondDeviceRequestFromDashboard(' + requestId + ', \'reject\')">Reject</button> ' +
                    '<a href="' + link + '" class="btn btn-sm btn-outline-secondary">Open</a>';
            } else if (actionKind === 'hours' && requestId > 0) {
                actionButtons =
                    '<button type="button" class="btn btn-sm btn-success" onclick="respondHoursRequestFromDashboard(' + requestId + ', ' + userId + ', \'' + reqDate + '\', \'approved\')">Accept</button> ' +
                    '<button type="button" class="btn btn-sm btn-danger" onclick="respondHoursRequestFromDashboard(' + requestId + ', ' + userId + ', \'' + reqDate + '\', \'rejected\')">Reject</button> ' +
                    '<a href="' + link + '" class="btn btn-sm btn-outline-secondary">Open</a>';
            }

            feedHtml +=
                '<div class="list-group-item" data-pending-item="1" data-action-kind="' + escapeHtml(actionKind) + '" data-request-id="' + requestId + '">' +
                '<div class="d-flex justify-content-between align-items-start mb-2">' +
                '<div><span class="badge bg-secondary me-2">' + escapeHtml(item.type || '') + '</span>' +
                '<strong>' + escapeHtml(item.title || '') + '</strong>' +
                '<div class="small text-muted">Requested by ' + escapeHtml(item.user || 'Unknown') + '</div></div>' +
                '<small class="text-muted">' + formatPendingTime(item.requested_at || '') + '</small>' +
                '</div><div class="d-flex gap-2">' + actionButtons + '</div></div>';
        });

        host.innerHTML =
            '<div class="row g-2 mb-3">' + (bucketsHtml || '') + '</div>' +
            (feedHtml ? '<h6 class="mb-2">Latest Pending Requests</h6><div class="list-group">' + feedHtml + '</div>' : '');
    }

    function refreshPendingRequests() {
        fetch(pendingSummaryUrl + '&_ts=' + Date.now(), {
            cache: 'no-store', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' }
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) { if (data && data.success) renderPendingRequests(data); })
        .catch(function () {});
    }

    function removePendingFeedItem(actionKind, requestId) {
        var sel = '.list-group-item[data-pending-item="1"][data-action-kind="' + actionKind + '"][data-request-id="' + String(requestId) + '"]';
        var item = document.querySelector(sel);
        if (item) item.remove();
        var badge = document.getElementById('pendingTotalBadge');
        if (badge) {
            var m = String(badge.textContent || '').match(/\d+/);
            var current = m ? parseInt(m[0], 10) : 0;
            badge.textContent = Math.max(0, current - 1) + ' pending';
        }
        var list = document.querySelector('.card.border-warning .list-group');
        if (list && list.children.length === 0) {
            list.innerHTML = '<div class="list-group-item text-muted">No pending requests right now.</div>';
        }
    }

    function optimisticallyRemovePendingItem(actionKind, requestId) {
        var sel = '.list-group-item[data-pending-item="1"][data-action-kind="' + actionKind + '"][data-request-id="' + String(requestId) + '"]';
        var item = document.querySelector(sel);
        if (!item || !item.parentNode) return null;
        var parent = item.parentNode;
        var nextSibling = item.nextSibling;
        removePendingFeedItem(actionKind, requestId);
        return function restore() {
            if (!parent) return;
            if (nextSibling && nextSibling.parentNode === parent) parent.insertBefore(item, nextSibling);
            else parent.appendChild(item);
            var badge = document.getElementById('pendingTotalBadge');
            if (badge) {
                var m = String(badge.textContent || '').match(/\d+/);
                var current = m ? parseInt(m[0], 10) : 0;
                badge.textContent = (current + 1) + ' pending';
            }
        };
    }

    window.respondDeviceRequestFromDashboard = function (requestId, action) {
        var actionLabel = action === 'approve' ? 'accept' : 'reject';
        confirmModal('Are you sure you want to ' + actionLabel + ' this device request?', function () {
            var restoreItem = optimisticallyRemovePendingItem('device', requestId);
            $.post(devicesApiUrl, {
                action: 'respond_to_request',
                request_id: requestId,
                response_action: action,
                response_notes: 'Processed from admin dashboard'
            }, function (response) {
                if (response && response.success) {
                    showToast('Device request ' + (action === 'approve' ? 'accepted' : 'rejected') + '.', 'success');
                    refreshPendingRequests();
                } else {
                    if (typeof restoreItem === 'function') restoreItem();
                    showToast((response && response.message) ? response.message : 'Failed to process request', 'danger');
                }
            }).fail(function (xhr) {
                if (typeof restoreItem === 'function') restoreItem();
                var msg = 'Failed to process request';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                showToast(msg, 'danger');
            });
        }, {
            title: action === 'approve' ? 'Confirm Accept' : 'Confirm Reject',
            confirmText: action === 'approve' ? 'Accept' : 'Reject',
            confirmClass: action === 'approve' ? 'btn-success' : 'btn-danger'
        });
    };

    window.respondHoursRequestFromDashboard = function (requestId, userId, reqDate, action) {
        var actionLabel = action === 'approved' ? 'accept' : 'reject';
        confirmModal('Are you sure you want to ' + actionLabel + ' this hours request?', function () {
            var restoreItem = optimisticallyRemovePendingItem('hours', requestId);
            var fd = new FormData();
            fd.append('request_id', String(requestId));
            fd.append('action', action);
            fd.append('user_id', String(userId));
            fd.append('date', String(reqDate || ''));
            fd.append('return_to', dashboardUrl);
            fetch(editRequestsUrl, {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function (resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) throw new Error((payload && payload.message) ? payload.message : 'Request failed');
                showToast('Hours request ' + (action === 'approved' ? 'accepted' : 'rejected') + '.', 'success');
                refreshPendingRequests();
            })
            .catch(function (err) {
                if (typeof restoreItem === 'function') restoreItem();
                showToast('Failed to process hours request: ' + (err && err.message ? err.message : 'Unknown error'), 'danger');
            });
        }, {
            title: action === 'approved' ? 'Confirm Accept' : 'Confirm Reject',
            confirmText: action === 'approved' ? 'Accept' : 'Reject',
            confirmClass: action === 'approved' ? 'btn-success' : 'btn-danger'
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        refreshPendingRequests();
        setInterval(refreshPendingRequests, 30000);
    });
})();
