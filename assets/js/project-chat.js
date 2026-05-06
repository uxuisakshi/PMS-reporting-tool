
(function(initFn){
    if (window.jQuery) { jQuery(initFn); } else { document.addEventListener('DOMContentLoaded', initFn); }
})(function(){
    const cfg = window._projectChatConfig || {};
    const hasJQ = !!window.jQuery;
    const $ = window.jQuery;
    const chatMessages = hasJQ ? $('#chatMessages') : document.querySelector('#chatMessages');
    const currentUserId = Number(cfg.currentUserId || 0);
    const currentUserRole = cfg.currentUserRole || '';
    const canViewHistoryAdmin = !!cfg.canViewHistoryAdmin;
    const projectId = cfg.projectId || null;
    const pageId = cfg.pageId || null;
    let lastMessageId = cfg.lastMessageId || 0;
    const mentionUsers = cfg.mentionUsers || [];
    const baseDir = cfg.baseDir || '';
    const mdRawList = Array.from(document.querySelectorAll('#mentionDropdown'));
    const mentionDropdownEl = mdRawList.length ? mdRawList[mdRawList.length - 1] : null;
    const mentionDropdown = hasJQ ? (mentionDropdownEl ? $(mentionDropdownEl) : $('#mentionDropdown')) : mentionDropdownEl;
    let mentionIndex = -1;
    let lastMentionAnchor = null;
    let lastMentionRange = null;
    let mentionSearchDisabled = false;
    let activeReplyToId = null;
    const recentUploadKeys = new Map();
    let suppressImageUploadUntil = 0;

    function getImageUploadKey(file) { if (!file) return ''; return [String(file.type||''),String(file.size||0),String(file.lastModified||0),String(file.name||'')].join('|'); }
    function isDuplicateImageUpload(file, windowMs) {
        const now = Date.now(); const ttl = Number(windowMs) > 0 ? Number(windowMs) : 2500;
        recentUploadKeys.forEach(function(ts, key) { if ((now - ts) > ttl) recentUploadKeys.delete(key); });
        const key = getImageUploadKey(file); if (!key) return false;
        const lastTs = recentUploadKeys.get(key);
        if (lastTs && (now - lastTs) <= ttl) return true;
        recentUploadKeys.set(key, now); return false;
    }
    function beginClipboardImageUploadSuppression(windowMs) { suppressImageUploadUntil = Date.now() + (Number(windowMs) > 0 ? Number(windowMs) : 1800); }
    function shouldSuppressImageUploadCallback() { return Date.now() < suppressImageUploadUntil; }
    function isMentionVisible() {
        if (hasJQ) return mentionDropdown && mentionDropdown.length && mentionDropdown.is(':visible');
        return mentionDropdown && mentionDropdown.style.display !== 'none';
    }
    function scrollToBottom() { try { if (hasJQ) { chatMessages.scrollTop(chatMessages[0].scrollHeight); } else if (chatMessages) { chatMessages.scrollTop = chatMessages.scrollHeight; } } catch (e) {} }
    scrollToBottom();
    function enhanceImages(container) {
        if (!container) return;
        const imgs = hasJQ ? $(container).find('.message-content img').toArray() : Array.from(container.querySelectorAll('.message-content img'));
        imgs.forEach(processImg);
    }
    function processImg(img) {
        if (!img) return;
        const src = img.getAttribute('src') || '';
        let wrap = (img.closest && img.closest('.chat-image-wrap')) || null;
        if (!wrap) { wrap = document.createElement('span'); wrap.className = 'chat-image-wrap'; if (img.parentNode) { img.parentNode.insertBefore(wrap, img); wrap.appendChild(img); } }
        if (!wrap) return;
        img.classList.add('chat-image-thumb');
        let btn = wrap.querySelector('.chat-image-full-btn');
        if (!btn && src) { btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn btn-light btn-sm chat-image-full-btn'; btn.dataset.src = src; btn.setAttribute('aria-label', 'View full image'); btn.innerHTML = '<i class="fas fa-up-right-from-square"></i>'; wrap.appendChild(btn); }
        else if (btn && src) { btn.dataset.src = src; }
    }
    function showImageModal(src) {
        if (!src) return;
        const modalEl = document.getElementById('chatImageModal'); const modalImg = document.getElementById('chatModalImg');
        if (modalImg) modalImg.src = src;
        if (window.bootstrap && modalEl) { bootstrap.Modal.getOrCreateInstance(modalEl).show(); } else { window.open(src, '_blank'); }
    }
    enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
    if (chatMessages && typeof MutationObserver !== 'undefined') {
        const obs = new MutationObserver(() => { enhanceImages(hasJQ ? chatMessages[0] : chatMessages); });
        obs.observe(hasJQ ? chatMessages[0] : chatMessages, { childList: true, subtree: true });
    }
    let enhanceTicks = 0;
    const enhanceInterval = setInterval(() => { enhanceImages(hasJQ ? chatMessages[0] : chatMessages); enhanceTicks++; if (enhanceTicks > 5) clearInterval(enhanceInterval); }, 800);
    function notifyChat(message, type) {
        if (typeof window.showToast === 'function') { window.showToast(message, type || 'info'); return; }
        if (typeof showChatActionStatusModal === 'function') { showChatActionStatusModal(message, type || 'info'); return; }
        if ((type || '') === 'danger') console.error(message); else console.log(message);
    }
    function syncEditorPlaceholder($editor) {
        if (!hasJQ || !$editor || !$editor.length || !$editor.data('summernote')) return;
        const $noteEditor = $editor.next('.note-editor'); if (!$noteEditor.length) return;
        const $editable = $noteEditor.find('.note-editable').first(); const $placeholder = $noteEditor.find('.note-placeholder').first();
        if (!$editable.length || !$placeholder.length) return;
        const html = String($editor.summernote('code') || '');
        const text = $('<div>').html(html).text().replace(/\u200B/g, '').replace(/\s+/g, ' ').trim();
        const hasImg = /<img\b[^>]*src=/i.test(html); const hasContent = !!(hasImg || text.length);
        $editable.toggleClass('note-empty', !hasContent); $placeholder.toggle(!hasContent);
    }
    const chatImageAltState = { pendingFile: null, pendingEditor: null, savedRange: null, isEditing: false, editingImg: null, lastPasteTime: 0 };
    function saveEditorRange($editor) { if (!hasJQ || !$editor || !$editor.length || !$editor.data('summernote')) return; try { $editor.summernote('editor.saveRange'); } catch (e) {} }
    function createChatImageAltButton() {
        if (!hasJQ || !$.summernote || !$.summernote.ui) return null;
        const ui = $.summernote.ui;
        return function(context) {
            return ui.button({ contents: '<i class="fas fa-tag"></i> <span style="font-size:0.75em;">Alt Text</span>', tooltip: 'Edit alt text', click: function() {
                const $img = $(context.invoke('restoreTarget')); if (!$img || !$img.length) return;
                chatImageAltState.isEditing = true; chatImageAltState.editingImg = $img;
                chatImageAltState.pendingFile = null; chatImageAltState.pendingEditor = null; chatImageAltState.savedRange = null;
                showChatImageAltModal($img.attr('alt') || '', true);
            }}).render();
        };
    }
    function showChatImageAltModal(currentAlt, isEditMode) {
        if (!window.bootstrap || !bootstrap.Modal) {
            const entered = window.prompt('Enter descriptive alt text for this image:', String(currentAlt || ''));
            if (entered === null) { chatImageAltState.pendingFile = null; chatImageAltState.pendingEditor = null; chatImageAltState.savedRange = null; chatImageAltState.isEditing = false; chatImageAltState.editingImg = null; return; }
            const altText = String(entered || '').trim() || 'Chat image';
            if (isEditMode && chatImageAltState.isEditing && chatImageAltState.editingImg && chatImageAltState.editingImg.length) { chatImageAltState.editingImg.attr('alt', altText); chatImageAltState.isEditing = false; chatImageAltState.editingImg = null; return; }
            const file = chatImageAltState.pendingFile; const $editor = chatImageAltState.pendingEditor; const savedRange = chatImageAltState.savedRange;
            chatImageAltState.pendingFile = null; chatImageAltState.pendingEditor = null; chatImageAltState.savedRange = null;
            if (file && $editor && $editor.length) { uploadAndInsertImage(file, $editor, altText, savedRange); }
            return;
        }
        let modalEl = document.getElementById('chatImageAltTextModal');
        if (!modalEl) {
            const modalHtml = '<div class="modal fade" id="chatImageAltTextModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Image Alt Text</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label for="chatImageAltTextInput" class="form-label">Enter descriptive alt text for this image:</label><input type="text" class="form-control" id="chatImageAltTextInput" placeholder="e.g., Screenshot showing login error"><div class="form-text">You can edit this later by clicking the image inside editor.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="chatConfirmAltTextBtn">Save</button></div></div></div></div>';
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            modalEl = document.getElementById('chatImageAltTextModal');
            const confirmBtn = document.getElementById('chatConfirmAltTextBtn'); const inputEl = document.getElementById('chatImageAltTextInput');
            if (confirmBtn && !confirmBtn.dataset.boundClick) { confirmBtn.addEventListener('click', function() { confirmChatImageAltText(); }); confirmBtn.dataset.boundClick = '1'; }
            if (inputEl && !inputEl.dataset.boundEnter) { inputEl.addEventListener('keydown', function(ev) { if (ev.key === 'Enter') { ev.preventDefault(); confirmChatImageAltText(); } }); inputEl.dataset.boundEnter = '1'; }
            if (modalEl && !modalEl.dataset.boundHidden) { modalEl.addEventListener('hidden.bs.modal', function() { chatImageAltState.pendingFile = null; chatImageAltState.pendingEditor = null; chatImageAltState.savedRange = null; chatImageAltState.isEditing = false; chatImageAltState.editingImg = null; }); modalEl.dataset.boundHidden = '1'; }
        }
        const input = document.getElementById('chatImageAltTextInput'); if (input) input.value = String(currentAlt || '');
        const saveBtn = document.getElementById('chatConfirmAltTextBtn'); if (saveBtn) saveBtn.textContent = isEditMode ? 'Save Alt Text' : 'Upload Image';
        if (modalEl && window.bootstrap) { const modal = bootstrap.Modal.getOrCreateInstance(modalEl); modal.show(); setTimeout(function() { try { if (input) input.focus(); } catch (e) {} }, 50); }
    }
    function confirmChatImageAltText() {
        const input = document.getElementById('chatImageAltTextInput');
        const altText = String((input && input.value) ? input.value : '').trim() || 'Chat image';
        const modalEl = document.getElementById('chatImageAltTextModal');
        if (chatImageAltState.isEditing && chatImageAltState.editingImg && chatImageAltState.editingImg.length) { chatImageAltState.editingImg.attr('alt', altText); chatImageAltState.isEditing = false; chatImageAltState.editingImg = null; if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide(); return; }
        const file = chatImageAltState.pendingFile; const $editor = chatImageAltState.pendingEditor; const savedRange = chatImageAltState.savedRange;
        chatImageAltState.pendingFile = null; chatImageAltState.pendingEditor = null; chatImageAltState.savedRange = null;
        if (!file || !$editor || !$editor.length) { if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide(); return; }
        uploadAndInsertImage(file, $editor, altText, savedRange);
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
    function queueChatImageUpload(file, $targetEditor) {
        if (!file || !file.type || String(file.type).indexOf('image/') !== 0) return;
        if (isDuplicateImageUpload(file, 2500)) return;
        const now = Date.now(); if ((now - chatImageAltState.lastPasteTime) < 350) return;
        chatImageAltState.lastPasteTime = now;
        const $target = ($targetEditor && $targetEditor.length) ? $targetEditor : $msg;
        saveEditorRange($target);
        chatImageAltState.pendingFile = file; chatImageAltState.pendingEditor = $target;
        chatImageAltState.savedRange = ($target && $target.length && $target.data('summernote')) ? $target.summernote('createRange') : null;
        chatImageAltState.isEditing = false; chatImageAltState.editingImg = null;
        showChatImageAltModal('', false);
    }
    function extractClipboardImageFilesLocal(e) {
        if (window.PMSSummernoteImage && typeof window.PMSSummernoteImage.extractClipboardImageFiles === 'function') { return window.PMSSummernoteImage.extractClipboardImageFiles(e) || []; }
        const ev = e && (e.originalEvent || e); const clipboard = ev && ev.clipboardData; const out = [];
        if (!clipboard || !clipboard.items) return out;
        for (let i = 0; i < clipboard.items.length; i++) { const item = clipboard.items[i]; if (item && item.type && item.type.indexOf('image') === 0 && item.getAsFile) { const f = item.getAsFile(); if (f) out.push(f); } }
        return out;
    }
    function uploadAndInsertImage(file, $targetEditor, altTextRaw, savedRange) {
        const $target = ($targetEditor && $targetEditor.length) ? $targetEditor : $msg;
        const uploadUrl = baseDir + '/api/chat_upload_image.php'; const fallbackUploadUrl = baseDir + '/api/issue_upload_image.php';
        const rawAltText = String(altTextRaw || '').trim();
        const altText = (rawAltText || 'Chat image').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        function insertUploadedUrl(url) {
            if ($target && $target.length && $target.data('summernote')) {
                const imgHtml = '<p><img src="' + url + '" alt="' + altText + '" class="chat-image-thumb editable-chat-image" style="max-width:100%;width:100%;max-height:240px;height:auto;object-fit:contain;border-radius:10px;cursor:pointer;" /></p>';
                try { $target.summernote('focus'); } catch (e) {}
                let restored = false;
                if (savedRange && typeof savedRange.select === 'function') { try { savedRange.select(); restored = true; } catch (e) {} }
                if (!restored) { try { $target.summernote('editor.restoreRange'); restored = true; } catch (e) {} }
                if (!restored) { try { $target.summernote('restoreRange'); } catch (e) {} }
                $target.summernote('pasteHTML', imgHtml); saveEditorRange($target); syncEditorPlaceholder($target);
                if (typeof updateCharCount === 'function' && String($target.attr('id') || '') === 'message') updateCharCount();
                if (typeof updateEditCharCount === 'function' && String($target.attr('id') || '') === 'chatEditMessageInput') updateEditCharCount();
                return true;
            }
            return false;
        }
        function tryUploadVia(url) {
            if (window.PMSSummernoteImage && typeof window.PMSSummernoteImage.uploadImage === 'function') { return window.PMSSummernoteImage.uploadImage(file, { uploadUrl: url, credentials: 'same-origin' }); }
            const formData = new FormData(); formData.append('image', file);
            return fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' }).then(res => res.text()).then(txt => { try { return JSON.parse(txt); } catch (e) { return { error: 'Upload failed' }; } });
        }
        if (window.PMSSummernoteImage && typeof window.PMSSummernoteImage.uploadImage === 'function') {
            return tryUploadVia(uploadUrl).then(function(res) {
                if (res && res.success && res.url && insertUploadedUrl(res.url)) return;
                return tryUploadVia(fallbackUploadUrl).then(function(res2) { if (res2 && res2.success && res2.url && insertUploadedUrl(res2.url)) return; if (res2 && !res2.skipped) notifyChat((res2 && res2.error) ? res2.error : 'Image upload failed', 'danger'); });
            }).catch(function() { notifyChat('Image upload failed', 'danger'); });
        }
        if (!file) return;
        if (file.type && !file.type.startsWith('image/')) { notifyChat('Only image files are allowed', 'warning'); return; }
        const formData = new FormData(); formData.append('image', file);
        return fetch(uploadUrl, { method: 'POST', body: formData, credentials: 'same-origin' }).then(res => res.text()).then(txt => {
            let res = {}; try { res = JSON.parse(txt); } catch (e) { res = { error: 'Upload failed' }; }
            if (res && res.success && res.url) { insertUploadedUrl(res.url); return; }
            const fd2 = new FormData(); fd2.append('image', file);
            return fetch(fallbackUploadUrl, { method: 'POST', body: fd2, credentials: 'same-origin' }).then(r2 => r2.text()).then(t2 => {
                let res2 = {}; try { res2 = JSON.parse(t2); } catch (e) { res2 = { error: 'Upload failed' }; }
                if (res2 && res2.success && res2.url) { insertUploadedUrl(res2.url); } else { notifyChat((res2 && res2.error) ? res2.error : 'Image upload failed', 'danger'); }
            });
        }).catch(() => notifyChat('Image upload failed', 'danger'));
    }

    let summernoteReady = false;
    const $msg = hasJQ ? $('#message') : null;
    const isEmbed = document.body.classList.contains('chat-embed');
    const composeBody = document.getElementById('composeBody');
    let composeToggle = document.getElementById('composeToggle');
    const composeForm = document.getElementById('chatForm');
    if (isEmbed && !composeToggle && composeForm) {
        composeToggle = document.createElement('button'); composeToggle.type = 'button'; composeToggle.id = 'composeToggle';
        composeToggle.className = 'btn btn-sm chat-compose-toggle'; composeToggle.innerHTML = '<i class="fas fa-comment-dots"></i> Compose';
        if (composeBody && composeBody.parentNode === composeForm) { composeForm.insertBefore(composeToggle, composeBody); } else { composeForm.appendChild(composeToggle); }
    }
    let composeCollapsed = isEmbed ? true : false;
    let messageKeyboardBound = false;
    let initialRecentMessageFocused = false;
    function focusFirstComposeControl() {
        if (composeCollapsed) return;
        let target = null;
        if (summernoteReady && $msg && $msg.length) { const $toolbarItems = $msg.next('.note-editor').find('.note-toolbar .note-btn-group button').filter(function() { const $b = $(this); return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length; }); if ($toolbarItems.length) target = $toolbarItems.get(0); }
        if (!target) { target = document.querySelector('#composeBody #message, #composeBody .note-editable, #composeBody #sendBtn'); }
        if (!target && summernoteReady && $msg && $msg.length) { const editable = $msg.next('.note-editor').find('.note-editable[contenteditable="true"]').get(0); if (editable) target = editable; }
        if (target && typeof target.focus === 'function') { try { target.focus(); } catch (e) {} }
    }
    function focusComposeEditable() {
        let target = null;
        if (summernoteReady && $msg && $msg.length) { target = $msg.next('.note-editor').find('.note-editable[contenteditable="true"]').get(0); }
        if (!target) { target = document.querySelector('#composeBody #message, #composeBody .note-editable'); }
        if (target && typeof target.focus === 'function') { try { target.focus(); } catch (e) {} }
    }
    function applyEmbedTabOrder() {
        if (!isEmbed) return;
        if (composeToggle && composeToggle.hasAttribute('tabindex')) { composeToggle.removeAttribute('tabindex'); }
        const composeInteractive = document.querySelectorAll('#composeBody button, #composeBody input, #composeBody textarea, #composeBody select, #composeBody [contenteditable="true"]');
        composeInteractive.forEach(function(el) { if (!el) return; if (el.id === 'composeToggle') return; if (el.classList && el.classList.contains('note-btn')) return; if (el.hasAttribute('tabindex')) el.removeAttribute('tabindex'); });
        const rows = document.querySelectorAll('#chatMessages .message');
        rows.forEach(function(row) { if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', '-1'); });
    }
    function getMessageRows() { return Array.from(document.querySelectorAll('#chatMessages .message')); }
    function getMessageActionButtons(row) { if (!row || !row.querySelectorAll) return []; return Array.from(row.querySelectorAll('.message-actions button:not([disabled])')); }
    function setRowActionTabStops(activeRow) { const rows = getMessageRows(); rows.forEach(function(row) { const actions = getMessageActionButtons(row); actions.forEach(function(btn) { btn.setAttribute('tabindex', row === activeRow ? '0' : '-1'); }); }); }
    function setActiveMessageRow(row, shouldFocus) { const rows = getMessageRows(); rows.forEach(function(r) { r.setAttribute('tabindex', '-1'); }); if (row) row.setAttribute('tabindex', '0'); setRowActionTabStops(row || null); if (row && shouldFocus) { try { row.focus(); } catch (e) {} } }
    function ensureRecentMessageAnchor(shouldFocus) { const rows = getMessageRows(); if (!rows.length) return; setActiveMessageRow(rows[rows.length - 1], !!shouldFocus); }
    function bindMessageKeyboardNavigation() {
        const host = hasJQ ? (chatMessages && chatMessages[0]) : chatMessages;
        if (!host || messageKeyboardBound) return;
        messageKeyboardBound = true;
        host.addEventListener('focusin', function(e) { const row = e.target && e.target.closest ? e.target.closest('.message') : null; if (row) setActiveMessageRow(row, false); });
        host.addEventListener('keydown', function(e) {
            const target = e.target; const row = target && target.closest ? target.closest('.message') : null; if (!row) return;
            const rows = getMessageRows(); const idx = rows.indexOf(row); if (idx < 0) return;
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); setActiveMessageRow(rows[e.key === 'ArrowUp' ? Math.max(0, idx - 1) : Math.min(rows.length - 1, idx + 1)], true); return; }
            if (e.key === 'Home') { e.preventDefault(); setActiveMessageRow(rows[0], true); return; }
            if (e.key === 'End') { e.preventDefault(); setActiveMessageRow(rows[rows.length - 1], true); return; }
            if ((e.key === 'Enter' || e.key === ' ') && target.classList && target.classList.contains('message')) { const firstAction = getMessageActionButtons(row)[0]; if (firstAction) { e.preventDefault(); firstAction.focus(); } return; }
            if (e.key === 'Tab' && !e.shiftKey && target.classList && target.classList.contains('message')) { const firstAction = getMessageActionButtons(row)[0]; if (firstAction) { e.preventDefault(); firstAction.focus(); } return; }
            if (e.key === 'Tab' && e.shiftKey && target.closest && target.closest('.message-actions')) { const actions = getMessageActionButtons(row); if (actions.length && target === actions[0]) { e.preventDefault(); setActiveMessageRow(row, true); } }
        });
    }
    function updateComposeCollapse() {
        if (!composeBody) return;
        if (composeCollapsed) { composeBody.classList.remove('open'); hideMentionDropdown(); } else { composeBody.classList.add('open'); }
        if (composeForm) { if (composeCollapsed) composeForm.classList.add('collapsed'); else composeForm.classList.remove('collapsed'); }
        if (composeToggle) { composeToggle.classList.toggle('expanded', !composeCollapsed); composeToggle.innerHTML = composeCollapsed ? '<i class="fas fa-comment-dots"></i> Compose' : '<i class="fas fa-chevron-down"></i> Hide Compose'; }
        applyEmbedTabOrder();
    }
    function toggleCompose(nextState) { if (typeof nextState === 'boolean') { composeCollapsed = !nextState; } else { composeCollapsed = !composeCollapsed; } updateComposeCollapse(); }
    updateComposeCollapse();
    if (composeToggle) {
        composeToggle.type = 'button';
        composeToggle.addEventListener('click', function(){ toggleCompose(); setTimeout(function() { try { composeToggle.focus(); } catch (e) {} }, 0); });
        composeToggle.addEventListener('keydown', function(e) {
            if (!e) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); toggleCompose(); return; }
            if (composeCollapsed) return;
            if (e.key === 'Tab' && !e.shiftKey) { e.preventDefault(); focusFirstComposeControl(); }
        });
    }
    if (hasJQ && $.fn.summernote) {
        try {
            $msg.summernote({
                placeholder: 'Type your message here... Use @username to mention someone.',
                tabsize: 2, height: isEmbed ? 80 : 200,
                popover: { image: [['image',['resizeFull','resizeHalf','resizeQuarter','resizeNone']],['float',['floatLeft','floatRight','floatNone']],['remove',['removeMedia']],['custom',['imageAltText']]] },
                buttons: { imageAltText: createChatImageAltButton() },
                toolbar: [['style',['style']],['font',['bold','italic','underline','clear']],['fontname',['fontname']],['color',['color']],['para',['ul','ol','paragraph']],['table',['table']],['insert',['link','picture','video']],['view',['codeview','help']]],
                callbacks: {
                    onInit: function() { setTimeout(function() { enableToolbarKeyboardA11y($msg); }, 0); setTimeout(function() { enableToolbarKeyboardA11y($msg); }, 200); syncEditorPlaceholder($msg); saveEditorRange($msg); },
                    onKeydown: function(e) { if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) { e.preventDefault(); focusEditorToolbar($msg); } },
                    onImageUpload: function(files) { if (shouldSuppressImageUploadCallback()) return; var list = files || []; for (var i = 0; i < list.length; i++) { queueChatImageUpload(list[i], $msg); } },
                    onPaste: function(e) {
                        const files = extractClipboardImageFilesLocal(e);
                        if (files.length) {
                            beginClipboardImageUploadSuppression(2200); e.preventDefault();
                            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                            if (typeof e.stopPropagation === 'function') e.stopPropagation();
                            const oe = e && e.originalEvent;
                            if (oe && typeof oe.stopImmediatePropagation === 'function') oe.stopImmediatePropagation();
                            if (oe && typeof oe.stopPropagation === 'function') oe.stopPropagation();
                            for (let i = 0; i < files.length; i++) { queueChatImageUpload(files[i], $msg); }
                        }
                    }
                }
            });
            summernoteReady = true;
            $msg.on('summernote.keyup summernote.mouseup summernote.focus', function() { saveEditorRange($msg); });
            if (isEmbed && composeCollapsed) { $msg.summernote('reset'); }
        } catch (err) { console.warn('Summernote init failed, using plain textarea', err); }
    }
    function getMessageHtml() { if (summernoteReady) return $msg.summernote('code'); if (hasJQ) return $msg.val(); const el = document.getElementById('message'); return el ? el.value : ''; }
    function setMessageHtml(val) { if (summernoteReady) { const out = $msg.summernote('code', val); syncEditorPlaceholder($msg); return out; } if (hasJQ) return $msg.val(val); const el = document.getElementById('message'); if (el) el.value = val; }
    function updateCharCount() {
        const raw = getMessageHtml();
        const text = (hasJQ ? $('<div>').html(raw).text() : (new DOMParser().parseFromString(raw, 'text/html').documentElement.textContent || ''));
        const length = text.length;
        if (hasJQ) { $('#charCount').text(length + '/1000'); $('#message').toggleClass('is-invalid', length > 1000); }
        else { const cc = document.getElementById('charCount'); if (cc) cc.textContent = length + '/1000'; const msgEl = document.getElementById('message'); if (msgEl) msgEl.classList.toggle('is-invalid', length > 1000); }
    }
    if (summernoteReady) { $msg.on('summernote.change', function() { updateCharCount(); syncEditorPlaceholder($msg); }); }
    else if (hasJQ) { $msg.on('input', updateCharCount); }
    else { const el = document.getElementById('message'); if (el) el.addEventListener('input', updateCharCount); }
    updateCharCount();
    if (summernoteReady) syncEditorPlaceholder($msg);
    const $editMsg = hasJQ ? $('#chatEditMessageInput') : null;
    let editSummernoteReady = false;
    function getEditMessageHtml() { if (editSummernoteReady && $editMsg && $editMsg.length) return $editMsg.summernote('code'); const el = document.getElementById('chatEditMessageInput'); return el ? el.value : ''; }
    function setEditMessageHtml(val) { if (editSummernoteReady && $editMsg && $editMsg.length) { const out = $editMsg.summernote('code', val || ''); syncEditorPlaceholder($editMsg); return out; } const el = document.getElementById('chatEditMessageInput'); if (el) el.value = val || ''; }
    function updateEditCharCount() { const raw = getEditMessageHtml(); const text = (hasJQ ? $('<div>').html(raw).text() : (new DOMParser().parseFromString(raw, 'text/html').documentElement.textContent || '')); const countEl = document.getElementById('chatEditCharCount'); if (countEl) countEl.textContent = text.length + '/1000'; if (editSummernoteReady && $editMsg && $editMsg.length) syncEditorPlaceholder($editMsg); }
    if (hasJQ && $.fn.summernote && $editMsg && $editMsg.length) {
        try {
            $editMsg.summernote({
                placeholder: 'Edit message...', tabsize: 2, height: 160,
                popover: { image: [['image',['resizeFull','resizeHalf','resizeQuarter','resizeNone']],['float',['floatLeft','floatRight','floatNone']],['remove',['removeMedia']],['custom',['imageAltText']]] },
                buttons: { imageAltText: createChatImageAltButton() },
                toolbar: [['style',['style']],['font',['bold','italic','underline','clear']],['fontname',['fontname']],['color',['color']],['para',['ul','ol','paragraph']],['table',['table']],['insert',['link','picture','video']],['view',['codeview','help']]],
                callbacks: {
                    onInit: function() { editSummernoteReady = true; setTimeout(function() { enableToolbarKeyboardA11y($editMsg); }, 0); setTimeout(function() { enableToolbarKeyboardA11y($editMsg); }, 200); updateEditCharCount(); syncEditorPlaceholder($editMsg); saveEditorRange($editMsg); },
                    onKeydown: function(e) { if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) { e.preventDefault(); focusEditorToolbar($editMsg); } },
                    onImageUpload: function(files) { if (shouldSuppressImageUploadCallback()) return; var list = files || []; for (var i = 0; i < list.length; i++) { queueChatImageUpload(list[i], $editMsg); } },
                    onPaste: function(e) {
                        const files = extractClipboardImageFilesLocal(e);
                        if (files.length) {
                            beginClipboardImageUploadSuppression(2200); e.preventDefault();
                            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                            if (typeof e.stopPropagation === 'function') e.stopPropagation();
                            const oe = e && e.originalEvent;
                            if (oe && typeof oe.stopImmediatePropagation === 'function') oe.stopImmediatePropagation();
                            if (oe && typeof oe.stopPropagation === 'function') oe.stopPropagation();
                            for (let i = 0; i < files.length; i++) { queueChatImageUpload(files[i], $editMsg); }
                        }
                    },
                    onChange: function() { updateEditCharCount(); }
                }
            });
            editSummernoteReady = true;
            $editMsg.on('summernote.keyup summernote.mouseup summernote.focus', function() { saveEditorRange($editMsg); });
        } catch (e) { editSummernoteReady = false; }
    }
    if (hasJQ && $('#chatForm').length) {
        $('#chatForm').prepend('<input type="hidden" id="chatReplyTo" name="reply_to" value="">\n<div id="chatReplyPreview" style="display:none;" class="mb-2"><div class="small text-muted">Replying to <strong class="reply-user"></strong> <button type="button" class="btn btn-sm btn-link p-0" id="chatCancelReply">Cancel</button></div><div class="reply-preview p-2 rounded bg-light small"></div></div>');
    }

        if (hasJQ) {
            $(document).on('click', '.mention-user', function() {
                const username = $(this).data('username');
                const textarea = $('#message');
                const cursorPos = textarea[0].selectionStart;
                const current = textarea.val();
                const textBefore = current.substring(0, cursorPos);
                const textAfter = current.substring(cursorPos);
                const needsLeadingSpace = textBefore.length > 0 && !/\s$/.test(textBefore);
                const insertText = (needsLeadingSpace ? ' ' : '') + username + ' ';
                textarea.val(textBefore + insertText + textAfter).focus();
                textarea[0].selectionStart = textarea[0].selectionEnd = cursorPos + insertText.length;
            });

            $(document).on('click', '.note-editor .note-editable img', function(e) {
                const $img = $(this);
                e.preventDefault();
                e.stopPropagation();
                chatImageAltState.isEditing = true;
                chatImageAltState.editingImg = $img;
                chatImageAltState.pendingFile = null;
                chatImageAltState.pendingEditor = null;
                chatImageAltState.savedRange = null;
                showChatImageAltModal($img.attr('alt') || '', true);
            });

            $(document).on('click', '.chat-reply', function(){
                const mid = $(this).data('mid');
                const username = $(this).data('username');
                const message = $(this).data('message');
                if(!mid) return;
                const parsedMid = parseInt(mid, 10);
                activeReplyToId = Number.isFinite(parsedMid) && parsedMid > 0 ? parsedMid : null;
                if (isEmbed && composeCollapsed) { composeCollapsed = false; updateComposeCollapse(); }
                $('#chatReplyTo').val(activeReplyToId ? String(activeReplyToId) : '');
                $('#chatReplyPreview').attr('data-reply-id', activeReplyToId ? String(activeReplyToId) : '');
                $('#chatReplyPreview .reply-user').text(username);
                $('#chatReplyPreview .reply-preview').html(message);
                $('#chatReplyPreview').show();
                $('#message').summernote && $('#message').summernote('focus');
            });

            $(document).on('click', '#chatCancelReply', function(){
                activeReplyToId = null;
                $('#chatReplyTo').val('');
                $('#chatReplyPreview').attr('data-reply-id', '');
                $('#chatReplyPreview').hide();
                $('#chatReplyPreview .reply-preview').html('');
            });

            function clearReplyState() {
                activeReplyToId = null;
                $('#chatReplyTo').val('');
                $('#chatReplyPreview').attr('data-reply-id', '');
                $('#chatReplyPreview').hide();
                $('#chatReplyPreview .reply-user').text('');
                $('#chatReplyPreview .reply-preview').html('');
            }

            function updateMessageInDom(msg) {
                if (!msg || !msg.id) return;
                const $row = $('.message[data-id="' + msg.id + '"]');
                if (!$row.length) return;
                const $content = $row.find('.message-content').first();
                if (!$content.length) return;
                const replyHtml = $content.find('.reply-preview').first().prop('outerHTML') || '';
                const metaHtml = '<div class="message-meta">' + (msg.created_at || '') + '</div>';
                $content.html(replyHtml + (msg.message || '') + metaHtml);
                $row.find('.chat-edit').attr('data-message', msg.message || '');
                const deletedPlain = $('<div>').html(msg.message || '').text().replace(/\s+/g, ' ').trim().toLowerCase() === 'message deleted';
                if (!msg.can_edit || deletedPlain) $row.find('.chat-edit').remove();
                if (!msg.can_delete || deletedPlain) $row.find('.chat-delete').remove();
                const $actions = $row.find('.message-actions').first();
                const $historyBtn = $row.find('.chat-history');
                if (canViewHistoryAdmin) {
                    if (!$historyBtn.length && $actions.length) {
                        $actions.append('<button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="' + msg.id + '"><i class="fas fa-history"></i></button>');
                    } else if ($historyBtn.length) {
                        $historyBtn.attr('data-mid', msg.id);
                    }
                } else if ($historyBtn.length) {
                    $historyBtn.remove();
                }
                enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
                applyEmbedTabOrder();
            }
            window.chatUpdateMessageInDom = updateMessageInDom;

            function showMessageHistory(messageId) {
                if (!canViewHistoryAdmin) return;
                const body = document.getElementById('chatHistoryBody');
                if (body) body.innerHTML = '<p class="text-muted mb-0">Loading...</p>';
                const historyUrl = new URL(window.location.href);
                historyUrl.searchParams.set('action', 'get_message_history');
                historyUrl.searchParams.set('message_id', String(messageId));
                fetch(historyUrl.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function(res) { return res.json(); })
                    .then(function(res) {
                        if (!body) return;
                        if (!res || !res.success) { body.innerHTML = '<p class="text-danger mb-0">Failed to load history.</p>'; return; }
                        const rows = res.history || [];
                        if (!rows.length) { body.innerHTML = '<p class="text-muted mb-0">No history available.</p>'; return; }
                        let html = '<div class="list-group">';
                        rows.forEach(function(h) {
                            html += '<div class="list-group-item">';
                            html += '<div class="d-flex justify-content-between mb-2"><strong>' + escapeHtml((h.action_type || '').toUpperCase()) + '</strong>';
                            html += '<small class="text-muted">' + escapeHtml(h.acted_at || '') + ' by ' + escapeHtml(h.acted_by_name || 'Unknown') + '</small></div>';
                            html += '<div class="small text-muted mb-1">Old</div><div class="border rounded p-2 mb-2">' + (h.old_message || '') + '</div>';
                            html += '<div class="small text-muted mb-1">New</div><div class="border rounded p-2">' + (h.new_message || '') + '</div>';
                            html += '</div>';
                        });
                        html += '</div>';
                        body.innerHTML = html;
                    }).catch(function() { if (body) body.innerHTML = '<p class="text-danger mb-0">Failed to load history.</p>'; });
                const modalEl = document.getElementById('chatHistoryModal');
                if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            $(document).on('click', '.chat-edit', function() {
                const mid = Number($(this).data('mid'));
                if (!mid) return;
                const current = $(this).data('message') || '';
                const editInput = document.getElementById('chatEditMessageInput');
                const editId = document.getElementById('chatEditMessageId');
                const modalEl = document.getElementById('chatEditModal');
                if (!editInput || !editId || !modalEl || !window.bootstrap) return;
                editId.value = String(mid);
                setEditMessageHtml(current);
                updateEditCharCount();
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                setTimeout(function() {
                    try {
                        if (editSummernoteReady && $editMsg && $editMsg.length) $editMsg.summernote('focus');
                        else editInput.focus();
                    } catch (e) {}
                }, 120);
            });

            $(document).on('click', '.chat-delete', function() {
                const mid = Number($(this).data('mid'));
                if (!mid) return;
                const deleteId = document.getElementById('chatDeleteMessageId');
                const modalEl = document.getElementById('chatDeleteModal');
                if (!deleteId || !modalEl || !window.bootstrap) return;
                deleteId.value = String(mid);
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });

            $(document).on('click', '.chat-history', function() {
                const mid = Number($(this).data('mid'));
                if (!mid) return;
                showMessageHistory(mid);
            });

            $('#chatEditModal').on('hidden.bs.modal', function() {
                const editId = document.getElementById('chatEditMessageId');
                if (editId) editId.value = '';
                setEditMessageHtml('');
                updateEditCharCount();
            });
        }

        function toastSafe(message, type) {
            if (typeof showToast === 'function') { showToast(message, type || 'info'); return; }
            showChatActionStatusModal(message || 'Action failed', type || 'info');
        }

        function showChatActionStatusModal(message, type) {
            const textEl = document.getElementById('chatActionStatusText');
            const titleEl = document.getElementById('chatActionStatusTitle');
            const modalEl = document.getElementById('chatActionStatusModal');
            const isSuccess = String(type || '').toLowerCase() === 'success';
            if (titleEl) titleEl.textContent = isSuccess ? 'Success' : 'Action Failed';
            if (textEl) textEl.textContent = String(message || (isSuccess ? 'Action completed.' : 'Action failed'));
            if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        function saveEditedMessage() {
            const editId = document.getElementById('chatEditMessageId');
            const modalEl = document.getElementById('chatEditModal');
            const saveBtn = document.getElementById('chatEditSaveBtn');
            const mid = Number(editId ? editId.value : 0);
            const edited = getEditMessageHtml();
            const editedText = (hasJQ ? $('<div>').html(edited).text().trim() : (new DOMParser().parseFromString(edited, 'text/html').documentElement.textContent || '').trim());
            const hasImg = /<img\b[^>]*src=/i.test(edited || '');
            if (!mid) return;
            if (!editedText && !hasImg) { toastSafe('Message cannot be empty', 'warning'); return; }
            if (saveBtn) saveBtn.disabled = true;
            const payload = new URLSearchParams();
            payload.append('edit_message', '1');
            payload.append('message_id', String(mid));
            payload.append('message', edited);
            const _csrfEdit = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : (window._csrfToken || '');
            if (_csrfEdit) payload.append('csrf_token', _csrfEdit);
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: payload.toString()
            }).then(function(res) { return res.text().then(function(t) { try { return JSON.parse(t); } catch(e) { return { success: false, error: 'Invalid response' }; } }); }).then(function(res) {
                if (res && res.success) {
                    if (res.message && typeof window.chatUpdateMessageInDom === 'function') window.chatUpdateMessageInDom(res.message);
                    else fetchMessages();
                    toastSafe('Message updated', 'success');
                    if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                } else {
                    toastSafe((res && res.error) ? res.error : 'Failed to edit message', 'danger');
                }
            }).catch(function() { toastSafe('Failed to edit message', 'danger'); })
            .finally(function() { if (saveBtn) saveBtn.disabled = false; });
        }

        function confirmDeleteMessage() {
            const deleteId = document.getElementById('chatDeleteMessageId');
            const modalEl = document.getElementById('chatDeleteModal');
            const deleteBtn = document.getElementById('chatDeleteConfirmBtn');
            const mid = Number(deleteId ? deleteId.value : 0);
            if (!mid) return;
            if (deleteBtn) deleteBtn.disabled = true;
            const payload = new URLSearchParams();
            payload.append('delete_message', '1');
            payload.append('message_id', String(mid));
            const _csrfDel = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : (window._csrfToken || '');
            if (_csrfDel) payload.append('csrf_token', _csrfDel);
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: payload.toString()
            }).then(function(res) { return res.text().then(function(t) { try { return JSON.parse(t); } catch(e) { return { success: false, error: 'Invalid response' }; } }); }).then(function(res) {
                if (res && res.success) {
                    if (res.message && typeof window.chatUpdateMessageInDom === 'function') window.chatUpdateMessageInDom(res.message);
                    else fetchMessages();
                    toastSafe('Message deleted', 'success');
                    if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                } else {
                    toastSafe((res && res.error) ? res.error : 'Failed to delete message', 'danger');
                }
            }).catch(function() { toastSafe('Failed to delete message', 'danger'); })
            .finally(function() { if (deleteBtn) deleteBtn.disabled = false; });
        }

        const chatEditSaveBtnEl = document.getElementById('chatEditSaveBtn');
        if (chatEditSaveBtnEl && !chatEditSaveBtnEl.dataset.boundClick) {
            chatEditSaveBtnEl.addEventListener('click', function(e) { e.preventDefault(); saveEditedMessage(); });
            chatEditSaveBtnEl.dataset.boundClick = '1';
        }

        const chatDeleteConfirmBtnEl = document.getElementById('chatDeleteConfirmBtn');
        if (chatDeleteConfirmBtnEl && !chatDeleteConfirmBtnEl.dataset.boundClick) {
            chatDeleteConfirmBtnEl.addEventListener('click', function(e) { e.preventDefault(); confirmDeleteMessage(); });
            chatDeleteConfirmBtnEl.dataset.boundClick = '1';
        }

        function sendChatMessage() {
            function hardFallbackSubmit(messageHtml, replyToVal) {
                // In embed mode, form submit would navigate the iframe and show raw JSON.
                // Skip form submit and show error instead.
                if (isEmbed) {
                    if (typeof showToast === 'function') showToast('Failed to send message. Please try again.', 'danger');
                    return;
                }
                try {
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.action = window.location.pathname + window.location.search;
                    f.style.display = 'none';
                    const fields = {
                        send_message: '1',
                        project_id: String(projectId || ''),
                        page_id: String(pageId || ''),
                        message: String(messageHtml || ''),
                        reply_to: String(replyToVal || ''),
                        reply_token: replyToVal ? ('r:' + String(replyToVal)) : '',
                        csrf_token: (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute ? (document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '') : (window._csrfToken || '')
                    };
                    Object.keys(fields).forEach(function(k) {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = k; input.value = fields[k];
                        f.appendChild(input);
                    });
                    document.body.appendChild(f);
                    f.submit();
                } catch (e) {
                    if (typeof showToast === 'function') showToast('Failed to send message', 'danger');
                }
            }

            try {
                const msg = getMessageHtml();
                const textOnly = hasJQ ? $('<div>').html(msg).text().trim() : (new DOMParser().parseFromString(msg, 'text/html').documentElement.textContent || '').trim();
                const hasImg = /<img\b[^>]*src=/i.test(msg);
                if (!textOnly && !hasImg) { if (typeof showToast === 'function') showToast('Type a message to send', 'warning'); return; }
                hideMentionDropdown();
                if (hasJQ) $('#sendBtn').prop('disabled', true); else { const b = document.getElementById('sendBtn'); if (b) b.disabled = true; }

                const hiddenReplyTo = hasJQ ? ($('#chatReplyTo').val() || '') : (document.getElementById('chatReplyTo') ? document.getElementById('chatReplyTo').value : '');
                const previewReplyTo = hasJQ ? ($('#chatReplyPreview').attr('data-reply-id') || '') : '';
                const parsedHiddenReplyTo = parseInt(hiddenReplyTo, 10);
                const parsedPreviewReplyTo = parseInt(previewReplyTo, 10);
                const replyTo = (Number.isFinite(activeReplyToId) && activeReplyToId > 0)
                    ? activeReplyToId
                    : (Number.isFinite(parsedHiddenReplyTo) && parsedHiddenReplyTo > 0 ? parsedHiddenReplyTo : (Number.isFinite(parsedPreviewReplyTo) && parsedPreviewReplyTo > 0 ? parsedPreviewReplyTo : null));
                const replyPreviewUser = hasJQ ? ($('#chatReplyPreview .reply-user').text() || '') : '';
                const replyPreviewMessage = hasJQ ? ($('#chatReplyPreview .reply-preview').html() || '') : '';
                const payload = new FormData();
                payload.append('project_id', String(projectId || ''));
                payload.append('page_id', String(pageId || ''));
                payload.append('message', msg);
                payload.append('reply_to', replyTo ? String(replyTo) : '');
                payload.append('reply_token', replyTo ? ('r:' + String(replyTo)) : '');
                const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : (window._csrfToken || '');
                if (csrfToken) payload.append('csrf_token', csrfToken);

                function fallbackPostMessage() {
                    const params = new URLSearchParams();
                    params.append('send_message', '1');
                    params.append('project_id', String(projectId || ''));
                    params.append('page_id', String(pageId || ''));
                    params.append('message', msg);
                    if (replyTo) params.append('reply_to', String(replyTo));
                    if (replyTo) params.append('reply_token', 'r:' + String(replyTo));
                    if (csrfToken) params.append('csrf_token', csrfToken);
                    return fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: params.toString()
                    }).then(function(res) {
                        return res.text().then(function(text) { try { return JSON.parse(text); } catch (e) { return { error: 'Fallback invalid response' }; } });
                    }).then(function(res) {
                        if (res && res.success) {
                            if (replyTo && res.message && !res.message.reply_preview && replyPreviewMessage) {
                                res.message.reply_preview = { id: replyTo, user_id: null, username: '', full_name: replyPreviewUser || 'User', message: replyPreviewMessage, created_at: null };
                            }
                            setMessageHtml(''); clearReplyState(); updateCharCount();
                            if (res.message) appendMessages([res.message]); else fetchMessages();
                            if (isEmbed && composeCollapsed) updateComposeCollapse();
                        } else { hardFallbackSubmit(msg, replyTo); }
                    }).catch(function() { hardFallbackSubmit(msg, replyTo); });
                }

                fetch(baseDir + '/api/chat_actions.php?action=send_message', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken || '' },
                    body: payload
                }).then(function(res) {
                    return res.text().then(function(text) { try { return JSON.parse(text); } catch (e) { return { error: 'Invalid response', _raw: text }; } });
                }).then(function(res) {
                    if (res && res.success) {
                        if (replyTo && res.message && !res.message.reply_preview && replyPreviewMessage) {
                            res.message.reply_preview = { id: replyTo, user_id: null, username: '', full_name: replyPreviewUser || 'User', message: replyPreviewMessage, created_at: null };
                        }
                        setMessageHtml(''); clearReplyState(); updateCharCount();
                        if (res.message) appendMessages([res.message]); else fetchMessages();
                        if (isEmbed && composeCollapsed) updateComposeCollapse();
                    } else { return fallbackPostMessage(); }
                }).catch(function(err) { console.error('Chat send failed', err); return fallbackPostMessage(); })
                .finally(function() { if (hasJQ) $('#sendBtn').prop('disabled', false); else { const b = document.getElementById('sendBtn'); if (b) b.disabled = false; } });
            } catch (err) {
                console.error('sendChatMessage fatal error', err);
                const safeMsg = (typeof getMessageHtml === 'function') ? getMessageHtml() : '';
                const safeHiddenReply = (document.getElementById('chatReplyTo') ? document.getElementById('chatReplyTo').value : '');
                const safeReply = (Number.isFinite(activeReplyToId) && activeReplyToId > 0) ? String(activeReplyToId) : String(safeHiddenReply || '');
                hardFallbackSubmit(safeMsg, safeReply);
            }
        }

        function enableToolbarKeyboardA11y($editor) {
            if (!hasJQ || !$editor || !$editor.length) return;
            const $toolbar = $editor.next('.note-editor').find('.note-toolbar').first();
            if (!$toolbar.length || $toolbar.data('kbdA11yBound')) return;
            let syncingTabStops = false;

            function getItems() {
                return $toolbar.find('.note-btn-group button').filter(function() {
                    const $b = $(this);
                    return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
                });
            }

            function setActiveIndex(idx) {
                if (syncingTabStops) return;
                const $items = getItems();
                if (!$items.length) return;
                const next = Math.max(0, Math.min(idx, $items.length - 1));
                syncingTabStops = true;
                try {
                    $items.each(function(i) {
                        const val = i === next ? '0' : '-1';
                        if (this.getAttribute('tabindex') !== val) this.setAttribute('tabindex', val);
                    });
                    $toolbar.data('kbdIndex', next);
                } finally { syncingTabStops = false; }
            }

            function ensureToolbarTabStops() {
                const $items = getItems();
                if (!$items.length) return;
                let idx = parseInt($toolbar.data('kbdIndex'), 10);
                if (isNaN(idx) || idx < 0 || idx >= $items.length) idx = $items.index(document.activeElement);
                if (isNaN(idx) || idx < 0 || idx >= $items.length) idx = 0;
                setActiveIndex(idx);
            }

            function handleNav(e) {
                const key = e.key || (e.originalEvent && e.originalEvent.key);
                const code = e.keyCode || (e.originalEvent && e.originalEvent.keyCode);
                const isTab = key === 'Tab' || code === 9;
                if (isTab) {
                    const isComposeEditor = isEmbed && ($editor.attr('id') === 'message');
                    if (isComposeEditor && !e.shiftKey) { e.preventDefault(); focusComposeEditable(); return; }
                    if (isComposeEditor && e.shiftKey && composeToggle) { e.preventDefault(); try { composeToggle.focus(); } catch (err) {} return; }
                }
                const isRight = key === 'ArrowRight' || code === 39;
                const isLeft = key === 'ArrowLeft' || code === 37;
                const isHome = key === 'Home' || code === 36;
                const isEnd = key === 'End' || code === 35;
                if (!isRight && !isLeft && !isHome && !isEnd) return;
                const $items = getItems();
                if (!$items.length) return;
                const activeEl = document.activeElement;
                let idx = $items.index(activeEl);
                if (idx < 0 && activeEl && activeEl.closest) { const parentBtn = activeEl.closest('button'); if (parentBtn) idx = $items.index(parentBtn); }
                if (idx < 0) { const saved = parseInt($toolbar.data('kbdIndex'), 10); if (!isNaN(saved) && saved >= 0 && saved < $items.length) idx = saved; }
                if (isNaN(idx) || idx < 0) idx = 0;
                e.preventDefault();
                if (e.stopPropagation) e.stopPropagation();
                if (isHome) idx = 0;
                else if (isEnd) idx = $items.length - 1;
                else if (isRight) idx = (idx + 1) % $items.length;
                else if (isLeft) idx = (idx - 1 + $items.length) % $items.length;
                setActiveIndex(idx);
                $items.eq(idx).focus();
                if (document.activeElement !== $items.eq(idx).get(0)) setTimeout(function() { $items.eq(idx).focus(); }, 0);
            }

            $toolbar.attr('role', 'toolbar');
            if (!$toolbar.attr('aria-label')) $toolbar.attr('aria-label', 'Editor toolbar');
            ensureToolbarTabStops();
            $toolbar.on('focusin', 'button, [role="button"], a.note-btn', function() { const $items = getItems(); const idx = $items.index(this); if (idx >= 0) setActiveIndex(idx); });
            $toolbar.on('click', 'button, [role="button"], a.note-btn', function() { const $items = getItems(); const idx = $items.index(this); if (idx >= 0) setActiveIndex(idx); });
            $toolbar.on('keydown', handleNav);
            if (!$toolbar.data('kbdA11yNativeKeyBound')) {
                $toolbar.get(0).addEventListener('keydown', handleNav, true);
                $toolbar.data('kbdA11yNativeKeyBound', true);
            }
            const observer = new MutationObserver(function() { ensureToolbarTabStops(); });
            observer.observe($toolbar[0], { subtree: true, attributes: true, attributeFilter: ['tabindex', 'class', 'disabled'] });
            $toolbar.data('kbdA11yObserver', observer);
            ensureToolbarTabStops();
            $toolbar.data('kbdA11yBound', true);
            const $editable = $editor.next('.note-editor').find('.note-editable');
            $editable.on('keydown', function(e) {
                if (e && e.altKey && (e.key === 'F10' || e.keyCode === 121)) { e.preventDefault(); focusEditorToolbar($editor); }
            });
        }

        function focusEditorToolbar($editor) {
            if (!hasJQ || !$editor || !$editor.length) return;
            const $toolbar = $editor.next('.note-editor').find('.note-toolbar').first();
            if (!$toolbar.length) return;
            const $items = $toolbar.find('.note-btn-group button').filter(function() {
                const $b = $(this);
                return !$b.is(':hidden') && !$b.prop('disabled') && !$b.closest('.dropdown-menu').length;
            });
            if (!$items.length) return;
            $items.attr('tabindex', '-1');
            $items.eq(0).attr('tabindex', '0').focus();
            $toolbar.data('kbdIndex', 0);
        }

        if (hasJQ) {
            $('#chatForm').on('submit', function(e) { e.preventDefault(); sendChatMessage(); });
            $('#message').on('keydown', function(e) {
                mentionSearchDisabled = (e.key === 'Escape') ? mentionSearchDisabled : false;
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); sendChatMessage(); }
                if (isMentionVisible()) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionHighlight(1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionHighlight(-1); }
                    else if (e.key === 'Enter') { const username = getActiveMentionUsername(); if (username) { e.preventDefault(); e.stopImmediatePropagation(); insertMention(username); } }
                    else if (e.key === 'Escape') { e.preventDefault(); hideMentionDropdown(); }
                }
            });
            $(chatMessages).on('click', '.chat-image-full-btn', function(){ showImageModal($(this).data('src')); });
            $(chatMessages).on('click', '.message-content img', function(){ showImageModal($(this).attr('src')); });
            if (summernoteReady) {
                $msg.on('summernote.keyup', function() {
                    const plain = $('<div>').html($msg.summernote('code')).text();
                    const editable = $msg.next('.note-editor').find('.note-editable')[0];
                    handleMentionSearch(plain, editable || $msg.next('.note-editor')[0]);
                });
                $msg.on('summernote.keydown', function(e) {
                    mentionSearchDisabled = (e.key === 'Escape') ? mentionSearchDisabled : false;
                    if (isMentionVisible()) {
                        if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionHighlight(1); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionHighlight(-1); }
                        else if (e.key === 'Enter') { const username = getActiveMentionUsername(); if (username) { e.preventDefault(); e.stopImmediatePropagation(); insertMention(username); } }
                        else if (e.key === 'Escape') { e.preventDefault(); hideMentionDropdown(); }
                    }
                });
            } else {
                $('#message').on('keyup', function(){
                    if (mentionSearchDisabled && !/@[A-Za-z0-9._-]*$/.test($(this).val())) mentionSearchDisabled = false;
                    handleMentionSearch($(this).val(), $(this));
                });
            }
        } else {
            const form = document.getElementById('chatForm');
            const msgEl = document.getElementById('message');
            if (form) form.addEventListener('submit', function(e){ e.preventDefault(); sendChatMessage(); });
            if (msgEl) msgEl.addEventListener('keydown', function(e){
                mentionSearchDisabled = (e.key === 'Escape') ? mentionSearchDisabled : false;
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); sendChatMessage(); }
                if (isMentionVisible()) {
                    if (e.key === 'Escape') { e.preventDefault(); hideMentionDropdown(); }
                    else if (e.key === 'Enter') { const username = getActiveMentionUsername(); if (username) { e.preventDefault(); e.stopPropagation(); insertMention(username); } }
                }
            });
            if (chatMessages) {
                chatMessages.addEventListener('click', function(e){
                    const btn = e.target.closest('.chat-image-full-btn');
                    if (btn) { showImageModal(btn.getAttribute('data-src')); return; }
                    const img = e.target.closest('.message-content img');
                    if (img) showImageModal(img.getAttribute('src'));
                });
            }
            if (msgEl) msgEl.addEventListener('keyup', function(){
                if (mentionSearchDisabled && !/@[A-Za-z0-9._-]*$/.test(msgEl.value)) mentionSearchDisabled = false;
                handleMentionSearch(msgEl.value, msgEl);
            });
        }

        function getCaretRectWithin(el) {
            if (!el) return null;
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            const range = sel.getRangeAt(0).cloneRange();
            if (!el.contains(range.commonAncestorContainer)) return null;
            range.collapse(true);
            let rect = range.getBoundingClientRect();
            if (!rect || (rect.width === 0 && rect.height === 0)) {
                const span = document.createElement('span');
                span.textContent = '\u200b';
                range.insertNode(span);
                rect = span.getBoundingClientRect();
                span.parentNode && span.parentNode.removeChild(span);
                range.collapse(true);
                sel.removeAllRanges();
                sel.addRange(range);
            }
            return rect;
        }

        function handleMentionSearch(text, anchorEl) {
            if (mentionSearchDisabled) return;
            const match = /@([A-Za-z0-9._-]*)$/.exec((text || ''));
            if (!match) { hideMentionDropdown(); return; }
            const query = match[1] || '';
            const list = mentionUsers.filter(u =>
                u.username.toLowerCase().startsWith(query.toLowerCase()) ||
                u.full_name.toLowerCase().includes(query.toLowerCase())
            ).slice(0, 8);
            if (!list.length) { hideMentionDropdown(); return; }
            const html = list.map(u => `<button type="button" class="dropdown-item mention-pick" data-username="${u.username}">@${u.username} — ${escapeHtml(u.full_name)}</button>`).join('');
            lastMentionAnchor = anchorEl;
            mentionIndex = 0;
            const formEl = document.getElementById('chatForm');
            if (hasJQ && mentionDropdown) {
                mentionDropdown.html(html).css({ display: 'block', position: 'absolute' });
                const sel = window.getSelection();
                if (sel && sel.rangeCount) lastMentionRange = sel.getRangeAt(0).cloneRange();
                const caretRect = getCaretRectWithin(anchorEl);
                const rect = caretRect || anchorEl.getBoundingClientRect();
                const contRect = formEl ? formEl.getBoundingClientRect() : { top: 0, left: 0 };
                mentionDropdown.css({ top: rect.bottom - contRect.top + 6, left: rect.left - contRect.left, minWidth: rect.width });
                const items = mentionDropdown.find('.mention-pick');
                if (items.length) { items.removeClass('active'); items.eq(mentionIndex).addClass('active'); }
            } else if (mentionDropdown) {
                mentionDropdown.innerHTML = html;
                mentionDropdown.style.display = 'block';
                mentionDropdown.style.position = 'absolute';
                const sel = window.getSelection();
                if (sel && sel.rangeCount) lastMentionRange = sel.getRangeAt(0).cloneRange();
                const caretRect = getCaretRectWithin(anchorEl);
                const rect = caretRect || anchorEl.getBoundingClientRect();
                const contRect = formEl ? formEl.getBoundingClientRect() : { top: 0, left: 0 };
                mentionDropdown.style.top = (rect.bottom - contRect.top + 6) + 'px';
                mentionDropdown.style.left = (rect.left - contRect.left) + 'px';
                mentionDropdown.style.minWidth = rect.width + 'px';
                const items = mentionDropdown.querySelectorAll('.mention-pick');
                if (items.length) { items.forEach(i => i.classList.remove('active')); items[mentionIndex].classList.add('active'); }
            }
        }

        function hideMentionDropdown() {
            mentionIndex = -1;
            lastMentionRange = null;
            if (hasJQ) mentionDropdown.hide();
            else if (mentionDropdown) mentionDropdown.style.display = 'none';
        }

        if (hasJQ) {
            $(document).on('click', '.mention-pick', function(){ insertMention($(this).data('username')); hideMentionDropdown(); });
        } else if (mentionDropdown) {
            mentionDropdown.addEventListener('click', function(e){
                const btn = e.target.closest('.mention-pick');
                if (btn) { insertMention(btn.getAttribute('data-username')); hideMentionDropdown(); }
            });
        }

        document.addEventListener('keydown', function(e){
            if (!isMentionVisible()) return;
            const isEsc = (e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27);
            if (isEsc) { e.preventDefault(); e.stopImmediatePropagation(); mentionSearchDisabled = true; hideMentionDropdown(); return; }
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Tab' || e.keyCode === 9) {
                const username = getActiveMentionUsername();
                if (username) { e.preventDefault(); e.stopImmediatePropagation(); insertMention(username); hideMentionDropdown(); }
            }
        }, true);

        function highlightMention(index) {
            if (hasJQ) {
                const items = mentionDropdown.find('.mention-pick');
                items.removeClass('active');
                if (items.length && index >= 0 && index < items.length) $(items[index]).addClass('active')[0].scrollIntoView({ block: 'nearest' });
            } else if (mentionDropdown) {
                const items = mentionDropdown.querySelectorAll('.mention-pick');
                items.forEach(i => i.classList.remove('active'));
                if (items.length && index >= 0 && index < items.length) { items[index].classList.add('active'); items[index].scrollIntoView({ block: 'nearest' }); }
            }
        }

        function getActiveMentionUsername() {
            if (hasJQ) {
                const active = mentionDropdown.find('.mention-pick.active');
                if (active.length) return active.data('username');
                const first = mentionDropdown.find('.mention-pick').first();
                return first.length ? first.data('username') : null;
            } else if (mentionDropdown) {
                const active = mentionDropdown.querySelector('.mention-pick.active');
                if (active) return active.getAttribute('data-username');
                const first = mentionDropdown.querySelector('.mention-pick');
                return first ? first.getAttribute('data-username') : null;
            }
            return null;
        }

        function moveMentionHighlight(delta) {
            if (mentionIndex < 0) return;
            const items = hasJQ ? mentionDropdown.find('.mention-pick') : (mentionDropdown ? mentionDropdown.querySelectorAll('.mention-pick') : []);
            const len = items.length || 0;
            if (!len) return;
            mentionIndex = (mentionIndex + delta + len) % len;
            highlightMention(mentionIndex);
        }

        function insertMention(username) {
            function stripCurrentMentionToken(editable) {
                if (!editable || !window.getSelection) return;
                const sel = window.getSelection();
                if (!sel.rangeCount) return;
                if (lastMentionRange) { sel.removeAllRanges(); sel.addRange(lastMentionRange); }
                if (!sel.rangeCount) return;
                const range = sel.getRangeAt(0);
                if (!editable.contains(range.commonAncestorContainer)) return;
                let cursorNode = range.startContainer;
                let cursorOffset = range.startOffset;
                let startNode = null, startOffset = 0;
                function prevPosition(node, offset) {
                    if (!node) return null;
                    if (node.nodeType === 3 && offset > 0) return { node, offset: offset - 1, char: node.textContent[offset - 1] };
                    let cur = node;
                    while (cur) {
                        if (cur.previousSibling) {
                            cur = cur.previousSibling;
                            while (cur.lastChild) cur = cur.lastChild;
                            if (cur.nodeType === 3) { const len = cur.textContent.length; return { node: cur, offset: len, char: len ? cur.textContent[len - 1] : '' }; }
                        } else { cur = cur.parentNode; if (!cur || cur === editable) break; }
                    }
                    return null;
                }
                let pos = { node: cursorNode, offset: cursorOffset };
                while (true) {
                    const prev = prevPosition(pos.node, pos.offset);
                    if (!prev) break;
                    if (prev.char === '@') { startNode = prev.node; startOffset = prev.offset; break; }
                    if (/\s/.test(prev.char || '')) break;
                    pos = prev;
                }
                if (startNode) {
                    const del = document.createRange();
                    del.setStart(startNode, startOffset);
                    del.setEnd(cursorNode, cursorOffset);
                    del.deleteContents();
                }
            }

            function shouldPrefixSpaceInEditable(editable) {
                if (!editable || !window.getSelection) return false;
                const sel = window.getSelection();
                if (!sel || !sel.rangeCount) return false;
                const range = sel.getRangeAt(0).cloneRange();
                if (!editable.contains(range.commonAncestorContainer)) return false;
                const probe = range.cloneRange();
                probe.collapse(true);
                try { probe.setStart(editable, 0); } catch (e) { return false; }
                const textBefore = probe.toString();
                if (!textBefore) return false;
                return !/\s$/.test(textBefore);
            }

            function buildPlainMentionInsert(text, start, end, uname) {
                const safeText = String(text || '');
                const caretStart = Math.max(0, Number(start || 0));
                const caretEnd = Math.max(caretStart, Number(end || caretStart));
                const beforeCaret = safeText.substring(0, caretStart);
                const tokenMatch = /@[A-Za-z0-9._-]*$/.exec(beforeCaret);
                const atPos = tokenMatch ? (beforeCaret.length - tokenMatch[0].length) : -1;
                const before = atPos >= 0 ? safeText.substring(0, atPos) : beforeCaret;
                const after = atPos >= 0 ? safeText.substring(caretEnd) : safeText.substring(caretStart);
                const needsLeadingSpace = before.length > 0 && !/\s$/.test(before);
                const insert = (needsLeadingSpace ? ' ' : '') + '@' + uname + ' ';
                return { value: before + insert + after, caret: before.length + insert.length };
            }

            if (summernoteReady) {
                const editable = $msg.next('.note-editor').find('.note-editable')[0];
                const sel = window.getSelection();
                if (lastMentionRange) { sel.removeAllRanges(); sel.addRange(lastMentionRange); }
                stripCurrentMentionToken(editable);
                const selAfter = window.getSelection();
                if (selAfter && selAfter.rangeCount) { const r = selAfter.getRangeAt(0); r.collapse(false); selAfter.removeAllRanges(); selAfter.addRange(r); }
                const needsLeadingSpace = shouldPrefixSpaceInEditable(editable);
                const mentionText = (needsLeadingSpace ? ' ' : '') + '@' + username + ' ';
                $msg.summernote('editor.focus');
                try { document.execCommand('insertText', false, mentionText); } catch (e) { $msg.summernote('editor.insertText', mentionText); }
                lastMentionRange = null;
            } else if (hasJQ) {
                const ta = $msg.get(0);
                const start = ta.selectionStart, end = ta.selectionEnd;
                const next = buildPlainMentionInsert($msg.val() || '', start, end, username);
                $msg.val(next.value).focus();
                if (ta && typeof ta.setSelectionRange === 'function') ta.setSelectionRange(next.caret, next.caret);
                lastMentionRange = null;
            } else {
                const ta = document.getElementById('message');
                if (!ta) return;
                const next = buildPlainMentionInsert(ta.value || '', ta.selectionStart, ta.selectionEnd, username);
                ta.value = next.value;
                ta.focus();
                if (typeof ta.setSelectionRange === 'function') ta.setSelectionRange(next.caret, next.caret);
                lastMentionRange = null;
            }
            updateCharCount();
            hideMentionDropdown();
        }

        function appendMessages(messages) {
            if (!messages || !messages.length) return;
            if (hasJQ) $('.no-messages').remove();
            else { const nm = document.querySelector('.no-messages'); if (nm && nm.parentNode) nm.parentNode.removeChild(nm); }

            messages.forEach(msg => {
                if (!msg || !msg.id) return;
                const msgId = Number(msg.id);
                if (msgId <= Number(lastMessageId)) return;
                if (document.querySelector('.message[data-id="' + msgId + '"]')) return;
                lastMessageId = msgId;

                let isMentioned = false;
                if (msg.mentions) {
                    try {
                        const mentions = typeof msg.mentions === 'string' ? JSON.parse(msg.mentions) : msg.mentions;
                        if (Array.isArray(mentions) && mentions.includes(currentUserId)) isMentioned = true;
                    } catch (e) {}
                }

                const roleColor = msg.role === 'admin' ? 'danger' : (msg.role === 'project_lead' ? 'warning' : 'info');
                const ownClass = (Number(msg.user_id) === currentUserId) ? 'own-message' : 'other-message';
                const canEdit = !!msg.can_edit;
                const canDelete = !!msg.can_delete;
                const deletedByContent = (hasJQ ? $('<div>').html(msg.message || '').text() : (new DOMParser().parseFromString(msg.message || '', 'text/html').documentElement.textContent || ''))
                    .replace(/\s+/g, ' ').trim().toLowerCase() === 'message deleted';
                let replyBlock = '';
                if (msg.reply_preview) {
                    const rp = msg.reply_preview;
                    const rpTime = rp.created_at ? ' <small class="text-muted ms-2">' + escapeHtml(rp.created_at) + '</small>' : '';
                    replyBlock = `<div class="reply-preview small"><strong>${escapeHtml(rp.full_name)}</strong>${rpTime}: ${rp.message}</div>`;
                }
                const msgHtml = `
                    <div class="message ${ownClass} ${isMentioned ? 'border-start border-warning border-4 bg-light' : ''}" data-id="${msgId}">
                        <div class="message-header">
                            <div>
                                <span class="message-sender">
                                    <a href="../../modules/profile.php?id=${msg.user_id}" class="text-decoration-none">${escapeHtml(msg.full_name || '')}</a>
                                </span>
                                <span class="badge user-badge bg-${roleColor}">${capitalize(String(msg.role || '').replace('_', ' '))}</span>
                                <small class="text-muted">@${escapeHtml(msg.username || '')}</small>
                            </div>
                            <div class="message-header-right">
                                <div class="message-time">${msg.created_at || ''}</div>
                                <div class="message-actions">
                                    <button type="button" class="chat-action-btn chat-reply" title="Reply" aria-label="Reply to message" data-mid="${msgId}" data-username="${escapeHtml(msg.username || '')}" data-message="${escapeHtml(msg.message || '')}"><i class="fas fa-reply"></i></button>
                                    ${(canEdit && !deletedByContent) ? `<button type="button" class="chat-action-btn chat-edit" title="Edit" aria-label="Edit message" data-mid="${msgId}" data-message="${escapeHtml(msg.message || '')}"><i class="fas fa-pen"></i></button>` : ``}
                                    ${(canDelete && !deletedByContent) ? `<button type="button" class="chat-action-btn chat-delete" title="Delete" aria-label="Delete message" data-mid="${msgId}"><i class="fas fa-trash"></i></button>` : ``}
                                    ${canViewHistoryAdmin ? `<button type="button" class="chat-action-btn chat-history" title="History" aria-label="View message history" data-mid="${msgId}"><i class="fas fa-history"></i></button>` : ``}
                                </div>
                            </div>
                        </div>
                        <div class="message-content">
                            ${replyBlock}
                            ${msg.message || ''}
                            <div class="message-meta">${msg.created_at || ''}</div>
                        </div>
                    </div>`;

                if (hasJQ) chatMessages.append(msgHtml);
                else if (chatMessages) { const wrapper = document.createElement('div'); wrapper.innerHTML = msgHtml; if (wrapper.firstElementChild) chatMessages.appendChild(wrapper.firstElementChild); }
            });

            enhanceImages(hasJQ ? chatMessages[0] : chatMessages);
            applyEmbedTabOrder();
            ensureRecentMessageAnchor(false);
            scrollToBottom();
        }

        let chatPollTimer = null;
        let chatPollIntervalMs = 2000;
        const chatPollMinMs = 1500;
        const chatPollMaxMs = 10000;

        function scheduleNextFetch(ms) {
            if (chatPollTimer) clearTimeout(chatPollTimer);
            chatPollTimer = setTimeout(fetchMessages, Math.max(chatPollMinMs, Math.min(chatPollMaxMs, ms || chatPollIntervalMs)));
        }

        function fetchMessages() {
            const params = new URLSearchParams({
                action: 'fetch_messages',
                project_id: projectId,
                page_id: pageId,
                last_id: lastMessageId
            });
            fetch(baseDir + '/api/chat_actions.php?' + params.toString(), {
                headers: { 'Accept': 'application/json' }
            }).then(res => res.text())
            .then(text => { try { return JSON.parse(text); } catch (e) { return []; } })
            .then(messages => {
                const hasNew = !!(messages && Array.isArray(messages) && messages.length > 0);
                if (hasNew) {
                    appendMessages(messages);
                    chatPollIntervalMs = chatPollMinMs;
                } else {
                    chatPollIntervalMs = Math.min(chatPollMaxMs, Math.round(chatPollIntervalMs * 1.35));
                }
                scheduleNextFetch(chatPollIntervalMs);
            })
            .catch(() => {
                chatPollIntervalMs = Math.min(chatPollMaxMs, Math.round(chatPollIntervalMs * 1.5));
                scheduleNextFetch(chatPollIntervalMs);
            });
        }

        function escapeHtml(text) {
            return (text || '').replace(/[&<>"']/g, function(m) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m] || m;
            });
        }

        function capitalize(s) { return (s && s.length) ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

        fetchMessages();
        applyEmbedTabOrder();
        bindMessageKeyboardNavigation();
        if (!initialRecentMessageFocused) {
            ensureRecentMessageAnchor(true);
            initialRecentMessageFocused = true;
        }

        if (hasJQ) {
            $('#refreshChat').click(() => location.reload());
            $('#clearMessage').click(() => { setMessageHtml(''); updateCharCount(); });
        } else {
            const r = document.getElementById('refreshChat');
            if (r) r.addEventListener('click', () => location.reload());
            const c = document.getElementById('clearMessage');
            if (c) c.addEventListener('click', () => { setMessageHtml(''); updateCharCount(); });
        }

        if (isEmbed) {
            document.addEventListener('keydown', function(e) {
                if (!e || e.key !== 'Escape') return;
                try {
                    if (window.parent && window.parent !== window) window.parent.postMessage({ type: 'pms-chat-close' }, '*');
                } catch (err) {}
            });
        }
    });
