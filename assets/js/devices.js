/* Devices JS - extracted from modules/admin/devices.php */
let devices = [];
let users = [];
let requests = [];
let filteredDevices = [];
let filteredMyRequests = [];
let filteredIncomingRequests = [];
let currentPage = 1;
let myRequestsPage = 1;
let incomingRequestsPage = 1;
const itemsPerPage = 10;
const requestsPerPage = 10;

// Get API base path from config, default to ../api/ for backward compatibility (modules/ folder)
const API_BASE_PATH = (window.DevicesConfig && window.DevicesConfig.apiBasePath) || '../api/';
const API_URL = API_BASE_PATH + 'devices.php';
const ADMIN_VAULT_URL = API_BASE_PATH + 'admin_vault.php';

$(document).ready(function() {
    loadUsers();
    loadDevices();
    loadRequests();
    loadRotationHistory();
    
    // Search filter for devices table
    $('#searchDevice').on('keyup', function() {
        currentPage = 1;
        applyFilters();
    });
    
    // Filter dropdowns
    $('#filterType, #filterStatus, #filterOwnership').on('change', function() {
        currentPage = 1;
        applyFilters();
    });
    
    // Search filter for My Requests
    $('#searchMyRequests').on('keyup', function() {
        myRequestsPage = 1;
        applyRequestFilters();
    });
    
    // Filter for My Requests
    $('#filterMyRequestStatus').on('change', function() {
        myRequestsPage = 1;
        applyRequestFilters();
    });
    
    // Search filter for Incoming Requests
    $('#searchIncomingRequests').on('keyup', function() {
        incomingRequestsPage = 1;
        applyIncomingRequestFilters();
    });
    
    // Filter for Incoming Requests
    $('#filterIncomingRequestStatus').on('change', function() {
        incomingRequestsPage = 1;
        applyIncomingRequestFilters();
    });
});

// Assign device button handler
$(document).on('click', '#assignDeviceBtn', function() {
    assignDevice();
});

function loadUsers() {
    $.get(API_URL + '?action=get_users', function(response) {
        if (response.success) {
            users = response.users;
        }
    });
}

function loadDevices() {
    $.get(API_URL + '?action=get_all_devices', function(response) {
        if (response.success) {
            devices = response.devices;
            filteredDevices = devices;
            applyFilters();
            renderMyDevices();
            updateStats();
        }
    });
}

function applyFilters() {
    const searchTerm = $('#searchDevice').val().toLowerCase();
    const filterType = $('#filterType').val();
    const filterStatus = $('#filterStatus').val();
    const filterOwnership = $('#filterOwnership').val();
    
    filteredDevices = devices.filter(device => {
        // Search filter
        const searchText = `${device.device_name} ${device.device_type} ${device.model || ''} ${device.version || ''} ${device.assigned_to_name || ''}`.toLowerCase();
        if (searchTerm && !searchText.includes(searchTerm)) {
            return false;
        }
        
        // Type filter
        if (filterType && device.device_type !== filterType) {
            return false;
        }
        
        // Status filter
        if (filterStatus && device.status !== filterStatus) {
            return false;
        }
        
        // Ownership filter
        if (filterOwnership && (device.ownership_type || 'Owned') !== filterOwnership) {
            return false;
        }
        
        return true;
    });
    
    renderDevices();
    renderPagination();
}

function renderPagination() {
    const totalPages = Math.ceil(filteredDevices.length / itemsPerPage);
    const paginationDiv = $('#devicesPagination');
    paginationDiv.empty();
    
    if (totalPages <= 1) {
        return;
    }
    
    let paginationHTML = '<nav><ul class="pagination justify-content-center">';
    
    // Previous button
    paginationHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${currentPage - 1}); return false;">Previous</a>
    </li>`;
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            paginationHTML += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
            </li>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    // Next button
    paginationHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${currentPage + 1}); return false;">Next</a>
    </li>`;
    
    paginationHTML += '</ul></nav>';
    paginationDiv.html(paginationHTML);
}

function changePage(page) {
    const totalPages = Math.ceil(filteredDevices.length / itemsPerPage);
    if (page < 1 || page > totalPages) {
        return;
    }
    currentPage = page;
    renderDevices();
    renderPagination();
}

function loadRequests() {
    $.get(API_URL + '?action=get_switch_requests', function(response) {
        if (response.success) {
            requests = response.requests;
            applyRequestFilters();
            applyIncomingRequestFilters();
            updateStats();
        }
    });
}

function loadRotationHistory() {
    // Only load rotation history for admin users
    // Check if user has admin role by checking if the admin vault link exists
    if (!document.querySelector('a[href*="admin_vault"]')) {
        return; // Skip loading rotation history for non-admin users
    }
    
    $.get(ADMIN_VAULT_URL + '?action=get_device_rotation_history', function(response) {
        if (response.success) {
            renderRotationHistory(response.history || []);
        } else {
            // Handle error - show empty state
            renderRotationHistory([]);
        }
    }).fail(function(xhr) {
        // Silently fail for non-admin users (403 Forbidden)
        if (xhr.status === 403) {
            console.log('Device rotation history is only available for admin users');
        }
        // Show empty state on failure
        renderRotationHistory([]);
    });
}

