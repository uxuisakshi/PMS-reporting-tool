/**
 * view_core.js
 * Core functionality for Project View: State, Tabs, Modals, Chat, Utilities.
 */

var ProjectConfig = window.ProjectConfig || {};

(function () {
    var projectId = ProjectConfig.projectId;
    var baseDir = ProjectConfig.baseDir;
    var userId = ProjectConfig.userId;

    // --- Global Fetch Interceptor for Auth/Errors ---
    (function () {
        const originalFetch = window.fetch;
        window.fetch = function () {
            return originalFetch.apply(this, arguments).then(async res => {
                if (res.status === 401) {
                    if (typeof showToast === 'function') showToast('Session expired. Please reload.', 'danger');
                }
                try {
                    const clone = res.clone();
                    const type = clone.headers.get('content-type');
                    if (type && type.includes('application/json')) {
                    const data = await clone.json();
                    if (data && data.error === 'auth_required') {
                            if (typeof showToast === 'function') {
                                showToast('Authentication required. Please sign in again.', 'warning');
                            }
                        }
                    }
                } catch (e) { }
                return res;
            });
        };
    })();

    // --- Tab State Management ---
    var isRestoring = false;

    function restoreTabState() {
        var pId = window.ProjectConfig.projectId || projectId;
        if (!pId) return;

        if (isRestoring) return;
        isRestoring = true;

        // Restore Main Tab
        var activeTab = localStorage.getItem('pms_project_tab_' + pId);
        if (activeTab) {
            var tabBtn = document.querySelector('#projectTabs button[data-bs-target="' + activeTab + '"]');
            if (tabBtn) {
                try {
                    var tabTrigger = new bootstrap.Tab(tabBtn);
                    tabTrigger.show();
                } catch (e) {
                    tabBtn.click();
                }
                isRestoring = false;
            } else {
                isRestoring = false;
            }
        } else {
            isRestoring = false;
        }

        // Restore Sub-tabs
        var subTabs = ['#issuesSubTabs', '#pagesSubTabs', '#assetsSubTabs', '#teamSubTabs', '#regressionSubTabs'];
        subTabs.forEach(function (sel) {
            var el = document.querySelector(sel);
            if (el) {
                var storeKey = 'pms_project_subtab_' + pId + '_' + sel;
                var saved = localStorage.getItem(storeKey);
                if (saved) {
                    var b = el.querySelector('button[data-bs-target="' + saved + '"]');
                    if (b) {
                        setTimeout(function () {
                            try { new bootstrap.Tab(b).show(); } catch (e) { b.click(); }
                        }, 250);
                    }
                }
                el.addEventListener('shown.bs.tab', function (e) {
                    if (isRestoring) return;
                    var t = e.target.getAttribute('data-bs-target');
                    if (t) localStorage.setItem(storeKey, t);
                });
            }
        });
    }

    // Main tab listener - Using delegation
    function initTabPersistence() {
        var mainTabContainer = document.getElementById('projectTabs');
        if (mainTabContainer) {
            mainTabContainer.addEventListener('shown.bs.tab', function (e) {
                if (isRestoring) return;
                var target = e.target.getAttribute('data-bs-target');
                if (target) {
                    var pId = window.ProjectConfig.projectId || projectId;
                    localStorage.setItem('pms_project_tab_' + pId, target);
                }
            });
        }
    }

    // --- Floating Chat Widget ---
    function initChatWidget() {
        var chatWidget = document.getElementById('projectChatWidget');
        var chatMessages = document.getElementById('projectChatMessages');
        var chatToggle = document.getElementById('projectChatToggle');
        var chatMinimize = document.getElementById('projectChatMinimize');
        var chatInput = document.getElementById('projectChatInput');
        var chatSend = document.getElementById('projectChatSend');

        if (!chatWidget || !chatToggle) return;

        // Restore state
        if (localStorage.getItem('pms_chat_open_' + projectId) === 'true') {
            chatWidget.classList.remove('d-none');
            loadProjectChat();
        }

        chatToggle.addEventListener('click', function () {
            chatWidget.classList.remove('d-none');
            localStorage.setItem('pms_chat_open_' + projectId, 'true');
            loadProjectChat();
        });

        chatMinimize.addEventListener('click', function () {
            chatWidget.classList.add('d-none');
            localStorage.setItem('pms_chat_open_' + projectId, 'false');
        });

        if (chatSend) chatSend.addEventListener('click', sendMsg);
        if (chatInput) chatInput.addEventListener('keypress', function (e) { if (e.which === 13) sendMsg(); });

        async function loadProjectChat() {
            if (!chatMessages) return;
            try {
                const res = await fetch(baseDir + '/api/project_chat.php?project_id=' + projectId);
                const json = await res.json();
                if (json && json.messages) {
                    chatMessages.innerHTML = json.messages.map(m => {
                        var isMe = String(m.user_id) === String(userId);
                        var cls = isMe ? 'bg-primary text-white ms-auto' : 'bg-light border me-auto';
                        var align = isMe ? 'align-items-end' : 'align-items-start';
                        return `
                            <div class="d-flex flex-column ${align} mb-2" style="max-width: 85%;">
                                <div class="p-2 rounded shadow-sm small ${cls}">${escapeHtml(m.message)}</div>
                                <div class="text-muted mt-1" style="font-size: 0.65rem;">
                                    ${isMe ? 'You' : escapeHtml(m.user_name)} • ${m.created_at_short}
                                </div>
                            </div>
                        `;
                    }).join('');
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            } catch (e) { }
        }

        async function sendMsg() {
            var txt = chatInput.value.trim();
            if (!txt) return;
            chatInput.value = '';
            try {
                const fd = new FormData();
                fd.append('action', 'send');
                fd.append('project_id', projectId);
                fd.append('message', txt);
                const res = await fetch(baseDir + '/api/project_chat.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success) loadProjectChat();
            } catch (e) { }
        }

        setInterval(function () {
            if (!chatWidget.classList.contains('d-none')) loadProjectChat();
        }, 15000);

        dragElement(chatWidget);
    }

    // --- Initialize Everything ---
    document.addEventListener('DOMContentLoaded', function () {
        // Ensure main tab panes are direct children of #projectTabsContent
        (function () {
            var container = document.getElementById('projectTabsContent');
            if (!container) return;
            // Keep tab panes in sync with the actual main tab buttons to avoid missing panes.
            var topTabs = Array.from(document.querySelectorAll('#projectTabs button[data-bs-target^="#"]'))
                .map(function (btn) {
                    var target = String(btn.getAttribute('data-bs-target') || '').trim();
                    return target.charAt(0) === '#' ? target.slice(1) : '';
                })
                .filter(function (id) { return id !== ''; });
            topTabs.forEach(function (id) {
                var pane = document.getElementById(id);
                if (pane && pane.parentElement !== container) {
                    container.appendChild(pane);
                }
            });
        })();

        // Apply tab/subtab from query params (if present)
        (function () {
            var params = new URLSearchParams(window.location.search);
            var tabParam = params.get('tab');
            var subtabParam = params.get('subtab');

            if (tabParam) {
                var target = '#' + tabParam;
                var tabBtn = document.querySelector('#projectTabs button[data-bs-target="' + target + '"]');
                if (tabBtn) {
                    try { new bootstrap.Tab(tabBtn).show(); } catch (e) { tabBtn.click(); }
                    var pId = window.ProjectConfig.projectId || projectId;
                    if (pId) localStorage.setItem('pms_project_tab_' + pId, target);
                }
            }

            if (subtabParam) {
                var subTarget = '#' + subtabParam;
                var subBtn = document.querySelector('button[data-bs-target="' + subTarget + '"]');
                if (subBtn) {
                    try { new bootstrap.Tab(subBtn).show(); } catch (e) { subBtn.click(); }
                    var subTabs = ['#issuesSubTabs', '#pagesSubTabs', '#assetsSubTabs', '#teamSubTabs', '#regressionSubTabs'];
                    subTabs.forEach(function (sel) {
                        var el = document.querySelector(sel);
                        if (el && el.contains(subBtn)) {
                            var pId2 = window.ProjectConfig.projectId || projectId;
                            if (pId2) {
                                var storeKey = 'pms_project_subtab_' + pId2 + '_' + sel;
                                localStorage.setItem(storeKey, subTarget);
                            }
                        }
                    });
                }
            }
        })();

        restoreTabState();
        initTabPersistence();
        initChatWidget();
        // Init table resizing
        document.querySelectorAll('table.resizable').forEach(t => createResizableTable(t));
        // Edit page modal field toggle
        var editFieldEl = document.getElementById('editPage_field');
        if (editFieldEl) {
            editFieldEl.addEventListener('change', function () {
                var v = this.value;
                if (v === 'notes') {
                    document.getElementById('editPage_input_wrap').classList.add('d-none');
                    document.getElementById('editPage_text_wrap').classList.remove('d-none');
                } else {
                    document.getElementById('editPage_input_wrap').classList.remove('d-none');
                    document.getElementById('editPage_text_wrap').classList.add('d-none');
                }
            });
        }

        // Focus edit button after returning from assignments
        var params = new URLSearchParams(window.location.search);
        var focusPageId = params.get('focus_page_id');
        if (focusPageId) {
            setTimeout(function () {
                var btn = document.querySelector('[data-page-edit-id="' + focusPageId + '"]');
                if (btn) {
                    btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    btn.focus();
                }
            }, 350);
        }
    });

    // --- Utilities ---
    function dragElement(elmnt) {
        var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
        var header = document.getElementById(elmnt.id + "Header");
        if (header) {
            header.onmousedown = dragMouseDown;
        } else {
            elmnt.onmousedown = dragMouseDown;
        }
        function dragMouseDown(e) {
            e = e || window.event;
            if (['BUTTON', 'INPUT', 'TEXTAREA', 'I'].includes(e.target.tagName)) return;
            e.preventDefault();
            pos3 = e.clientX;
            pos4 = e.clientY;
            document.onmouseup = closeDragElement;
            document.onmousemove = elementDrag;
        }
        function elementDrag(e) {
            e = e || window.event;
            e.preventDefault();
            pos1 = pos3 - e.clientX;
            pos2 = pos4 - e.clientY;
            pos3 = e.clientX;
            pos4 = e.clientY;
            elmnt.style.top = (elmnt.offsetTop - pos2) + "px";
            elmnt.style.left = (elmnt.offsetLeft - pos1) + "px";
        }
        function closeDragElement() {
            document.onmouseup = null;
            document.onmousemove = null;
        }
    }

    window.escapeHtml = function (text) {
        if (!text) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    };

    window.openTextViewer = function (title, content) {
        document.getElementById('textViewTitle').textContent = title || 'View Content';
        document.getElementById('textViewContent').textContent = content || '';
        new bootstrap.Modal(document.getElementById('textViewModal')).show();
    };

    // Edit page name / notes for Unique Pages tab — open modal instead of prompt
    window.handleEditPageName = function (btn) {
        if (!btn) return false;
        var field = btn.getAttribute('data-field');
        var uniqueId = btn.getAttribute('data-unique-id') || 0;
        var pageId = btn.getAttribute('data-page-id') || 0;
        var current = btn.getAttribute('data-current-name') || '';

        // Fill modal
        var modalEl = document.getElementById('editPageModal');
        if (!modalEl) {
            // fallback to prompt if modal missing
            var fallback = prompt(field === 'notes' ? 'Enter notes:' : (field === 'canonical_url' ? 'Enter unique URL:' : 'Enter page name:'), current);
            if (fallback === null) return false;
            return window.handleEditPageNameFallback(btn, fallback);
        }

        document.getElementById('editPage_unique_id').value = uniqueId;
        document.getElementById('editPage_page_id').value = pageId;
        document.getElementById('editPage_field').value = field;
        
        // hide the field selector when opened from an Edit button so user edits only the clicked field
        var fieldWrapper = document.getElementById('editPage_field').closest('.mb-3');
        if (fieldWrapper) {
            fieldWrapper.style.display = 'none';
            modalEl._editFieldHidden = true;
        }
        // update modal title to reflect target field
        try { 
            var titleText = 'Edit Page Name';
            if (field === 'notes') titleText = 'Edit Notes';
            else if (field === 'canonical_url') titleText = 'Edit Unique URL';
            else if (field === 'page_number') titleText = 'Edit Page Number';
            modalEl.querySelector('.modal-title').textContent = titleText; 
        } catch (e) {}
        // toggle input types
        if (field === 'notes') {
            document.getElementById('editPage_input_wrap').classList.add('d-none');
            document.getElementById('editPage_text_wrap').classList.remove('d-none');
            document.getElementById('editPage_text').value = current;
        } else {
            document.getElementById('editPage_input_wrap').classList.remove('d-none');
            document.getElementById('editPage_text_wrap').classList.add('d-none');
            document.getElementById('editPage_value').value = current;
            var labelText = 'Value';
            if (field === 'page_name') labelText = 'Page Name';
            else if (field === 'canonical_url') labelText = 'Unique URL';
            else if (field === 'page_number') labelText = 'Page Number';
            document.getElementById('editPage_label').textContent = labelText;
        }

        // show modal
        var bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();

        // attach one-time save handler
        var saveBtn = document.getElementById('editPageSaveBtn');
        var handler = function () {
            var f = document.getElementById('editPage_field').value;
            var val = f === 'notes' ? document.getElementById('editPage_text').value : document.getElementById('editPage_value').value;
            val = String(val || '').trim();
            
            // For page_number field, allow empty values
            if (val === '' && !['notes', 'page_number'].includes(f)) { 
                alert('Value required'); 
                return; 
            }

            var payload = { 
                project_id: projectId, 
                unique_page_id: parseInt(uniqueId, 10) || 0, 
                page_id: (pageId !== '' ? parseInt(pageId, 10) : 0), 
                field: f 
            };
            
            // Use appropriate parameter name based on field
            if (f === 'page_number') {
                payload['page_name'] = val; // API expects page_name parameter for all fields
            } else {
                payload['page_name'] = val;
            }

            saveBtn.disabled = true;
            fetch(baseDir + '/api/project_pages.php?action=update_page_name', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), credentials: 'same-origin'
            }).then(r => r.json()).then(function (j) {
                saveBtn.disabled = false;
                if (j && j.success) {
                    // update UI: find the related display element in the same row
                    try {
                    var parentRow = btn.closest('tr') || btn.parentElement;
                    var targetSelector = '.page-name-display';
                    if (f === 'notes') targetSelector = '.notes-display';
                    else if (f === 'canonical_url') targetSelector = '.unique-url-display';
                    else if (f === 'page_number') targetSelector = '.page-no-display';
                    
                    var target = parentRow ? parentRow.querySelector(targetSelector) : null;
                        if (target) target.textContent = val;
                        btn.setAttribute('data-current-name', val);
                        if (f === 'notes' && parentRow) {
                            var deleteBtn = parentRow.querySelector('button[onclick*="handleDeletePageNotes"]');
                            if (deleteBtn) {
                                if (val) deleteBtn.classList.remove('d-none');
                                else deleteBtn.classList.add('d-none');
                            }
                        }
                    } catch (e) { 
                        console.error('UI update error:', e);
                    }
                    bsModal.hide();
                } else {
                    console.error('API Error:', j);
                    alert('Update failed: ' + (j && j.error ? j.error : 'Unknown error'));
                }
            }).catch(function (err) { 
                saveBtn.disabled = false; 
                console.error('Request error:', err);
                alert('Request failed: ' + err.message); 
            });

            // cleanup
            saveBtn.removeEventListener('click', handler);
        };
        // remove previous handlers then attach (clear onclick then add)
        try { saveBtn.onclick = null; } catch (e) {}
        saveBtn.addEventListener('click', handler);

        // restore selector when modal is hidden
        modalEl.addEventListener('hidden.bs.modal', function onHidden() {
            try {
                var fw = document.getElementById('editPage_field').closest('.mb-3');
                if (fw) fw.style.display = '';
                modalEl._editFieldHidden = false;
                // reset title
                try { modalEl.querySelector('.modal-title').textContent = 'Edit Page'; } catch (e) {}
                // clear inputs
                document.getElementById('editPage_value').value = '';
                document.getElementById('editPage_text').value = '';
                // reset field selector to default
                document.getElementById('editPage_field').value = 'page_name';
            } catch (e) {}
            // remove this listener after run
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        });

        return false;
    };

    // fallback helper used by prompt fallback above
    window.handleEditPageNameFallback = function (btn, val) {
        var field = btn.getAttribute('data-field');
        var uniqueId = btn.getAttribute('data-unique-id') || 0;
        var pageId = btn.getAttribute('data-page-id') || 0;
        
        // Validate input for non-optional fields
        if (!val && !['notes', 'page_number'].includes(field)) {
            alert('Value required');
            return;
        }
        
        fetch(baseDir + '/api/project_pages.php?action=update_page_name', {
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' }, 
            body: JSON.stringify({ 
                project_id: projectId, 
                unique_page_id: uniqueId, 
                page_id: pageId, 
                field: field, 
                page_name: val 
            }), 
            credentials: 'same-origin'
        }).then(r => r.json()).then(function (j) {
            if (j && j.success) {
                var parent = btn.closest('tr') || btn.parentElement;
                var targetSelector = '.page-name-display';
                if (field === 'notes') targetSelector = '.notes-display';
                else if (field === 'canonical_url') targetSelector = '.unique-url-display';
                else if (field === 'page_number') targetSelector = '.page-no-display';
                
                var target = parent ? parent.querySelector(targetSelector) : null;
                if (target) target.textContent = val;
                btn.setAttribute('data-current-name', val);
                if (field === 'notes' && parent) {
                    var deleteBtn = parent.querySelector('button[onclick*="handleDeletePageNotes"]');
                    if (deleteBtn) {
                        if (val) deleteBtn.classList.remove('d-none');
                        else deleteBtn.classList.add('d-none');
                    }
                }
            } else {
                console.error('Fallback API Error:', j);
                alert('Update failed: ' + (j && j.error ? j.error : 'Unknown error'));
            }
        }).catch(function (err) { 
            console.error('Fallback Request error:', err);
            alert('Request failed: ' + err.message); 
        });
        return false;
    };

    window.handleDeletePageNotes = function (btn) {
        if (!btn) return false;
        var uniqueId = btn.getAttribute('data-unique-id') || 0;
        var pageId = btn.getAttribute('data-page-id') || 0;
        var notesEl = btn.closest('td') ? btn.closest('td').querySelector('.notes-display') : null;
        var hasNotes = notesEl && String(notesEl.textContent || '').trim() !== '';
        if (!hasNotes) return false;

        var doDelete = function () {
            btn.disabled = true;
            fetch(baseDir + '/api/project_pages.php?action=update_page_name', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: projectId,
                    unique_page_id: parseInt(uniqueId, 10) || 0,
                    page_id: (pageId !== '' ? parseInt(pageId, 10) : 0),
                    field: 'notes',
                    page_name: ''
                }),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (j) {
                btn.disabled = false;
                if (j && j.success) {
                    if (notesEl) notesEl.textContent = '';
                    var editBtn = btn.closest('td') ? btn.closest('td').querySelector('.edit-page-name[data-field="notes"]') : null;
                    if (editBtn) editBtn.setAttribute('data-current-name', '');
                    btn.classList.add('d-none');
                    if (typeof showToast === 'function') showToast('Notes deleted', 'success');
                } else {
                    alert('Delete failed');
                }
            }).catch(function () {
                btn.disabled = false;
                alert('Request failed');
            });
        };

        if (typeof confirmModal === 'function') {
            confirmModal('Delete notes for this page?', doDelete);
        } else if (confirm('Delete notes for this page?')) {
            doDelete();
        }

        return false;
    };

    // Asset Types Toggle
    document.querySelectorAll('input[name="asset_type_option"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var val = this.value;
            document.getElementById('assetFileGroup').classList.toggle('d-none', val !== 'file');
            document.getElementById('assetLinkGroup').classList.toggle('d-none', val !== 'link');
            document.getElementById('assetCredsGroup').classList.toggle('d-none', val !== 'creds');
        });
    });

    window.validateAddAsset = function () {
        var type = document.querySelector('input[name="asset_type_option"]:checked').value;
        if (type === 'file' && !document.querySelector('input[name="asset_file"]').files.length) { alert('Please select a file'); return false; }
        if (type === 'link' && !document.querySelector('input[name="asset_link"]').value) { alert('Please enter a link'); return false; }
        if (type === 'creds' && !document.querySelector('input[name="asset_username"]').value && !document.querySelector('input[name="asset_password"]').value) { alert('Please enter username or password'); return false; }
        return true;
    };

    window.openAddPhaseModal = function () { new bootstrap.Modal(document.getElementById('addPhaseModal')).show(); };
    window.openEditPhaseModal = function (id, name, desc, start, end, status) {
        document.getElementById('edit_phase_id').value = id;
        document.getElementById('edit_phase_name').value = name;
        document.getElementById('edit_phase_description').value = desc;
        document.getElementById('edit_start_date').value = start;
        document.getElementById('edit_end_date').value = end;
        document.getElementById('edit_phase_status').value = status;
        new bootstrap.Modal(document.getElementById('editPhaseModal')).show();
    };

    window.deletePhase = function (id) {
        if (typeof confirmModal === 'function') {
            confirmModal('Delete this phase? This action cannot be undone.', function() {
                var fd = new FormData();
                fd.append('delete_phase', '1');
                fd.append('phase_id', id);
                fd.append('project_id', projectId);
                fetch(baseDir + '/modules/projects/phases.php', { method: 'POST', body: fd })
                    .then(r => {
                        if (r.ok) location.reload();
                        else alert('Error deleting phase');
                    }).catch(e => alert('Error: ' + e.message));
            });
        } else {
            if (confirm('Delete this phase?')) {
                var fd = new FormData();
                fd.append('delete_phase', '1');
                fd.append('phase_id', id);
                fd.append('project_id', projectId);
                fetch(baseDir + '/modules/projects/phases.php', { method: 'POST', body: fd })
                    .then(r => {
                        if (r.ok) location.reload();
                        else alert('Error deleting phase');
                    }).catch(e => alert('Error: ' + e.message));
            }
        }
    };

    window.deleteAsset = function (id) {
        if (typeof confirmModal === 'function') {
            confirmModal('Delete this asset? This action cannot be undone.', function() {
                var fd = new FormData();
                fd.append('delete_asset', '1');
                fd.append('asset_id', id);
                fd.append('project_id', projectId);
                fetch(baseDir + '/modules/projects/handle_asset.php', { method: 'POST', body: fd })
                    .then(r => {
                        if (r.ok) location.reload();
                        else alert('Error deleting asset');
                    }).catch(e => alert('Error: ' + e.message));
            });
        } else {
            if (confirm('Delete this asset?')) {
                var fd = new FormData();
                fd.append('delete_asset', '1');
                fd.append('asset_id', id);
                fd.append('project_id', projectId);
                fetch(baseDir + '/modules/projects/handle_asset.php', { method: 'POST', body: fd })
                    .then(r => {
                        if (r.ok) location.reload();
                        else alert('Error deleting asset');
                    }).catch(e => alert('Error: ' + e.message));
            }
        }
    };

    // Table Column Resizing
    function createResizableTable(table) {
        const cols = table.querySelectorAll('th');
        cols.forEach((col) => {
            if (col.querySelector('.resizer')) return;
            const resizer = document.createElement('div');
            resizer.classList.add('resizer');
            col.appendChild(resizer);
            createResizableColumn(col, resizer);
        });
    }
    function createResizableColumn(col, resizer) {
        let x = 0, w = 0;
        const mouseDownHandler = function (e) {
            x = e.clientX;
            w = parseInt(window.getComputedStyle(col).width, 10);
            document.addEventListener('mousemove', mouseMoveHandler);
            document.addEventListener('mouseup', mouseUpHandler);
            resizer.classList.add('resizing');
        };
        const mouseMoveHandler = function (e) { col.style.width = (w + (e.clientX - x)) + 'px'; };
        const mouseUpHandler = function () {
            document.removeEventListener('mousemove', mouseMoveHandler);
            document.removeEventListener('mouseup', mouseUpHandler);
            resizer.classList.remove('resizing');
        };
        resizer.addEventListener('mousedown', mouseDownHandler);
    }

})();
