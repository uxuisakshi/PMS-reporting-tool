// Admin Vault JavaScript - Full Version
let credentials = [];
let notes = [];
let todos = [];
let meetings = [];

$(document).ready(function() {
    loadAll();
    
    // Search functionality
    $('#searchCredentials').on('keyup', function() {
        filterCredentials();
    });
});

function loadAll() {
    loadCredentials();
    loadNotes();
    loadTodos();
    loadMeetings();
}

function showDeleteConfirm(message, onConfirm) {
    const modalId = 'vaultDeleteConfirmModal';
    $('#' + modalId).remove();

    const modalHtml = `
        <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning me-2"></i>Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">${escapeHtml(message)}</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="${modalId}Ok">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    $('body').append(modalHtml);
    const el = document.getElementById(modalId);
    const modal = new bootstrap.Modal(el);

    $('#' + modalId + 'Ok').on('click', function () {
        modal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });

    $(el).on('hidden.bs.modal', function () {
        $(this).remove();
    });

    modal.show();
}

// ===== CREDENTIALS =====
function loadCredentials() {
    $.get('../../api/admin_vault.php?action=get_credentials', function(response) {
        if (response.success) {
            credentials = response.credentials;
            $('#credentialsCount').text(credentials.length);
            renderCredentials();
        }
    });
}

function renderCredentials() {
    const container = $('#credentialsList');
    container.empty();
    
    if (credentials.length === 0) {
        container.html('<div class="alert alert-info"><i class="fas fa-info-circle"></i> No credentials stored yet. Click "Add Credential" to get started.</div>');
        return;
    }
    
    credentials.forEach(cred => {
        const categoryIcons = {
            'Software': 'fa-laptop-code',
            'Device': 'fa-mobile-alt',
            'Account': 'fa-user-circle',
            'Server': 'fa-server',
            'Database': 'fa-database',
            'API': 'fa-code',
            'Other': 'fa-folder'
        };
        
        container.append(`
            <div class="credential-card" data-id="${cred.id}" data-category="${cred.category}">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="mb-2">
                            <i class="fas ${categoryIcons[cred.category] || 'fa-key'} text-primary"></i> 
                            ${escapeHtml(cred.title)}
                        </h5>
                        <p class="mb-2">
                            <span class="badge bg-secondary">${cred.category}</span>
                            ${cred.last_used ? `<span class="badge bg-info">Last used: ${new Date(cred.last_used).toLocaleDateString()}</span>` : ''}
                        </p>
                        ${cred.username ? `
                            <p class="mb-1">
                                <strong><i class="fas fa-user"></i> Username:</strong> 
                                <code>${escapeHtml(cred.username)}</code>
                                <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard('${escapeHtml(cred.username)}', 'Username')">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </p>
                        ` : ''}
                        ${cred.url ? `
                            <p class="mb-1">
                                <strong><i class="fas fa-link"></i> URL:</strong> 
                                <a href="${escapeHtml(cred.url)}" target="_blank">${escapeHtml(cred.url)}</a>
                            </p>
                        ` : ''}
                        ${cred.notes ? `
                            <p class="mb-1 text-muted">
                                <small><i class="fas fa-sticky-note"></i> ${escapeHtml(cred.notes)}</small>
                            </p>
                        ` : ''}
                        ${cred.tags ? `
                            <p class="mb-0">
                                <small class="text-muted">
                                    <i class="fas fa-tags"></i> ${escapeHtml(cred.tags)}
                                </small>
                            </p>
                        ` : ''}
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group-vertical w-100">
                            <button class="btn btn-info btn-sm mb-1" onclick="viewPassword(${cred.id})" title="View Password">
                                <i class="fas fa-eye"></i> View Password
                            </button>
                            <button class="btn btn-primary btn-sm mb-1" onclick="editCredential(${cred.id})" title="Edit">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-success btn-sm mb-1" onclick="markAsUsed(${cred.id})" title="Mark as Used">
                                <i class="fas fa-check"></i> Mark Used
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteCredential(${cred.id})" title="Delete">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    });
}