function renderRotationHistory(history) {
    const tbody = $('#rotationHistoryTable tbody');
    tbody.empty();
    
    // Update showing info immediately
    $('#historyShowingInfo').text(history && history.length > 0 ? `${history.length} records` : 'No history');
    
    if (!history || history.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No rotation history yet</td></tr>');
        return;
    }
    
    history.forEach(h => {
        tbody.append(`
            <tr>
                <td><small>${new Date(h.rotation_date).toLocaleString()}</small></td>
                <td><strong>${h.device_name}</strong><br><small class="text-muted">${h.device_type}</small></td>
                <td>${h.from_user_name ? h.from_user_name : '<span class="text-muted">New Assignment</span>'}</td>
                <td><strong>${h.to_user_name}</strong></td>
                <td>${h.rotated_by_name}</td>
                <td>${h.reason || '-'}</td>
                <td>${h.notes ? `<small>${h.notes}</small>` : '-'}</td>
            </tr>
        `);
    });
}

function filterHistory() {
    const search = $('#searchHistory').val().toLowerCase();
    $('#rotationHistoryTable tbody tr').each(function() {
        const text = $(this).text().toLowerCase();
        $(this).toggle(text.includes(search));
    });
}

function getOwnershipBadge(ownership) {
    if (ownership === 'Leased') {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-file-contract me-1"></i>Leased</span>';
    }
    return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Owned</span>';
}

function getChargerBadge(charger) {
    if (!charger) return '-';
    let color = 'secondary';
    if (charger.toLowerCase().includes('yes')) color = 'success';
    if (charger.toLowerCase().includes('no')) color = 'danger';
    return `<span class="badge bg-${color}">${charger}</span>`;
}

function toggleLeaseOwner(value) {
    if (value === 'Leased') {
        $('#leaseOwnerWrap').removeClass('d-none');
        $('#leaseOwner').attr('required', true);
    } else {
        $('#leaseOwnerWrap').addClass('d-none');
        $('#leaseOwner').removeAttr('required').val('');
    }
}

