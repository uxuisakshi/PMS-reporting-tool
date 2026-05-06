/* Client Dashboard Widgets JS - extracted from modules/client/partials/dashboard_widgets.php */
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var tabButtons = Array.prototype.slice.call(document.querySelectorAll('[data-report-tab]'));
    var tabPanels = Array.prototype.slice.call(document.querySelectorAll('[data-report-panel]'));
    var commentsModalElement = document.getElementById('analyticsCommentsModal');

    if (commentsModalElement && commentsModalElement.parentNode !== document.body) {
        document.body.appendChild(commentsModalElement);
    }

    var commentsModal = commentsModalElement && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(commentsModalElement) : null;
    var commentsModalTitle = commentsModalElement ? commentsModalElement.querySelector('.modal-title') : null;
    var commentsModalLoading = commentsModalElement ? commentsModalElement.querySelector('.analytics-comments-loading') : null;
    var commentsModalList = commentsModalElement ? commentsModalElement.querySelector('.analytics-comments-list') : null;

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setCommentsModalState(state) {
        if (!commentsModalLoading || !commentsModalList) {
            return;
        }

        if (state === 'loading') {
            commentsModalLoading.classList.remove('d-none');
            commentsModalLoading.textContent = 'Loading comments...';
            commentsModalList.classList.add('d-none');
            commentsModalList.innerHTML = '';
            return;
        }

        commentsModalLoading.classList.add('d-none');
        commentsModalList.classList.remove('d-none');
    }

    function renderComments(comments) {
        if (!commentsModalList) {
            return;
        }

        if (!Array.isArray(comments) || !comments.length) {
            commentsModalList.innerHTML = '<div class="analytics-comments-empty text-muted">No comments available for this issue yet.</div>';
            return;
        }

        commentsModalList.innerHTML = comments.map(function(comment) {
            var author = escapeHtml(comment.user_name || 'Unknown user');
            var createdAt = escapeHtml(comment.created_at || '');
            var commentHtml = typeof comment.comment_html === 'string' ? comment.comment_html : '';
            var initials = author.trim() ? author.trim().charAt(0).toUpperCase() : 'U';

            return ''
                + '<article class="analytics-comment-item">'
                + '<div class="analytics-comment-avatar">' + initials + '</div>'
                + '<div class="analytics-comment-bubble">'
                + '<div class="analytics-comment-header">'
                + '<div class="analytics-comment-author">' + author + '</div>'
                + '<div class="analytics-comment-date">' + createdAt + '</div>'
                + '</div>'
                + '<div class="analytics-comment-body">' + commentHtml + '</div>'
                + '</div>'
                + '</article>';
        }).join('');
    }

    document.addEventListener('click', function(event) {
        var trigger = event.target.closest('[data-comment-modal-trigger]');
        if (!trigger) {
            return;
        }

        event.preventDefault();

        var fetchUrl = trigger.getAttribute('data-comment-fetch-url') || '';
        var issueTitle = trigger.getAttribute('data-comment-issue-title') || 'Issue comments';
        var issueKey = trigger.getAttribute('data-comment-issue-key') || '';

        if (!fetchUrl || !commentsModal) {
            if (typeof window.showToast === 'function') {
                window.showToast('Comments open nahi ho paaye.', 'warning');
            }
            return;
        }

        if (commentsModalTitle) {
            commentsModalTitle.textContent = issueKey ? issueKey + ' - ' + issueTitle : issueTitle;
        }

        setCommentsModalState('loading');
        commentsModal.show();

        window.fetch(fetchUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': window._csrfToken || ''
            },
            credentials: 'same-origin'
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Failed to load comments');
                }

                return response.json();
            })
            .then(function(payload) {
                if (!payload || payload.success !== true) {
                    throw new Error('Comments response invalid');
                }

                setCommentsModalState('ready');
                renderComments(payload.comments || []);
            })
            .catch(function() {
                setCommentsModalState('ready');
                if (commentsModalList) {
                    commentsModalList.innerHTML = '<div class="analytics-comments-empty text-muted">Comments could not be loaded. Please try again in a moment.</div>';
                }
            });
    });

    if (!tabButtons.length || !tabPanels.length) {
        return;
    }

    var activeReport = params.get('report');
    if (!activeReport || !document.querySelector('[data-report-tab="' + activeReport + '"]')) {
        activeReport = tabButtons[0].getAttribute('data-report-tab');
    }

    function setActiveReport(reportKey, shouldFocus) {
        tabButtons.forEach(function(button) {
            var isActive = button.getAttribute('data-report-tab') === reportKey;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        tabPanels.forEach(function(panel) {
            var isActive = panel.getAttribute('data-report-panel') === reportKey;
            panel.classList.toggle('is-active', isActive);
            if (isActive) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', 'hidden');
            }

            var widget = panel.querySelector('.dashboard-widget');
            if (widget) {
                widget.classList.toggle('is-active', isActive);
            }
        });

        var nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('report', reportKey);
        window.history.replaceState({}, '', nextUrl.toString());

        if (shouldFocus) {
            var activePanel = document.querySelector('[data-report-panel="' + reportKey + '"]');
            if (activePanel) {
                activePanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    tabButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            setActiveReport(button.getAttribute('data-report-tab'), false);
        });

        button.addEventListener('keydown', function(event) {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') {
                return;
            }

            event.preventDefault();
            var currentIndex = tabButtons.indexOf(button);
            var nextIndex = currentIndex;

            if (event.key === 'ArrowRight') {
                nextIndex = (currentIndex + 1) % tabButtons.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (currentIndex - 1 + tabButtons.length) % tabButtons.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabButtons.length - 1;
            }

            tabButtons[nextIndex].focus();
            setActiveReport(tabButtons[nextIndex].getAttribute('data-report-tab'), false);
        });
    });

    setActiveReport(activeReport, !!params.get('report'));
});