function filterCredentials() {
    const search = $('#searchCredentials').val().toLowerCase();
    const category = $('#filterCategory').val();
    
    $('.credential-card').each(function() {
        const text = $(this).text().toLowerCase();
        const cardCategory = $(this).data('category');
        
        const matchesSearch = !search || text.includes(search);
        const matchesCategory = !category || cardCategory === category;
        
        $(this).toggle(matchesSearch && matchesCategory);
    });
}

function filterCredentialsByCategory() {
    filterCredentials();
}

function viewPassword(id) {
    $.get('../../api/admin_vault.php?action=get_credential_password&id=' + id, function(response) {
        if (response.success) {
            const modal = `
                <div class="modal fade" id="passwordModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-info text-white">
                                <h5 class="modal-title"><i class="fas fa-key"></i> Password</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i> Keep this password secure!
                                </div>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-lg" value="${escapeHtml(response.password)}" id="passwordField" readonly>
                                    <button class="btn btn-primary" onclick="copyToClipboard('${escapeHtml(response.password)}', 'Password')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('body').append(modal);
            $('#passwordModal').modal('show');
            $('#passwordModal').on('hidden.bs.modal', function() {
                $(this).remove();
            });
        } else {
            showToast(response.message, 'danger');
        }
    });
}

function markAsUsed(id) {
    $.post('../../api/admin_vault.php', {
        action: 'mark_credential_used',
        id: id
    }, function(response) {
        if (response.success) {
            loadCredentials();
            showToast('Marked as used', 'success');
        }
    });
}

function showAddCredentialModal() {
    showCredentialModal();
}

function editCredential(id) {
    const cred = credentials.find(c => c.id == id);
    if (!cred) return;
    showCredentialModal(cred);
}

function showCredentialModal(cred = null) {
    const isEdit = cred !== null;
    const modal = `
        <div class="modal fade" id="credentialModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-key"></i> ${isEdit ? 'Edit' : 'Add'} Credential
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="credentialForm">
                            <input type="hidden" id="credId" name="id" value="${isEdit ? cred.id : ''}">
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-heading"></i> Title *</label>
                                        <input type="text" class="form-control" name="title" value="${isEdit ? escapeHtml(cred.title) : ''}" required placeholder="e.g., Adobe Creative Cloud">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-folder"></i> Category *</label>
                                        <select class="form-select" name="category" required>
                                            <option value="Software" ${isEdit && cred.category === 'Software' ? 'selected' : ''}>Software</option>
                                            <option value="Device" ${isEdit && cred.category === 'Device' ? 'selected' : ''}>Device</option>
                                            <option value="Account" ${isEdit && cred.category === 'Account' ? 'selected' : ''}>Account</option>
                                            <option value="Server" ${isEdit && cred.category === 'Server' ? 'selected' : ''}>Server</option>
                                            <option value="Database" ${isEdit && cred.category === 'Database' ? 'selected' : ''}>Database</option>
                                            <option value="API" ${isEdit && cred.category === 'API' ? 'selected' : ''}>API</option>
                                            <option value="Other" ${isEdit && cred.category === 'Other' ? 'selected' : ''}>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-user"></i> Username</label>
                                        <input type="text" class="form-control" name="username" value="${isEdit && cred.username ? escapeHtml(cred.username) : ''}" placeholder="Username or email">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-lock"></i> Password ${isEdit ? '(leave blank to keep current)' : '*'}</label>
                                        <div class="input-group">
                                            <input type="password" autocomplete="off" class="form-control" name="password" id="credPassword" ${!isEdit ? 'required' : ''} placeholder="Enter password">
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('credPassword')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-primary" type="button" onclick="generatePassword()">
                                                <i class="fas fa-random"></i> Generate
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-link"></i> URL</label>
                                <input type="url" class="form-control" name="url" value="${isEdit && cred.url ? escapeHtml(cred.url) : ''}" placeholder="https://example.com">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-sticky-note"></i> Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Additional information...">${isEdit && cred.notes ? escapeHtml(cred.notes) : ''}</textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-tags"></i> Tags (comma separated)</label>
                                <input type="text" class="form-control" name="tags" value="${isEdit && cred.tags ? escapeHtml(cred.tags) : ''}" placeholder="adobe, design, subscription">
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveCredential()">
                            <i class="fas fa-save"></i> Save Credential
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('body').append(modal);
    $('#credentialModal').modal('show');
    $('#credentialModal').on('hidden.bs.modal', function() {
        $(this).remove();
    });
}

function saveCredential() {
    const action = $('#credId').val() ? 'update_credential' : 'add_credential';
    const payload = $('#credentialForm').serialize() + '&action=' + encodeURIComponent(action);
    
    $.ajax({
        url: '../../api/admin_vault.php',
        type: 'POST',
        data: payload,
        contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#credentialModal').modal('hide');
                loadCredentials();
                showToast(response.message, 'success');
            } else {
                showToast(response.message, 'danger');
            }
        }
    });
}

function deleteCredential(id) {
    showDeleteConfirm('Are you sure you want to delete this credential? This action cannot be undone.', function () {
        $.post('../../api/admin_vault.php', {
            action: 'delete_credential',
            id: id
        }, function(response) {
            if (response.success) {
                loadCredentials();
                showToast(response.message, 'success');
            } else {
                showToast(response.message, 'danger');
            }
        });
    });
}

// ===== NOTES =====
function loadNotes() {
    $.get('../../api/admin_vault.php?action=get_notes', function(response) {
        if (response.success) {
            notes = response.notes;
            $('#notesCount').text(notes.length);
            renderNotes();
        }
    });
}

function renderNotes() {
    const container = $('#notesList');
    container.empty();
    
    if (notes.length === 0) {
        container.html('<div class="col-12"><div class="alert alert-info"><i class="fas fa-info-circle"></i> No notes yet. Click "Add Note" to create your first note.</div></div>');
        return;
    }
    
    notes.forEach(note => {
        const pinIcon = note.is_pinned == 1 ? '<i class="fas fa-thumbtack pin-icon text-danger" title="Pinned"></i>' : '';
        
        container.append(`
            <div class="col-md-4" data-note-category="${note.category}">
                <div class="note-card" style="background-color: ${note.color}; border: 2px solid ${adjustColor(note.color, -20)};">
                    ${pinIcon}
                    <h6 class="mb-2"><strong>${escapeHtml(note.title)}</strong></h6>
                    <p class="small mb-2" style="max-height: 100px; overflow-y: auto;">${escapeHtml(note.content || '')}</p>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            <span class="badge bg-secondary">${note.category}</span>
                            ${note.tags ? `<br><small class="text-muted">${escapeHtml(note.tags)}</small>` : ''}
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-sm btn-primary" onclick="editNote(${note.id})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteNote(${note.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <i class="fas fa-clock"></i> ${new Date(note.updated_at).toLocaleString()}
                    </small>
                </div>
            </div>
        `);
    });
}

function filterNotes() {
    const search = $('#searchNotes').val().toLowerCase();
    const category = $('#filterNoteCategory').val();
    
    $('#notesList > div').each(function() {
        const text = $(this).text().toLowerCase();
        const noteCategory = $(this).data('note-category');
        
        const matchesSearch = !search || text.includes(search);
        const matchesCategory = !category || noteCategory === category;
        
        $(this).toggle(matchesSearch && matchesCategory);
    });
}

function showAddNoteModal() {
    showNoteModal();
}

function editNote(id) {
    const note = notes.find(n => n.id == id);
    if (!note) return;
    showNoteModal(note);
}

function showNoteModal(note = null) {
    const isEdit = note !== null;
    const colors = ['#ffffff', '#fff3cd', '#d1ecf1', '#d4edda', '#f8d7da', '#e2e3e5', '#cfe2ff'];
    
    const modal = `
        <div class="modal fade" id="noteModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-sticky-note"></i> ${isEdit ? 'Edit' : 'Add'} Note
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="noteForm">
                            <input type="hidden" id="noteId" name="id" value="${isEdit ? note.id : ''}">
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-heading"></i> Title *</label>
                                <input type="text" class="form-control" name="title" value="${isEdit ? escapeHtml(note.title) : ''}" required placeholder="Note title">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-align-left"></i> Content</label>
                                <textarea class="form-control" id="noteContentEditor" name="content" rows="6" placeholder="Write your note here...">${isEdit && note.content ? escapeHtml(note.content) : ''}</textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-folder"></i> Category</label>
                                        <select class="form-select" name="category">
                                            <option value="General" ${isEdit && note.category === 'General' ? 'selected' : ''}>General</option>
                                            <option value="Project" ${isEdit && note.category === 'Project' ? 'selected' : ''}>Project</option>
                                            <option value="Meeting" ${isEdit && note.category === 'Meeting' ? 'selected' : ''}>Meeting</option>
                                            <option value="Technical" ${isEdit && note.category === 'Technical' ? 'selected' : ''}>Technical</option>
                                            <option value="Personal" ${isEdit && note.category === 'Personal' ? 'selected' : ''}>Personal</option>
                                            <option value="Important" ${isEdit && note.category === 'Important' ? 'selected' : ''}>Important</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-palette"></i> Color</label>
                                        <div class="d-flex gap-2">
                                            ${colors.map(color => `
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="color" value="${color}" 
                                                           ${isEdit && note.color === color ? 'checked' : (!isEdit && color === '#ffffff' ? 'checked' : '')}
                                                           style="background-color: ${color}; border: 2px solid #000;">
                                                </div>
                                            `).join('')}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_pinned" value="1" 
                                                   ${isEdit && note.is_pinned == 1 ? 'checked' : ''}>
                                            <label class="form-check-label">
                                                <i class="fas fa-thumbtack"></i> Pin this note
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-tags"></i> Tags</label>
                                        <input type="text" class="form-control" name="tags" value="${isEdit && note.tags ? escapeHtml(note.tags) : ''}" placeholder="tag1, tag2, tag3">
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveNote()">
                            <i class="fas fa-save"></i> Save Note
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('body').append(modal);
    $('#noteModal').on('shown.bs.modal', function() {
        if (window.jQuery && jQuery.fn.summernote) {
            $('#noteContentEditor').summernote({
                height: 220,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['fontname', ['fontname']],
                    ['fontsize', ['fontsize']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['insert', ['link']],
                    ['view', ['codeview']]
                ]
            });
        }
    });
    $('#noteModal').modal('show');
    $('#noteModal').on('hidden.bs.modal', function() {
        if (window.jQuery && jQuery.fn.summernote && $('#noteContentEditor').next('.note-editor').length) {
            try { $('#noteContentEditor').summernote('destroy'); } catch (e) {}
        }
        $(this).remove();
    });
}

function saveNote() {
    if (window.jQuery && jQuery.fn.summernote && $('#noteContentEditor').next('.note-editor').length) {
        $('#noteContentEditor').val($('#noteContentEditor').summernote('code'));
    }
    const formData = new FormData($('#noteForm')[0]);
    const action = $('#noteId').val() ? 'update_note' : 'add_note';
    formData.append('action', action);
    
    if (!formData.get('is_pinned')) {
        formData.append('is_pinned', '0');
    }
    
    $.ajax({
        url: '../../api/admin_vault.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#noteModal').modal('hide');
                loadNotes();
                showToast(response.message, 'success');
            } else {
                showToast(response.message, 'danger');
            }
        }
    });
}

function deleteNote(id) {
    showDeleteConfirm('Delete this note?', function () {
        $.post('../../api/admin_vault.php', {
            action: 'delete_note',
            id: id
        }, function(response) {
            if (response.success) {
                loadNotes();
                showToast(response.message, 'success');
            }
        });
    });
}

// Continue in next part due to length...

// ===== TODOS =====
function loadTodos() {
    $.get('../../api/admin_vault.php?action=get_todos', function(response) {
        if (response.success) {
            todos = response.todos;
            const pendingCount = todos.filter(t => t.status === 'Pending' || t.status === 'In Progress').length;
            $('#todosCount').text(pendingCount);
            renderTodos();
        }
    });
}

function renderTodos() {
    const container = $('#todosList');
    container.empty();
    
    if (todos.length === 0) {
        container.html('<div class="alert alert-info"><i class="fas fa-info-circle"></i> No todos yet. Click "Add Todo" to create your first task.</div>');
        return;
    }
    
    todos.forEach(todo => {
        const priorityClass = todo.priority.toLowerCase();
        const statusBadges = {
            'Pending': 'warning',
            'In Progress': 'info',
            'Completed': 'success',
            'Cancelled': 'secondary'
        };
        const statusBadge = statusBadges[todo.status] || 'secondary';
        const isCompleted = todo.status === 'Completed';
        const isOverdue = todo.due_date && new Date(todo.due_date) < new Date() && !isCompleted;
        
        container.append(`
            <div class="todo-item ${priorityClass} ${isCompleted ? 'completed' : ''}" data-status="${todo.status}" data-priority="${todo.priority}">
                <div class="row">
                    <div class="col-md-8">
                        <h6 class="mb-1">
                            ${isCompleted ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-circle text-muted"></i>'}
                            ${escapeHtml(todo.title)}
                            ${isOverdue ? '<span class="badge bg-danger ms-2"><i class="fas fa-exclamation-triangle"></i> Overdue</span>' : ''}
                        </h6>
                        ${todo.description ? `<p class="mb-2 small text-muted">${escapeHtml(todo.description)}</p>` : ''}
                        <div class="mb-2">
                            <span class="badge bg-${statusBadge}">${todo.status}</span>
                            <span class="badge bg-secondary">${todo.priority}</span>
                            ${todo.due_date ? `<span class="badge bg-info"><i class="fas fa-calendar"></i> ${todo.due_date}</span>` : ''}
                            ${todo.tags ? `<span class="badge bg-light text-dark"><i class="fas fa-tags"></i> ${escapeHtml(todo.tags)}</span>` : ''}
                        </div>
                        ${todo.completed_at ? `<small class="text-success"><i class="fas fa-check"></i> Completed: ${new Date(todo.completed_at).toLocaleString()}</small>` : ''}
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group">
                            ${!isCompleted ? `
                                <button class="btn btn-sm btn-success" onclick="markTodoComplete(${todo.id})" title="Mark Complete">
                                    <i class="fas fa-check"></i>
                                </button>
                            ` : ''}
                            <button class="btn btn-sm btn-primary" onclick="editTodo(${todo.id})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteTodo(${todo.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    });
}

function filterTodos() {
    const status = $('#filterTodoStatus').val();
    const priority = $('#filterTodoPriority').val();
    const search = $('#searchTodos').val().toLowerCase();
    
    $('.todo-item').each(function() {
        const itemStatus = $(this).data('status');
        const itemPriority = $(this).data('priority');
        const text = $(this).text().toLowerCase();
        
        const matchesStatus = !status || itemStatus === status;
        const matchesPriority = !priority || itemPriority === priority;
        const matchesSearch = !search || text.includes(search);
        
        $(this).toggle(matchesStatus && matchesPriority && matchesSearch);
    });
}

function showAddTodoModal() {
    showTodoModal();
}

function editTodo(id) {
    const todo = todos.find(t => t.id == id);
    if (!todo) return;
    showTodoModal(todo);
}

function showTodoModal(todo = null) {
    const isEdit = todo !== null;
    const modal = `
        <div class="modal fade" id="todoModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="fas fa-tasks"></i> ${isEdit ? 'Edit' : 'Add'} Todo
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="todoForm">
                            <input type="hidden" id="todoId" name="id" value="${isEdit ? todo.id : ''}">
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-heading"></i> Title *</label>
                                <input type="text" class="form-control" name="title" value="${isEdit ? escapeHtml(todo.title) : ''}" required placeholder="Task title">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-align-left"></i> Description</label>
                                <textarea class="form-control" name="description" rows="4" placeholder="Task details...">${isEdit && todo.description ? escapeHtml(todo.description) : ''}</textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-exclamation-circle"></i> Priority</label>
                                        <select class="form-select" name="priority">
                                            <option value="Low" ${isEdit && todo.priority === 'Low' ? 'selected' : ''}>Low</option>
                                            <option value="Medium" ${isEdit && todo.priority === 'Medium' ? 'selected' : (!isEdit ? 'selected' : '')}>Medium</option>
                                            <option value="High" ${isEdit && todo.priority === 'High' ? 'selected' : ''}>High</option>
                                            <option value="Critical" ${isEdit && todo.priority === 'Critical' ? 'selected' : ''}>Critical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-flag"></i> Status</label>
                                        <select class="form-select" name="status">
                                            <option value="Pending" ${isEdit && todo.status === 'Pending' ? 'selected' : (!isEdit ? 'selected' : '')}>Pending</option>
                                            <option value="In Progress" ${isEdit && todo.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                                            <option value="Completed" ${isEdit && todo.status === 'Completed' ? 'selected' : ''}>Completed</option>
                                            <option value="Cancelled" ${isEdit && todo.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-calendar"></i> Due Date</label>
                                        <input type="date" class="form-control" name="due_date" value="${isEdit && todo.due_date ? todo.due_date : ''}">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-tags"></i> Tags</label>
                                <input type="text" class="form-control" name="tags" value="${isEdit && todo.tags ? escapeHtml(todo.tags) : ''}" placeholder="critical, client, review">
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveTodo()">
                            <i class="fas fa-save"></i> Save Todo
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('body').append(modal);
    $('#todoModal').modal('show');
    $('#todoModal').on('hidden.bs.modal', function() {
        $(this).remove();
    });
}

function saveTodo() {
    const formData = new FormData($('#todoForm')[0]);
    const action = $('#todoId').val() ? 'update_todo' : 'add_todo';
    formData.append('action', action);
    
    $.ajax({
        url: '../../api/admin_vault.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#todoModal').modal('hide');
                loadTodos();
                showToast(response.message, 'success');
            } else {
                showToast(response.message, 'danger');
            }
        }
    });
}

function markTodoComplete(id) {
    $.post('../../api/admin_vault.php', {
        action: 'update_todo',
        id: id,
        status: 'Completed',
        title: todos.find(t => t.id == id).title,
        priority: todos.find(t => t.id == id).priority
    }, function(response) {
        if (response.success) {
            loadTodos();
            showToast('Todo marked as complete!', 'success');
        }
    });
}

function deleteTodo(id) {
    showDeleteConfirm('Delete this todo?', function () {
        $.post('../../api/admin_vault.php', {
            action: 'delete_todo',
            id: id
        }, function(response) {
            if (response.success) {
                loadTodos();
                showToast(response.message, 'success');
            }
        });
    });
}

// ===== MEETINGS =====
function loadMeetings() {
    $.get('../../api/admin_vault.php?action=get_meetings', function(response) {
        if (response.success) {
            meetings = response.meetings;
            const upcomingCount = meetings.filter(m => m.status === 'Scheduled' && new Date(m.meeting_date) >= new Date()).length;
            $('#meetingsCount').text(upcomingCount);
            renderMeetings();
        }
    });
}

function renderMeetings() {
    const container = $('#meetingsList');
    container.empty();
    
    if (meetings.length === 0) {
        container.html('<div class="alert alert-info"><i class="fas fa-info-circle"></i> No meetings scheduled. Click "Schedule Meeting" to add one.</div>');
        return;
    }
    
    meetings.forEach(meeting => {
        const statusBadges = {
            'Scheduled': 'primary',
            'Completed': 'success',
            'Cancelled': 'danger',
            'Rescheduled': 'warning'
        };
        const statusBadge = statusBadges[meeting.status] || 'secondary';
        
        const meetingDate = new Date(meeting.meeting_date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const isToday = meetingDate.toDateString() === today.toDateString();
        const isUpcoming = meetingDate >= today && meeting.status === 'Scheduled';
        
        const cardClass = isToday ? 'meeting-card today' : (isUpcoming ? 'meeting-card upcoming' : 'meeting-card');
        
        container.append(`
            <div class="${cardClass}" data-status="${meeting.status}">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="mb-2">
                            <i class="fas fa-calendar-alt text-primary"></i> ${escapeHtml(meeting.title)}
                            ${isToday ? '<span class="badge bg-success ms-2">TODAY</span>' : ''}
                        </h5>
                        ${meeting.description ? `<p class="mb-2 text-muted">${escapeHtml(meeting.description)}</p>` : ''}
                        <div class="mb-2">
                            <p class="mb-1">
                                <i class="fas fa-clock text-info"></i> 
                                <strong>${meeting.meeting_date}</strong> at <strong>${meeting.meeting_time}</strong>
                                <span class="text-muted">(${meeting.duration_minutes} minutes)</span>
                            </p>
                            ${meeting.meeting_with ? `
                                <p class="mb-1">
                                    <i class="fas fa-users text-success"></i> 
                                    With: <strong>${escapeHtml(meeting.meeting_with)}</strong>
                                </p>
                            ` : ''}
                            ${meeting.location ? `
                                <p class="mb-1">
                                    <i class="fas fa-map-marker-alt text-danger"></i> 
                                    ${escapeHtml(meeting.location)}
                                </p>
                            ` : ''}
                            ${meeting.meeting_link ? `
                                <p class="mb-1">
                                    <i class="fas fa-video text-primary"></i> 
                                    <a href="${escapeHtml(meeting.meeting_link)}" target="_blank">Join Meeting</a>
                                </p>
                            ` : ''}
                        </div>
                        <div>
                            <span class="badge bg-${statusBadge}">${meeting.status}</span>
                            ${meeting.reminder_minutes ? `<span class="badge bg-info"><i class="fas fa-bell"></i> ${meeting.reminder_minutes} min reminder</span>` : ''}
                        </div>
                        ${meeting.notes ? `
                            <div class="mt-2 p-2 bg-light rounded">
                                <small><strong>Notes:</strong> ${escapeHtml(meeting.notes)}</small>
                            </div>
                        ` : ''}
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group-vertical w-100">
                            ${meeting.meeting_link ? `
                                <a href="${escapeHtml(meeting.meeting_link)}" target="_blank" class="btn btn-success btn-sm mb-1">
                                    <i class="fas fa-video"></i> Join
                                </a>
                            ` : ''}
                            <button class="btn btn-primary btn-sm mb-1" onclick="editMeeting(${meeting.id})" title="Edit">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            ${meeting.status === 'Scheduled' ? `
                                <button class="btn btn-info btn-sm mb-1" onclick="markMeetingComplete(${meeting.id})" title="Mark Complete">
                                    <i class="fas fa-check"></i> Complete
                                </button>
                            ` : ''}
                            <button class="btn btn-danger btn-sm" onclick="deleteMeeting(${meeting.id})" title="Delete">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    });
}