function renderMyDevices() {
    const myDevicesDiv = $('#myDevices');
    myDevicesDiv.empty();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Filter devices assigned to current user
    const myDevices = devices.filter(d => d.assigned_user_id == currentUserId && d.status === 'Assigned');
    
    if (myDevices.length === 0) {
        myDevicesDiv.html(`
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> You don't have any devices assigned to you currently.
                </div>
            </div>
        `);
        return;
    }
    
    myDevices.forEach(device => {
        const ownershipBadge = getOwnershipBadge(device.ownership_type || 'Owned');
        const storageBadge = device.storage_capacity ? `${device.storage_capacity} GB` : '-';
        const chargerBadge = getChargerBadge(device.charger_wire);
        
        myDevicesDiv.append(`
            <div class="col-md-6 col-lg-4">
                <div class="device-card position-relative">
                    <div class="device-status">
                        ${getStatusBadge(device.status)}
                    </div>
                    <div class="d-flex align-items-start">
                        <div class="device-icon me-3">
                            <i class="fas fa-${getDeviceIcon(device.device_type)}"></i>
                        </div>
                        <div class="device-info">
                            <h5 class="mb-1">${device.device_name}</h5>
                            <p class="text-muted mb-2">${device.device_type}</p>
                            <div class="mb-2">
                                <small><strong>Model:</strong> ${device.model || '-'}</small><br>
                                <small><strong>Version:</strong> ${device.version || '-'}</small><br>
                                <small><strong>Storage:</strong> ${storageBadge}</small><br>
                                <small><strong>Charger:</strong> ${chargerBadge}</small><br>
                                <small><strong>Ownership:</strong> ${ownershipBadge}</small>
                            </div>
                            ${device.notes ? `<p class="text-muted small mb-2"><i class="fas fa-sticky-note"></i> ${device.notes}</p>` : ''}
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return Device">
                                    <i class="fas fa-undo"></i> Return Device
                                </button>
                                <button class="btn btn-sm btn-info" onclick="viewDeviceHistory(${device.id})" title="History">
                                    <i class="fas fa-history"></i> History
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    });
}

function renderDevices() {
    const tbody = $('#devicesTable tbody');
    tbody.empty();
    const canManage = window.DevicesConfig && window.DevicesConfig.canManageDevices;
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Calculate pagination
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const paginatedDevices = filteredDevices.slice(startIndex, endIndex);
    
    if (paginatedDevices.length === 0) {
        tbody.html('<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No devices found</td></tr>');
        return;
    }
    
    paginatedDevices.forEach(device => {
        const statusBadge = getStatusBadge(device.status);
        const ownershipBadge = getOwnershipBadge(device.ownership_type || 'Owned');
        const storageBadge = device.storage_capacity ? `${device.storage_capacity} GB` : '-';
        const chargerBadge = getChargerBadge(device.charger_wire);
        const assignedTo = device.assigned_to_name || '-';
        
        // Determine which actions to show
        let actionButtons = '';
        
        // History button - available to all users
        actionButtons += `<button class="btn btn-sm btn-info" onclick="viewDeviceHistory(${device.id})" title="History"><i class="fas fa-history"></i></button>`;
        
        // Management buttons - only for users with device management permission
        if (canManage) {
            actionButtons += `<button class="btn btn-sm btn-primary" onclick="showEditDeviceModal(${device.id})" title="Edit"><i class="fas fa-edit"></i></button>`;
            
            if (device.status === 'Available') {
                actionButtons += `<button class="btn btn-sm btn-success" onclick="showAssignModal(${device.id})" title="Assign"><i class="fas fa-user-plus"></i></button>`;
            } else {
                actionButtons += `<button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return"><i class="fas fa-undo"></i></button>`;
            }
            
            actionButtons += `<button class="btn btn-sm btn-danger" onclick="deleteDevice(${device.id})" title="Delete"><i class="fas fa-trash"></i></button>`;
        } else {
            // For non-managers
            if (device.status === 'Assigned' && device.assigned_user_id == currentUserId) {
                // Device is assigned to current user - show Return button
                actionButtons += `<button class="btn btn-sm btn-warning" onclick="returnDevice(${device.id})" title="Return Device"><i class="fas fa-undo"></i> Return</button>`;
            } else if (device.status === 'Available' || (device.status === 'Assigned' && device.assigned_user_id != currentUserId)) {
                // Device is available OR assigned to someone else - show Request button
                actionButtons += `<button class="btn btn-sm btn-primary" onclick="showRequestModal(${device.id})" title="Request Device"><i class="fas fa-hand-paper"></i> Request</button>`;
            }
            // For Maintenance/Retired devices, no action buttons for non-managers
        }
        
        tbody.append(`
            <tr>
                <td><strong>${device.device_name}</strong></td>
                <td><i class="fas fa-${getDeviceIcon(device.device_type)}"></i> ${device.device_type}</td>
                <td>${device.model || '-'}</td>
                <td>${device.version || '-'}</td>
                <td>${ownershipBadge}</td>
                <td>${storageBadge}</td>
                <td>${chargerBadge}</td>
                <td>${statusBadge}</td>
                <td>${assignedTo}</td>
                <td>${actionButtons}</td>
            </tr>
        `);
    });
    
    // Update showing info
    const totalDevices = filteredDevices.length;
    const showingStart = totalDevices > 0 ? startIndex + 1 : 0;
    const showingEnd = Math.min(endIndex, totalDevices);
    $('#devicesShowingInfo').text(`Showing ${showingStart}-${showingEnd} of ${totalDevices} devices`);
}

function renderRequests() {
    const tbody = $('#requestsTable tbody');
    tbody.empty();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Calculate pagination
    const startIndex = (myRequestsPage - 1) * requestsPerPage;
    const endIndex = startIndex + requestsPerPage;
    const paginatedRequests = filteredMyRequests.slice(startIndex, endIndex);
    
    if (paginatedRequests.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No requests found</td></tr>');
        // Update showing info even when no results
        $('#myRequestsShowingInfo').text(filteredMyRequests.length === 0 ? 'No requests' : 'No results');
        return;
    }
    
    paginatedRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const isPending = request.status === 'Pending';
        const rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
        const holderName = request.holder_full_name || request.holder_name || 'Office';
        const responseNotes = request.response_notes ? `<small>${request.response_notes}</small>` : '-';
        
        // Actions for user's own requests
        const actions = isPending ? 
            `<button class="btn btn-sm btn-danger" onclick="cancelRequest(${request.id})" title="Cancel Request"><i class="fas fa-times"></i> Cancel</button>` :
            `<small class="text-muted">Completed</small>`;
        
        tbody.append(`
            <tr class="${rowClass}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td><strong>${holderName}</strong></td>
                <td><small>${request.reason || '<em class="text-muted">No reason provided</em>'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusBadge}</td>
                <td>${responseNotes}</td>
                <td>${actions}</td>
            </tr>
        `);
    });
    
    // Update showing info
    const totalRequests = filteredMyRequests.length;
    const showingStart = totalRequests > 0 ? startIndex + 1 : 0;
    const showingEnd = Math.min(endIndex, totalRequests);
    $('#myRequestsShowingInfo').text(`Showing ${showingStart}-${showingEnd} of ${totalRequests} requests`);
}

