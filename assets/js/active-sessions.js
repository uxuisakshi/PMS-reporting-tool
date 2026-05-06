/**
 * active-sessions.js
 * Extracted from modules/admin/active_sessions.php inline script
 * Requires window._activeSessionsConfig.baseDir
 */
(function () {
    var baseDir = (window._activeSessionsConfig && window._activeSessionsConfig.baseDir) ? window._activeSessionsConfig.baseDir : '';
    var csrfToken = (window._activeSessionsConfig && window._activeSessionsConfig.csrfToken) ? window._activeSessionsConfig.csrfToken : (window._csrfToken || '');

    function updateSessionRowState(row, data) {
        if (!row) {
            return;
        }

        var logoutAtCell = row.querySelector('.session-logout-at');
        var logoutTypeCell = row.querySelector('.session-logout-type');
        var statusCell = row.querySelector('.session-status');
        var actionCell = row.querySelector('.session-action');

        if (logoutAtCell) {
            logoutAtCell.textContent = (data && data.logout_at) ? data.logout_at : 'Just now';
        }
        if (logoutTypeCell) {
            logoutTypeCell.textContent = (data && data.logout_type) ? data.logout_type : 'forced_by_admin';
        }
        if (statusCell) {
            statusCell.innerHTML = '<span class="badge bg-secondary">Logged Out</span>';
        }
        if (actionCell) {
            actionCell.innerHTML = '<span class="text-muted">-</span>';
        }

        row.classList.remove('table-warning');
        row.classList.add('table-light');
    }

    document.querySelectorAll('.force-logout').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var self = this;
            var sid = self.dataset.session;
            confirmModal('Force logout session ' + sid + ' ?', function () {
                self.disabled = true;
                fetch(baseDir + '/api/force_logout_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ session_id: sid, csrf_token: csrfToken })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j && j.success) {
                        var row = document.getElementById('sess-' + sid);
                        updateSessionRowState(row, j);
                        showToast('Session terminated successfully.', 'success');
                    } else {
                        self.disabled = false;
                        showToast('Failed: ' + (j && j.error ? j.error : 'unknown'), 'danger');
                    }
                }).catch(function (e) {
                    self.disabled = false;
                    console.error('Force logout error:', e);
                    showToast('Request failed', 'danger');
                });
            });
        });
    });

    // Read more toggle for long user-agent strings
    // Buttons are already rendered by PHP — just handle the click
    document.addEventListener('click', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ua-toggle')) {
            var btn = e.target;
            var cell = btn.closest('td');
            if (!cell) return;
            var full    = cell.querySelector('.ua-full');
            var snippet = cell.querySelector('.ua-snippet');
            if (!full) return;
            if (full.classList.contains('d-none')) {
                full.classList.remove('d-none');
                if (snippet) snippet.classList.add('d-none');
                btn.textContent = 'Read less';
            } else {
                full.classList.add('d-none');
                if (snippet) snippet.classList.remove('d-none');
                btn.textContent = 'Read more';
            }
        }
    });

    // Copy UA to clipboard
    document.addEventListener('click', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('ua-copy')) {
            var btn = e.target;
            var cell = btn.closest('td');
            if (!cell) return;
            var full = cell.querySelector('.ua-full');
            var text = full
                ? full.textContent.trim()
                : (cell.querySelector('.ua-snippet')
                    ? (cell.querySelector('.ua-snippet').getAttribute('title') || cell.querySelector('.ua-snippet').textContent)
                    : '');
            if (!text) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    var old = btn.innerHTML;
                    btn.innerHTML = 'Copied';
                    setTimeout(function () { btn.innerHTML = old; }, 1500);
                }).catch(function () { showToast('Copy failed', 'danger'); });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    btn.innerHTML = 'Copied';
                    setTimeout(function () { btn.innerHTML = 'Copy'; }, 1500);
                } catch (err) { showToast('Copy failed', 'danger'); }
                document.body.removeChild(ta);
            }
        }
    });

    window.addEventListener('resize', function () { /* no-op */ });

    // ── Geo lazy load ────────────────────────────────────────────────────────
    (function () {
        function loadGeo() {
            var spans = Array.from(document.querySelectorAll('.geo-lazy[data-ip]'));
            if (spans.length === 0) return;
            var ipMap = {};
            spans.forEach(function (s) {
                var ip = s.getAttribute('data-ip');
                if (!ip) return;
                if (!ipMap[ip]) ipMap[ip] = [];
                ipMap[ip].push(s);
            });
            var ips = Object.keys(ipMap);
            if (ips.length === 0) return;
            var chunks = [];
            for (var i = 0; i < ips.length; i += 100) chunks.push(ips.slice(i, i + 100));
            chunks.forEach(function (chunk) {
                fetch('http://ip-api.com/batch?fields=status,city,regionName,country,lat,lon,query', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(chunk.map(function (ip) { return { query: ip }; }))
                })
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    results.forEach(function (res) {
                        (ipMap[res.query] || []).forEach(function (span) {
                            if (res.status === 'success') {
                                var parts = [res.city, res.regionName, res.country].filter(Boolean);
                                var addr  = parts.join(', ');
                                var mapUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(res.lat + ',' + res.lon);
                                span.innerHTML = escHtml(addr) + ' <a href="' + escHtml(mapUrl) + '" target="_blank" rel="noopener" class="ms-1"><i class="fas fa-map-marker-alt text-primary"></i></a>';
                            } else {
                                span.innerHTML = '<span class="text-muted">-</span>';
                            }
                        });
                    });
                })
                .catch(function () {
                    chunk.forEach(function (ip) {
                        (ipMap[ip] || []).forEach(function (s) { s.innerHTML = '<span class="text-muted">-</span>'; });
                    });
                });
            });
        }
        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        document.addEventListener('DOMContentLoaded', loadGeo);
    })();

    var selectAllSessions = document.getElementById('selectAllSessions');
    if (selectAllSessions) {
        selectAllSessions.addEventListener('change', function () {
            var checked = !!this.checked;
            document.querySelectorAll('input[name="session_ids[]"]').forEach(function (cb) { cb.checked = checked; });
        });
    }

    (function () {
        var scopeType = document.getElementById('sessionScopeType');
        var userWrap = document.getElementById('sessionUserTargetWrap');
        var projectWrap = document.getElementById('sessionProjectTargetWrap');
        var userSelect = document.getElementById('sessionUserTarget');
        var projectSelect = document.getElementById('sessionProjectTarget');
        if (!scopeType || !userWrap || !projectWrap || !userSelect || !projectSelect) return;

        function syncScope() {
            if (scopeType.value === 'project') {
                userWrap.classList.add('d-none');
                projectWrap.classList.remove('d-none');
                userSelect.name = '';
                projectSelect.name = 'target_id';
            } else {
                projectWrap.classList.add('d-none');
                userWrap.classList.remove('d-none');
                projectSelect.name = '';
                userSelect.name = 'target_id';
            }
        }
        scopeType.addEventListener('change', syncScope);
        syncScope();
    })();

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm') || 'Are you sure?';
            e.preventDefault();
            if (typeof window.confirmModal === 'function') {
                window.confirmModal(msg, function () { form.submit(); });
                return;
            }
            var confirmFn = (typeof window._origConfirm === 'function') ? window._origConfirm : window.confirm;
            if (confirmFn(msg)) form.submit();
        });
    });
})();