function filterMeetings() {
    const status = $('#filterMeetingStatus').val();
    const search = $('#searchMeetings').val().toLowerCase();
    
    $('.meeting-card').each(function() {
        const itemStatus = $(this).data('status');
        const text = $(this).text().toLowerCase();
        
        const matchesStatus = !status || itemStatus === status;
        const matchesSearch = !search || text.includes(search);
        
        $(this).toggle(matchesStatus && matchesSearch);
    });
}

function showAddMeetingModal() {
    showMeetingModal();
}

function editMeeting(id) {
    const meeting = meetings.find(m => m.id == id);
    if (!meeting) return;
    showMeetingModal(meeting);
}

function showMeetingModal(meeting = null) {
    const isEdit = meeting !== null;
    const modal = `
        <div class="modal fade" id="meetingModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-check"></i> ${isEdit ? 'Edit' : 'Schedule'} Meeting
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="meetingForm">
                            <input type="hidden" id="meetingId" name="id" value="${isEdit ? meeting.id : ''}">
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-heading"></i> Meeting Title *</label>
                                <input type="text" class="form-control" name="title" value="${isEdit ? escapeHtml(meeting.title) : ''}" required placeholder="e.g., Client Review Meeting">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-align-left"></i> Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Meeting agenda...">${isEdit && meeting.description ? escapeHtml(meeting.description) : ''}</textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-calendar"></i> Date *</label>
                                        <input type="date" class="form-control" name="meeting_date" value="${isEdit ? meeting.meeting_date : ''}" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-clock"></i> Time *</label>
                                        <input type="time" class="form-control" name="meeting_time" value="${isEdit ? meeting.meeting_time : ''}" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-hourglass-half"></i> Duration (minutes)</label>
                                        <input type="number" class="form-control" name="duration_minutes" value="${isEdit ? meeting.duration_minutes : '30'}" min="5" step="5">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-bell"></i> Reminder (minutes before)</label>
                                        <input type="number" class="form-control" name="reminder_minutes" value="${isEdit ? meeting.reminder_minutes : '15'}" min="0" step="5">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-users"></i> Meeting With</label>
                                <input type="text" class="form-control" name="meeting_with" value="${isEdit && meeting.meeting_with ? escapeHtml(meeting.meeting_with) : ''}" placeholder="Participants names">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-map-marker-alt"></i> Location</label>
                                <input type="text" class="form-control" name="location" value="${isEdit && meeting.location ? escapeHtml(meeting.location) : ''}" placeholder="Office, Conference Room, etc.">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-video"></i> Meeting Link</label>
                                <input type="url" class="form-control" name="meeting_link" value="${isEdit && meeting.meeting_link ? escapeHtml(meeting.meeting_link) : ''}" placeholder="Zoom, Teams, or Google Meet link">
                            </div>
                            
                            ${isEdit ? `
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-flag"></i> Status</label>
                                    <select class="form-select" name="status">
                                        <option value="Scheduled" ${meeting.status === 'Scheduled' ? 'selected' : ''}>Scheduled</option>
                                        <option value="Completed" ${meeting.status === 'Completed' ? 'selected' : ''}>Completed</option>
                                        <option value="Cancelled" ${meeting.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                                        <option value="Rescheduled" ${meeting.status === 'Rescheduled' ? 'selected' : ''}>Rescheduled</option>
                                    </select>
                                </div>
                            ` : ''}
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-sticky-note"></i> Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Meeting notes...">${isEdit && meeting.notes ? escapeHtml(meeting.notes) : ''}</textarea>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveMeeting()">
                            <i class="fas fa-save"></i> Save Meeting
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('body').append(modal);
    $('#meetingModal').modal('show');
    $('#meetingModal').on('hidden.bs.modal', function() {
        $(this).remove();
    });
}

function saveMeeting() {
    const formData = new FormData($('#meetingForm')[0]);
    const action = $('#meetingId').val() ? 'update_meeting' : 'add_meeting';
    formData.append('action', action);
    
    if (!formData.get('status')) {
        formData.append('status', 'Scheduled');
    }
    
    $.ajax({
        url: '../../api/admin_vault.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#meetingModal').modal('hide');
                loadMeetings();
                showToast(response.message, 'success');
            } else {
                showToast(response.message, 'danger');
            }
        }
    });
}

function markMeetingComplete(id) {
    const meeting = meetings.find(m => m.id == id);
    if (!meeting) return;
    
    $.post('../../api/admin_vault.php', {
        action: 'update_meeting',
        id: id,
        title: meeting.title,
        meeting_date: meeting.meeting_date,
        meeting_time: meeting.meeting_time,
        status: 'Completed'
    }, function(response) {
        if (response.success) {
            loadMeetings();
            showToast('Meeting marked as complete!', 'success');
        }
    });
}

function deleteMeeting(id) {
    showDeleteConfirm('Delete this meeting?', function () {
        $.post('../../api/admin_vault.php', {
            action: 'delete_meeting',
            id: id
        }, function(response) {
            if (response.success) {
                loadMeetings();
                showToast(response.message, 'success');
            }
        });
    });
}

// ===== UTILITY FUNCTIONS =====
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function copyToClipboard(text, label = 'Text') {
    navigator.clipboard.writeText(text).then(function() {
        showToast(label + ' copied to clipboard!', 'success');
    }, function() {
        showToast('Failed to copy', 'danger');
    });
}

function togglePasswordVisibility(fieldId) {
    const field = $('#' + fieldId);
    const type = field.attr('type') === 'password' ? 'text' : 'password';
    field.attr('type', type);
}

function generatePassword() {
    const length = 16;
    const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?";
    let password = "";
    for (let i = 0; i < length; i++) {
        password += charset.charAt(Math.floor(Math.random() * charset.length));
    }
    $('#credPassword').val(password);
    $('#credPassword').attr('type', 'text');
    showToast('Password generated!', 'success');
}

function adjustColor(color, amount) {
    const clamp = (val) => Math.min(Math.max(val, 0), 255);
    const num = parseInt(color.replace("#", ""), 16);
    const r = clamp((num >> 16) + amount);
    const g = clamp(((num >> 8) & 0x00FF) + amount);
    const b = clamp((num & 0x0000FF) + amount);
    return "#" + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
}