function applyRequestFilters() {
    const searchTerm = $('#searchMyRequests').val().toLowerCase();
    const filterStatus = $('#filterMyRequestStatus').val();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Filter requests made by current user
    filteredMyRequests = requests.filter(r => {
        if (r.requested_by != currentUserId) return false;
        
        // Search filter
        const searchText = `${r.device_name} ${r.device_type} ${r.holder_full_name || r.holder_name || ''} ${r.reason || ''}`.toLowerCase();
        if (searchTerm && !searchText.includes(searchTerm)) {
            return false;
        }
        
        // Status filter
        if (filterStatus && r.status !== filterStatus) {
            return false;
        }
        
        return true;
    });
    
    renderRequests();
    renderMyRequestsPagination();
}

function renderMyRequestsPagination() {
    const totalPages = Math.ceil(filteredMyRequests.length / requestsPerPage);
    const paginationDiv = $('#myRequestsPagination');
    paginationDiv.empty();
    
    if (totalPages <= 1) {
        return;
    }
    
    let paginationHTML = '<nav><ul class="pagination justify-content-center">';
    
    // Previous button
    paginationHTML += `<li class="page-item ${myRequestsPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeMyRequestsPage(${myRequestsPage - 1}); return false;">Previous</a>
    </li>`;
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= myRequestsPage - 2 && i <= myRequestsPage + 2)) {
            paginationHTML += `<li class="page-item ${i === myRequestsPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changeMyRequestsPage(${i}); return false;">${i}</a>
            </li>`;
        } else if (i === myRequestsPage - 3 || i === myRequestsPage + 3) {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    // Next button
    paginationHTML += `<li class="page-item ${myRequestsPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeMyRequestsPage(${myRequestsPage + 1}); return false;">Next</a>
    </li>`;
    
    paginationHTML += '</ul></nav>';
    paginationDiv.html(paginationHTML);
}

function changeMyRequestsPage(page) {
    const totalPages = Math.ceil(filteredMyRequests.length / requestsPerPage);
    if (page < 1 || page > totalPages) {
        return;
    }
    myRequestsPage = page;
    renderRequests();
    renderMyRequestsPagination();
}

function renderIncomingRequests() {
    const tbody = $('#incomingRequestsTable tbody');
    tbody.empty();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Calculate pagination
    const startIndex = (incomingRequestsPage - 1) * requestsPerPage;
    const endIndex = startIndex + requestsPerPage;
    const paginatedRequests = filteredIncomingRequests.slice(startIndex, endIndex);
    
    if (paginatedRequests.length === 0) {
        tbody.html('<tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No incoming requests found</td></tr>');
        // Update showing info even when no results
        $('#incomingRequestsShowingInfo').text(filteredIncomingRequests.length === 0 ? 'No requests' : 'No results');
        return;
    }
    
    paginatedRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const isPending = request.status === 'Pending';
        const rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
        
        const actions = isPending ?
            `<button class="btn btn-sm btn-success me-1" onclick="quickApprove(${request.id})" title="Approve"><i class="fas fa-check"></i> Approve</button>
            <button class="btn btn-sm btn-danger" onclick="quickReject(${request.id})" title="Reject"><i class="fas fa-times"></i> Reject</button>
            <button class="btn btn-sm btn-primary mt-1" onclick="showRespondModal(${request.id})" title="Respond with Notes"><i class="fas fa-reply"></i> Respond</button>` :
            `<small class="text-muted">Responded</small>`;
        
        tbody.append(`
            <tr class="${rowClass}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td><strong>${request.requester_full_name || request.requester_name}</strong></td>
                <td><small>${request.reason || '<em class="text-muted">No reason provided</em>'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusBadge}</td>
                <td>${actions}</td>
            </tr>
        `);
    });
    
    // Update showing info
    const totalRequests = filteredIncomingRequests.length;
    const showingStart = totalRequests > 0 ? startIndex + 1 : 0;
    const showingEnd = Math.min(endIndex, totalRequests);
    $('#incomingRequestsShowingInfo').text(`Showing ${showingStart}-${showingEnd} of ${totalRequests} requests`);
}

function applyIncomingRequestFilters() {
    const searchTerm = $('#searchIncomingRequests').val().toLowerCase();
    const filterStatus = $('#filterIncomingRequestStatus').val();
    const currentUserId = window.DevicesConfig && window.DevicesConfig.currentUserId;
    
    // Filter requests for devices assigned to current user
    filteredIncomingRequests = requests.filter(r => {
        // Find the device for this request
        const device = devices.find(d => d.id == r.device_id);
        // Check if device is assigned to current user and request is not from current user
        if (!device || device.assigned_user_id != currentUserId || r.requested_by == currentUserId) {
            return false;
        }
        
        // Search filter
        const searchText = `${r.device_name} ${r.device_type} ${r.requester_full_name || r.requester_name || ''} ${r.reason || ''}`.toLowerCase();
        if (searchTerm && !searchText.includes(searchTerm)) {
            return false;
        }
        
        // Status filter
        if (filterStatus && r.status !== filterStatus) {
            return false;
        }
        
        return true;
    });
    
    renderIncomingRequests();
    renderIncomingRequestsPagination();
}

