/**
 * login-activity.js
 * Extracted from modules/admin/login_activity.php inline script
 */

// Read more toggle for long user-agent strings in login activity
function isOverflowing(el) { return el && el.scrollWidth > el.clientWidth; }

function ensureUaButtonsLogin() {
    document.querySelectorAll('.ua-snippet').forEach(function (snippet) {
        var cell = snippet.closest('td');
        if (!cell) return;
        var btnToggle = cell.querySelector('.ua-toggle');
        var btnCopy = cell.querySelector('.ua-copy');
        var overflowing = isOverflowing(snippet);
        if (overflowing) {
            if (!btnToggle) {
                btnToggle = document.createElement('button');
                btnToggle.type = 'button';
                btnToggle.className = 'btn btn-link btn-sm ua-toggle';
                btnToggle.textContent = 'Read more';
            }
            if (!btnCopy) {
                btnCopy = document.createElement('button');
                btnCopy.type = 'button';
                btnCopy.className = 'btn btn-outline-secondary btn-sm ua-copy ms-2';
                btnCopy.title = 'Copy user-agent';
                btnCopy.textContent = 'Copy';
            }
            var container = cell.querySelector('.ua-actions');
            if (!container) {
                container = document.createElement('div');
                container.className = 'mt-1 ua-actions';
                snippet.after(container);
            }
            if (!container.contains(btnToggle)) container.appendChild(btnToggle);
            if (!container.contains(btnCopy)) container.appendChild(btnCopy);
            btnToggle.classList.remove('d-none');
            btnCopy.classList.remove('d-none');
        } else {
            if (btnToggle) btnToggle.classList.add('d-none');
            if (btnCopy) btnCopy.classList.add('d-none');
        }
    });
}

document.addEventListener('click', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('ua-toggle')) {
        var btn = e.target;
        var cell = btn.closest('td');
        if (!cell) return;
        var full = cell.querySelector('.ua-full');
        var snippet = cell.querySelector('.ua-snippet');
        if (full) {
            if (full.classList.contains('d-none')) {
                full.classList.remove('d-none');
                if (snippet) snippet.classList.add('d-none');
                btn.textContent = 'Read less';
            } else {
                full.classList.add('d-none');
                if (snippet) snippet.classList.remove('d-none');
                btn.textContent = 'Read more';
            }
        } else if (snippet) {
            var fullText = snippet.getAttribute('title') || snippet.textContent;
            if (btn.dataset.expanded === '1') {
                snippet.textContent = fullText.substring(0, 100) + (fullText.length > 100 ? '...' : '');
                btn.textContent = 'Read more';
                btn.dataset.expanded = '0';
            } else {
                snippet.textContent = fullText;
                btn.textContent = 'Read less';
                btn.dataset.expanded = '1';
            }
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
            } catch (e) { showToast('Copy failed', 'danger'); }
            document.body.removeChild(ta);
        }
    }
});

window.addEventListener('load', ensureUaButtonsLogin);
window.addEventListener('resize', function () { setTimeout(ensureUaButtonsLogin, 150); });

// ── Geo lazy load ────────────────────────────────────────────────────────────
// Loads location for rows where geo was not stored at login time.
// Uses ip-api.com (free, no key, 45 req/min batch endpoint).
(function () {
    function loadGeo() {
        var spans = Array.from(document.querySelectorAll('.geo-lazy[data-ip]'));
        if (spans.length === 0) return;

        // Deduplicate IPs
        var ipMap = {}; // ip -> [span, ...]
        spans.forEach(function (s) {
            var ip = s.getAttribute('data-ip');
            if (!ip) return;
            if (!ipMap[ip]) ipMap[ip] = [];
            ipMap[ip].push(s);
        });

        var ips = Object.keys(ipMap);
        if (ips.length === 0) return;

        // ip-api.com batch endpoint — max 100 IPs per request
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
                    var ip = res.query;
                    var targets = ipMap[ip] || [];
                    targets.forEach(function (span) {
                        if (res.status === 'success') {
                            var parts = [res.city, res.regionName, res.country].filter(Boolean);
                            var addr = parts.join(', ');
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
                    (ipMap[ip] || []).forEach(function (s) {
                        s.innerHTML = '<span class="text-muted" title="Geo lookup failed">-</span>';
                    });
                });
            });
        });
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.addEventListener('DOMContentLoaded', loadGeo);
})();

document.addEventListener('DOMContentLoaded', function () {
    var selectAllLoginLogs = document.getElementById('selectAllLoginLogs');
    if (selectAllLoginLogs) {
        selectAllLoginLogs.addEventListener('change', function () {
            var checked = !!this.checked;
            document.querySelectorAll('input[name="log_ids[]"]').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    }

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
});
