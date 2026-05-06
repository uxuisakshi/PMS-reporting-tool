(function (window) {
    'use strict';

    var dedupeMap = {};
    var DEDUPE_WINDOW_MS = 800;

    function nowTs() {
        return Date.now ? Date.now() : new Date().getTime();
    }

    function cleanupDedupe() {
        var now = nowTs();
        Object.keys(dedupeMap).forEach(function (k) {
            if ((now - dedupeMap[k]) > DEDUPE_WINDOW_MS) {
                delete dedupeMap[k];
            }
        });
    }

    function fileSig(file) {
        if (!file) return '';
        return [
            String(file.name || ''),
            String(file.size || ''),
            String(file.type || ''),
            String(file.lastModified || '')
        ].join('|');
    }

    function shouldSkipDuplicate(file) {
        cleanupDedupe();
        var sig = fileSig(file);
        if (!sig) return false;
        var now = nowTs();
        if (dedupeMap[sig] && (now - dedupeMap[sig]) <= DEDUPE_WINDOW_MS) {
            return true;
        }
        dedupeMap[sig] = now;
        return false;
    }

    function isImageFile(file) {
        if (!file) return false;
        var type = String(file.type || '').toLowerCase();
        if (type.indexOf('image/') === 0) return true;
        var name = String(file.name || '').toLowerCase();
        return /\.(jpg|jpeg|png|gif|webp|bmp|svg|avif)$/.test(name);
    }

    function escapeAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function parseJsonFromResponse(res) {
        return res.text().then(function (txt) {
            try {
                return JSON.parse(txt);
            } catch (e) {
                return { success: false, error: 'Upload failed (invalid server response)' };
            }
        });
    }

    function uploadImage(file, opts) {
        opts = opts || {};
        var uploadUrl = String(opts.uploadUrl || '');
        if (!uploadUrl) {
            return Promise.resolve({ success: false, error: 'Upload URL missing' });
        }
        if (!isImageFile(file)) {
            return Promise.resolve({ success: false, error: 'Only image files are allowed', invalidType: true });
        }
        if (opts.dedupe !== false && shouldSkipDuplicate(file)) {
            return Promise.resolve({ success: false, skipped: true });
        }

        var fd = new FormData();
        fd.append(opts.fieldName || 'image', file);
        // Attach CSRF token if available
        if (window._csrfToken) {
            fd.append('csrf_token', window._csrfToken);
        }

        return fetch(uploadUrl, {
            method: 'POST',
            body: fd,
            headers: {
                'X-CSRF-Token': window._csrfToken || ''
            },
            credentials: opts.credentials || 'same-origin'
        }).then(parseJsonFromResponse).catch(function () {
            return { success: false, error: 'Image upload failed' };
        });
    }

    function insertImageIntoSummernote($editor, url, altText) {
        if (!window.jQuery || !$editor || !$editor.length || !$editor.data('summernote')) return false;
        var safeUrl = escapeAttr(url || '');
        var safeAlt = escapeAttr(altText || 'image');
        $editor.summernote('pasteHTML', '<p><img src="' + safeUrl + '" alt="' + safeAlt + '" /></p>');
        return true;
    }

    function normalizeFileList(files) {
        if (!files) return [];
        if (Array.isArray(files)) return files;
        try { return Array.from(files); } catch (e) { return []; }
    }

    function extractClipboardImageFiles(e) {
        var ev = e && (e.originalEvent || e);
        var clipboard = ev && ev.clipboardData;
        if (!clipboard || !clipboard.items) return [];
        var out = [];
        for (var i = 0; i < clipboard.items.length; i++) {
            var item = clipboard.items[i];
            if (item && item.type && item.type.indexOf('image') === 0 && item.getAsFile) {
                var f = item.getAsFile();
                if (f) out.push(f);
            }
        }
        return out;
    }

    function handleImageUpload(files, $editor, opts) {
        opts = opts || {};
        var list = normalizeFileList(files);
        if (!list.length) return;

        list.forEach(function (file) {
            if (!isImageFile(file)) {
                if (typeof opts.onInvalidType === 'function') opts.onInvalidType(file);
                return;
            }

            uploadImage(file, opts).then(function (res) {
                if (!res || res.skipped) return;
                if (res.success && res.url) {
                    if (typeof opts.onSuccess === 'function') {
                        opts.onSuccess(res, file, $editor);
                    } else {
                        insertImageIntoSummernote($editor, res.url, opts.defaultAlt || 'image');
                    }
                } else {
                    if (typeof opts.onError === 'function') {
                        opts.onError((res && res.error) ? res.error : 'Image upload failed', res, file);
                    }
                }
            }).catch(function () {
                if (typeof opts.onError === 'function') opts.onError('Image upload failed', null, file);
            });
        });
    }

    function handlePasteEvent(e, $editor, opts) {
        var files = extractClipboardImageFiles(e);
        if (!files.length) return false;
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        handleImageUpload(files, $editor, opts);
        return true;
    }

    function uploadAndInsert(file, $editor, opts) {
        opts = opts || {};
        handleImageUpload([file], $editor, opts);
    }

    window.PMSSummernoteImage = {
        isImageFile: isImageFile,
        uploadImage: uploadImage,
        insertImageIntoSummernote: insertImageIntoSummernote,
        extractClipboardImageFiles: extractClipboardImageFiles,
        handleImageUpload: handleImageUpload,
        handlePasteEvent: handlePasteEvent,
        uploadAndInsert: uploadAndInsert
    };
})(window);