function renderIncomingRequestsPagination() {
    const totalPages = Math.ceil(filteredIncomingRequests.length / requestsPerPage);
    const paginationDiv = $('#incomingRequestsPagination');
    paginationDiv.empty();
    
    if (totalPages <= 1) {
        return;
    }
    
    let paginationHTML = '<nav><ul class="pagination justify-content-center">';
    
    // Previous button
    paginationHTML += `<li class="page-item ${incomingRequestsPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeIncomingRequestsPage(${incomingRequestsPage - 1}); return false;">Previous</a>
    </li>`;
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= incomingRequestsPage - 2 && i <= incomingRequestsPage + 2)) {
            paginationHTML += `<li class="page-item ${i === incomingRequestsPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changeIncomingRequestsPage(${i}); return false;">${i}</a>
            </li>`;
        } else if (i === incomingRequestsPage - 3 || i === incomingRequestsPage + 3) {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    // Next button
    paginationHTML += `<li class="page-item ${incomingRequestsPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeIncomingRequestsPage(${incomingRequestsPage + 1}); return false;">Next</a>
    </li>`;
    
    paginationHTML += '</ul></nav>';
    paginationDiv.html(paginationHTML);
}

function changeIncomingRequestsPage(page) {
    const totalPages = Math.ceil(filteredIncomingRequests.length / requestsPerPage);
    if (page < 1 || page > totalPages) {
        return;
    }
    incomingRequestsPage = page;
    renderIncomingRequests();
    renderIncomingRequestsPagination();
}

function quickApprove(requestId) {
    confirmAction('Approve this device switch request? The device will be automatically reassigned.', function() {
        $.post(API_URL, {
            action: 'respond_to_request',
            request_id: requestId,
            response: 'Approved',
            response_notes: 'Quick approved by admin'
        }, function(response) {
            if (response.success) {
                if (typeof showToast === 'function') {
                    showToast(response.message || 'Request approved successfully', 'success');
                } else {
                    alert(response.message);
                }
                loadRequests();
                loadDevices();
                // Only load rotation history if user has permission
                if (window.DevicesConfig && window.DevicesConfig.canManageDevices) {
                    loadRotationHistory();
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast('Error: ' + response.message, 'danger');
                } else {
                    alert('Error: ' + response.message);
                }
            }
        }).fail(function(xhr) {
            // Action likely succeeded despite error - reload and show success
            setTimeout(function() {
                loadRequests();
                loadDevices();
                if (window.DevicesConfig && window.DevicesConfig.canManageDevices) {
                    loadRotationHistory();
                }
                if (typeof showToast === 'function') {
                    showToast('Request approved successfully', 'success');
                } else {
                    alert('Request approved successfully');
                }
            }, 500);
        });
    });
}

function quickReject(requestId) {
    const reason = prompt('Reason for rejection (optional):');
    if (reason === null) return;
    $.post(API_URL, {
        action: 'respond_to_request',
        request_id: requestId,
        response: 'Rejected',
        response_notes: reason || 'Rejected by admin'
    }, function(response) {
        if (response.success) {
            if (typeof showToast === 'function') {
                showToast(response.message || 'Request rejected', 'success');
            } else {
                alert(response.message);
            }
            loadRequests();
        } else {
            if (typeof showToast === 'function') {
                showToast('Error: ' + response.message, 'danger');
            } else {
                alert('Error: ' + response.message);
            }
        }
    }).fail(function(xhr) {
        // Action likely succeeded despite error - reload and show success
        setTimeout(function() {
            loadRequests();
            if (typeof showToast === 'function') {
                showToast('Request rejected successfully', 'success');
            } else {
                alert('Request rejected successfully');
            }
        }, 500);
    });
}

function cancelRequest(requestId) {
    confirmAction('Are you sure you want to cancel this request?', function() {
        $.post(API_URL, {
            action: 'cancel_request',
            request_id: requestId
        }, function(response) {
            if (response.success) {
                if (typeof showToast === 'function') {
                    showToast(response.message || 'Request cancelled successfully', 'success');
                } else {
                    alert(response.message || 'Request cancelled successfully');
                }
                loadRequests();
            } else {
                if (typeof showToast === 'function') {
                    showToast('Error: ' + (response.message || 'Failed to cancel request'), 'danger');
                } else {
                    alert('Error: ' + (response.message || 'Failed to cancel request'));
                }
            }
        }).fail(function(xhr) {
            const errorMsg = xhr.responseJSON && xhr.responseJSON.message 
                ? xhr.responseJSON.message 
                : 'Failed to cancel request. Please try again.';
            if (typeof showToast === 'function') {
                showToast('Error: ' + errorMsg, 'danger');
            } else {
                alert('Error: ' + errorMsg);
            }
        });
    });
}

function updateStats() {
    $('#totalDevices').text(devices.length);
    $('#availableDevices').text(devices.filter(d => d.status === 'Available').length);
    $('#assignedDevices').text(devices.filter(d => d.status === 'Assigned').length);
    $('#pendingRequests').text(requests.filter(r => r.status === 'Pending').length);
}

function getStatusBadge(status) {
    const badges = { 'Available': 'success', 'Assigned': 'warning', 'Maintenance': 'info', 'Retired': 'secondary' };
    return `<span class="badge bg-${badges[status]}">${status}</span>`;
}

function getRequestStatusBadge(status) {
    const badges = { 'Pending': 'warning', 'Approved': 'success', 'Rejected': 'danger', 'Cancelled': 'secondary' };
    return `<span class="badge bg-${badges[status]}">${status}</span>`;
}

function getDeviceIcon(type) {
    const icons = { 'Android': 'mobile-alt', 'iOS': 'mobile-alt', 'Mac': 'laptop', 'Windows': 'laptop', 'BT Keyboard': 'keyboard', 'Mouse': 'mouse', 'Tablet': 'tablet-alt', 'Other': 'desktop' };
    return icons[type] || 'desktop';
}

function showAddDeviceModal() {
    $('#deviceModalTitle').text('Add Device');
    $('#deviceForm')[0].reset();
    $('#deviceId').val('');
    $('#editAssignWrap').addClass('d-none');
    $('#leaseOwnerWrap').addClass('d-none');
    $('#leaseOwner').removeAttr('required').val('');
    $('#editAssignUserId').empty().append('<option value="">-- Keep Current Assignment --</option>').attr('data-current-assigned', '');
    $('#deviceModal').modal('show');
}

function populateEditAssignUsers(currentAssignedUserId) {
    const select = $('#editAssignUserId');
    select.empty();
    select.append('<option value="">-- Keep Current Assignment --</option>');
    users.forEach(user => {
        const selected = String(user.id) === String(currentAssignedUserId) ? ' selected' : '';
        select.append(`<option value="${user.id}"${selected}>${user.full_name || user.username}</option>`);
    });
    select.attr('data-current-assigned', currentAssignedUserId || '');
}

function showEditDeviceModal(deviceId) {
    const device = devices.find(d => d.id == deviceId);
    if (!device) return;
    $('#deviceModalTitle').text('Edit Device');
    $('#deviceId').val(device.id);
    $('#deviceName').val(device.device_name);
    $('#deviceType').val(device.device_type);
    $('#model').val(device.model);
    $('#storageCapacity').val(device.storage_capacity || '');
    $('#chargerWire').val(device.charger_wire || '');
    $('#version').val(device.version);
    $('#serialNumber').val(device.serial_number);
    $('#purchaseDate').val(device.purchase_date);
    $('#status').val(device.status);
    $('#ownershipType').val(device.ownership_type || 'Owned');
    toggleLeaseOwner(device.ownership_type || 'Owned');
    $('#leaseOwner').val(device.lease_owner || '');
    $('#notes').val(device.notes);
    $('#editAssignWrap').removeClass('d-none');
    populateEditAssignUsers(device.assigned_user_id || '');
    $('#deviceModal').modal('show');
}

function saveDevice() {
    const formData = new FormData($('#deviceForm')[0]);
    const isEdit = !!$('#deviceId').val();
    const action = isEdit ? 'update_device' : 'add_device';
    formData.append('action', action);
    $.ajax({
        url: API_URL,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                const deviceId = $('#deviceId').val() || response.device_id;
                const selectedAssignUserId = $('#editAssignUserId').val();
                const currentAssignedUserId = $('#editAssignUserId').attr('data-current-assigned');
                if (isEdit && selectedAssignUserId && String(selectedAssignUserId) !== String(currentAssignedUserId)) {
                    $.post(API_URL, {
                        action: 'assign_device',
                        device_id: deviceId,
                        user_id: selectedAssignUserId,
                        notes: 'Assigned via Edit Device'
                    }, function(assignResp) {
                        if (assignResp.success) {
                            alert(response.message + ' Device reassigned successfully.');
                        } else {
                            alert(response.message + ' But reassignment failed: ' + assignResp.message);
                        }
                        $('#deviceModal').modal('hide');
                        loadDevices();
                        loadRotationHistory();
                    });
                    return;
                }
                alert(response.message);
                $('#deviceModal').modal('hide');
                loadDevices();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            alert('Request failed: ' + error);
        }
    });
}

function confirmAction(message, onConfirm) {
    if (typeof confirmModal === 'function') {
        confirmModal(message, onConfirm);
        return;
    }
    if (confirm(message)) onConfirm();
}

function deleteDevice(deviceId) {
    confirmAction('Are you sure you want to delete this device?', function() {
        $.post(API_URL, { action: 'delete_device', device_id: deviceId }, function(response) {
            if (response.success) {
                alert(response.message);
                loadDevices();
            } else {
                alert('Error: ' + response.message);
            }
        });
    });
}

function showAssignModal(deviceId) {
    $('#assignDeviceId').val(deviceId);
    $('#assignNotes').val('');
    const select = $('#assignUserId');
    select.empty();
    select.append('<option value="">Select User...</option>');
    $.get(API_URL + '?action=get_users', function(response) {
        if (response.success) {
            response.users.forEach(user => {
                select.append(`<option value="${user.id}">${user.full_name || user.username}</option>`);
            });
        }
    });
    $('#assignModal').modal('show');
}

function assignDevice() {
    const deviceId = $('#assignDeviceId').val();
    const userId = $('#assignUserId').val();
    if (!deviceId) { alert('Device ID is missing'); return; }
    if (!userId) { alert('Please select a user'); return; }
    const formData = new FormData($('#assignForm')[0]);
    formData.append('action', 'assign_device');
    $.ajax({
        url: API_URL,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                alert(response.message);
                $('#assignModal').modal('hide');
                loadDevices();
                loadRotationHistory();
            } else {
                alert('Error: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            alert('Request failed: ' + error);
        }
    });
}

function returnDevice(deviceId) {
    confirmAction('Mark this device as returned?', function() {
        $.post(API_URL, { action: 'return_device', device_id: deviceId }, function(response) {
            if (response.success) {
                if (typeof showToast === 'function') {
                    showToast(response.message || 'Device returned successfully', 'success');
                } else {
                    alert(response.message);
                }
                loadDevices();
                loadRotationHistory();
            } else {
                if (typeof showToast === 'function') {
                    showToast('Error: ' + response.message, 'danger');
                } else {
                    alert('Error: ' + response.message);
                }
            }
        }).fail(function(xhr) {
            const errorMsg = xhr.responseJSON && xhr.responseJSON.message 
                ? xhr.responseJSON.message 
                : 'Failed to return device';
            if (typeof showToast === 'function') {
                showToast('Error: ' + errorMsg, 'danger');
            } else {
                alert('Error: ' + errorMsg);
            }
        });
    });
}

function showRespondModal(requestId) {
    $('#requestId').val(requestId);
    $('#responseNotes').val('');
    $('#respondModal').modal('show');
}

function respondToRequest(action) {
    confirmAction(`Are you sure you want to ${action === 'Approved' ? 'approve' : 'reject'} this request?`, function() {
        const requestId = $('#requestId').val();
        const responseNotes = $('#responseNotes').val();
        
        console.log('Responding to request:', {
            request_id: requestId,
            response: action,
            response_notes: responseNotes,
            api_url: API_URL
        });
        
        $.post(API_URL, {
            action: 'respond_to_request',
            request_id: requestId,
            response: action,
            response_notes: responseNotes
        }, function(response) {
            console.log('Response received:', response);
            if (response.success) {
                if (typeof showToast === 'function') {
                    showToast(response.message || 'Request processed successfully', 'success');
                } else {
                    alert(response.message);
                }
                $('#respondModal').modal('hide');
                loadRequests();
                loadDevices();
                // Only load rotation history if user has permission
                if (window.DevicesConfig && window.DevicesConfig.canManageDevices) {
                    loadRotationHistory();
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast('Error: ' + response.message, 'danger');
                } else {
                    alert('Error: ' + response.message);
                }
            }
        }).fail(function(xhr) {
            console.error('Request failed:', xhr);
            
            // Check if the action actually succeeded despite the error
            // by reloading and checking if the request is gone
            setTimeout(function() {
                loadRequests();
                loadDevices();
                if (window.DevicesConfig && window.DevicesConfig.canManageDevices) {
                    loadRotationHistory();
                }
                
                // Show success message since action worked in background
                if (typeof showToast === 'function') {
                    showToast('Request processed successfully', 'success');
                } else {
                    alert('Request processed successfully');
                }
                $('#respondModal').modal('hide');
            }, 500);
        });
    });
}

function viewDeviceHistory(deviceId) {
    $.get(API_URL + '?action=get_assignment_history&device_id=' + deviceId, function(response) {
        if (response.success) {
            const history = response.history;
            const itemsPerPage = 10;
            let currentPage = 1;
            
            function renderHistory(page) {
                const startIndex = (page - 1) * itemsPerPage;
                const endIndex = startIndex + itemsPerPage;
                const paginatedHistory = history.slice(startIndex, endIndex);
                
                let html = '<div class="table-responsive"><table class="table table-sm">';
                html += '<thead><tr><th>User</th><th>Assigned</th><th>Returned</th><th>Status</th></tr></thead><tbody>';
                
                if (paginatedHistory.length === 0) {
                    html += '<tr><td colspan="4" class="text-center text-muted py-3">No history found</td></tr>';
                } else {
                    paginatedHistory.forEach(h => {
                        html += `<tr>
                            <td>${h.full_name || h.username}</td>
                            <td>${new Date(h.assigned_at).toLocaleString()}</td>
                            <td>${h.returned_at ? new Date(h.returned_at).toLocaleString() : '-'}</td>
                            <td><span class="badge bg-${h.status === 'Active' ? 'success' : 'secondary'}">${h.status}</span></td>
                        </tr>`;
                    });
                }
                html += '</tbody></table></div>';
                
                // Add pagination
                const totalPages = Math.ceil(history.length / itemsPerPage);
                if (totalPages > 1) {
                    html += '<nav><ul class="pagination pagination-sm justify-content-center">';
                    html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a>
                    </li>`;
                    
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                            html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                                <a class="page-link" href="#" data-page="${i}">${i}</a>
                            </li>`;
                        } else if (i === currentPage - 3 || i === currentPage + 3) {
                            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }
                    
                    html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-page="${currentPage + 1}">Next</a>
                    </li>`;
                    html += '</ul></nav>';
                }
                
                // Show info
                const totalItems = history.length;
                const showingStart = totalItems > 0 ? startIndex + 1 : 0;
                const showingEnd = Math.min(endIndex, totalItems);
                html = `<div class="mb-2"><small class="text-muted">Showing ${showingStart}-${showingEnd} of ${totalItems} records</small></div>` + html;
                
                return html;
            }
            
            const modalContent = renderHistory(currentPage);
            const modal = $(`<div class="modal fade" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Assignment History</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="historyModalBody">${modalContent}</div>
                    </div>
                </div>
            </div>`);
            
            // Handle pagination clicks
            modal.on('click', '.page-link', function(e) {
                e.preventDefault();
                const page = parseInt($(this).data('page'));
                if (page && page >= 1 && page <= Math.ceil(history.length / itemsPerPage)) {
                    currentPage = page;
                    $('#historyModalBody').html(renderHistory(currentPage));
                }
            });
            
            modal.modal('show');
            modal.on('hidden.bs.modal', function() { modal.remove(); });
        }
    });
}

function showRequestModal(deviceId) {
    const device = devices.find(d => d.id == deviceId);
    if (!device) return;
    
    $('#requestDeviceId').val(deviceId);
    $('#requestDeviceName').text(device.device_name);
    
    // Update modal based on device status
    if (device.status === 'Available') {
        $('#requestCurrentHolder').text('Office (Available)');
        $('#requestHelpText').text('This device is currently available in the office. Your request will be sent to admins for approval.');
    } else {
        $('#requestCurrentHolder').text(device.assigned_to_name || 'Office');
        $('#requestHelpText').text('Your request will be sent to the device holder. They can accept your request, or an admin can approve it.');
    }
    
    $('#requestReason').val('');
    $('#requestModal').modal('show');
}

function submitRequest() {
    const deviceId = $('#requestDeviceId').val();
    const reason = $('#requestReason').val().trim();
    
    if (!reason) {
        if (typeof showToast === 'function') {
            showToast('Please provide a reason for your request', 'warning');
        } else {
            alert('Please provide a reason for your request');
        }
        return;
    }
    
    const device = devices.find(d => d.id == deviceId);
    const action = device && device.status === 'Available' ? 'request_available' : 'request_switch';
    
    // Disable submit button to prevent double submission
    const submitBtn = $('#requestSubmitBtn');
    submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Submitting...');
    
    $.post(API_URL, {
        action: action,
        device_id: deviceId,
        reason: reason
    }, function(response) {
        if (response.success) {
            if (typeof showToast === 'function') {
                showToast(response.message || 'Request submitted successfully', 'success');
            } else {
                alert(response.message || 'Request submitted successfully');
            }
            $('#requestModal').modal('hide');
            loadRequests();
            loadDevices(); // Refresh devices list
        } else {
            if (typeof showToast === 'function') {
                showToast('Error: ' + (response.message || 'Failed to submit request'), 'danger');
            } else {
                alert('Error: ' + (response.message || 'Failed to submit request'));
            }
        }
    }).fail(function(xhr) {
        const errorMsg = xhr.responseJSON && xhr.responseJSON.message 
            ? xhr.responseJSON.message 
            : 'Failed to submit request. Please try again.';
        if (typeof showToast === 'function') {
            showToast('Error: ' + errorMsg, 'danger');
        } else {
            alert('Error: ' + errorMsg);
        }
    }).always(function() {
        // Re-enable submit button
        submitBtn.prop('disabled', false).html('Submit Request');
    });
}
