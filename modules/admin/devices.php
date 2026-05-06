<?php
require_once __DIR__ . '/../../includes/auth.php';
requireDeviceManager();

$page_title = 'Device Management';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-laptop"></i> Device Management</h2>
        </div>
        <div class="col-auto">
            <?php if (in_array($_SESSION['role'] ?? '', ['admin'], true)): ?>
            <a href="../admin/device_permissions.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-user-shield"></i> Device Permissions
            </a>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="showAddDeviceModal()">
                <i class="fas fa-plus"></i> Add Device
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Devices</h5>
                    <h2 id="totalDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Available</h5>
                    <h2 id="availableDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Assigned</h5>
                    <h2 id="assignedDevices">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Pending Requests</h5>
                    <h2 id="pendingRequests">0</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#devicesTab">Devices</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#requestsTab">Switch Requests</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#historyTab">Rotation History</a>
        </li>
    </ul>

    <div class="tab-content mt-3">
        <!-- Devices Tab -->
        <div id="devicesTab" class="tab-pane fade show active">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">All Devices</h5>
                    <small class="text-muted" id="devicesShowingInfo">Loading...</small>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <input type="text" class="form-control" id="searchDevice" placeholder="Search devices...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="filterType">
                                <option value="">All Types</option>
                                <option value="Android">Android</option>
                                <option value="iOS">iOS</option>
                                <option value="Mac">Mac</option>
                                <option value="Windows">Windows</option>
                                <option value="BT Keyboard">BT Keyboard</option>
                                <option value="Mouse">Mouse</option>
                                <option value="Tablet">Tablet</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="filterStatus">
                                <option value="">All Status</option>
                                <option value="Available">Available</option>
                                <option value="Assigned">Assigned</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Retired">Retired</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="filterOwnership">
                                <option value="">All Ownership</option>
                                <option value="Owned">Owned</option>
                                <option value="Leased">Leased</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover" id="devicesTable">
                            <thead>
                                <tr>
                                    <th>Device Name</th>
                                    <th>Type</th>
                                    <th>Model</th>
                                    <th>Version</th>
                                    <th>Ownership</th>
                                    <th>Storage</th>
                                    <th>Charger/Wire</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="devicesPagination" class="mt-3"></div>
                </div>
            </div>
        </div>

        <!-- Requests Tab -->
        <div id="requestsTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Switch Requests</h5>
                    <small class="text-muted" id="adminRequestsShowingInfo">Loading...</small>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchAdminRequests" placeholder="Search requests...">
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="filterAdminRequestStatus">
                                <option value="">All Status</option>
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover" id="requestsTable">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Requested By</th>
                                    <th>Current Holder</th>
                                    <th>Reason</th>
                                    <th>Requested At</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="adminRequestsPagination" class="mt-3"></div>
                </div>
            </div>
        </div>

        <!-- Rotation History Tab -->
        <div id="historyTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Device Rotation History</h5>
                    <small class="text-muted" id="historyShowingInfo">Loading...</small>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="searchHistory" placeholder="Search history..." onkeyup="filterHistory()">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" id="rotationHistoryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Device</th>
                                    <th>From User</th>
                                    <th>To User</th>
                                    <th>Rotated By</th>
                                    <th>Reason</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="historyPagination" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Device Modal -->
<div class="modal fade" id="deviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deviceModalTitle">Add Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="deviceForm">
                    <input type="hidden" id="deviceId" name="device_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Device Name *</label>
                        <input type="text" class="form-control" id="deviceName" name="device_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Device Type *</label>
                        <select class="form-select" id="deviceType" name="device_type" required>
                            <option value="Android">Android</option>
                            <option value="iOS">iOS</option>
                            <option value="Mac">Mac</option>
                            <option value="Windows">Windows</option>
                            <option value="BT Keyboard">BT Keyboard</option>
                            <option value="Mouse">Mouse</option>
                            <option value="Tablet">Tablet</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" id="model" name="model">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Storage Capacity (GB)</label>
                            <input type="number" class="form-control" id="storageCapacity" name="storage_capacity" min="0" placeholder="e.g. 128">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Charger / Wire Details</label>
                            <input type="text" class="form-control" id="chargerWire" name="charger_wire" placeholder="e.g. Yes, 65W, or Original">
                        </div>
                    </div>

                    
                    <div class="mb-3">
                        <label class="form-label">Version</label>
                        <input type="text" class="form-control" id="version" name="version">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" id="serialNumber" name="serial_number">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" id="purchaseDate" name="purchase_date">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="Available">Available</option>
                            <option value="Assigned">Assigned</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ownership Type *</label>
                        <select class="form-select" id="ownershipType" name="ownership_type" required onchange="toggleLeaseOwner(this.value)">
                            <option value="Owned">Owned</option>
                            <option value="Leased">Leased</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="leaseOwnerWrap">
                        <label class="form-label">Lease Owner / Vendor Name *</label>
                        <input type="text" class="form-control" id="leaseOwner" name="lease_owner" placeholder="e.g. ABC Rentals Pvt Ltd">
                    </div>

                    <div class="mb-3 d-none" id="editAssignWrap">
                        <label class="form-label">Assign To</label>
                        <select class="form-select" id="editAssignUserId" name="assigned_user_id">
                            <option value="">-- Keep Current Assignment --</option>
                        </select>
                        <small class="text-muted">In edit mode, you can select a user here to reassign the device.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveDevice()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Device Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="assignForm">
                    <input type="hidden" id="assignDeviceId" name="device_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Assign To *</label>
                        <select class="form-select" id="assignUserId" name="user_id" required></select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="assignNotes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="assignDeviceBtn">Assign</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window.DevicesConfig = { 
    currentUserId: <?php echo (int)$_SESSION['user_id']; ?>,
    canManageDevices: true, // Admin page always has management permissions
    userRole: <?php echo json_encode($_SESSION['role'] ?? ''); ?>,
    isAdminPage: true,
    apiBasePath: '../../api/' // Admin page: modules/admin/ -> go up 2 levels to root, then api/
};
</script>
<script src="<?php echo htmlspecialchars(getBaseDir(), ENT_QUOTES, 'UTF-8'); ?>/assets/js/devices.js"></script>

<script nonce="<?php echo $cspNonce ?? ''; ?>">// Admin page specific functionality
let adminRequestsPage = 1;
let filteredAdminRequests = [];
let rotationHistory = [];
let filteredRotationHistory = [];
let historyPage = 1;
const adminRequestsPerPage = 10;
const historyPerPage = 10;

// Override renderRequests for admin page to show all requests
const originalRenderRequests = window.renderRequests;
window.renderRequests = function() {
    const tbody = $('#requestsTable tbody');
    tbody.empty();
    
    // Calculate pagination
    const startIndex = (adminRequestsPage - 1) * adminRequestsPerPage;
    const endIndex = startIndex + adminRequestsPerPage;
    const paginatedRequests = filteredAdminRequests.slice(startIndex, endIndex);
    
    if (paginatedRequests.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No requests found</td></tr>');
        $('#adminRequestsShowingInfo').text(filteredAdminRequests.length === 0 ? 'No requests' : 'No results');
        return;
    }
    
    paginatedRequests.forEach(request => {
        const statusBadge = getRequestStatusBadge(request.status);
        const isPending = request.status === 'Pending';
        const rowClass = request.status === 'Approved' ? 'table-success' : (request.status === 'Rejected' ? 'table-danger' : '');
        const holderName = request.holder_full_name || request.holder_name || 'Office';
        
        const actions = isPending ?
            `<button class="btn btn-sm btn-success me-1" onclick="quickApprove(${request.id})" title="Quick Approve"><i class="fas fa-check"></i> Approve</button>
            <button class="btn btn-sm btn-danger" onclick="quickReject(${request.id})" title="Quick Reject"><i class="fas fa-times"></i> Reject</button>
            <button class="btn btn-sm btn-primary mt-1" onclick="showRespondModal(${request.id})" title="Respond with Notes"><i class="fas fa-reply"></i> Respond</button>` :
            `<small class="text-muted">Responded</small>`;
        
        tbody.append(`
            <tr class="${rowClass}">
                <td><strong>${request.device_name}</strong><br><small class="text-muted">${request.device_type}</small></td>
                <td><strong>${request.requester_full_name || request.requester_name}</strong></td>
                <td><strong>${holderName}</strong></td>
                <td><small>${request.reason || '<em class="text-muted">No reason provided</em>'}</small></td>
                <td><small>${new Date(request.requested_at).toLocaleString()}</small></td>
                <td>${statusBadge}</td>
                <td>${actions}</td>
            </tr>
        `);
    });
    
    // Update showing info
    const totalRequests = filteredAdminRequests.length;
    const showingStart = totalRequests > 0 ? startIndex + 1 : 0;
    const showingEnd = Math.min(endIndex, totalRequests);
    $('#adminRequestsShowingInfo').text(`Showing ${showingStart}-${showingEnd} of ${totalRequests} requests`);
    
    renderAdminRequestsPagination();
};

function applyAdminRequestFilters() {
    const searchTerm = $('#searchAdminRequests').val().toLowerCase();
    const filterStatus = $('#filterAdminRequestStatus').val();
    
    filteredAdminRequests = requests.filter(r => {
        // Search filter
        const searchText = `${r.device_name} ${r.device_type} ${r.requester_full_name || r.requester_name || ''} ${r.holder_full_name || r.holder_name || ''} ${r.reason || ''}`.toLowerCase();
        if (searchTerm && !searchText.includes(searchTerm)) {
            return false;
        }
        
        // Status filter
        if (filterStatus && r.status !== filterStatus) {
            return false;
        }
        
        return true;
    });
    
    adminRequestsPage = 1;
    renderRequests();
}

function renderAdminRequestsPagination() {
    const totalPages = Math.ceil(filteredAdminRequests.length / adminRequestsPerPage);
    const paginationDiv = $('#adminRequestsPagination');
    paginationDiv.empty();
    
    if (totalPages <= 1) {
        return;
    }
    
    let paginationHTML = '<nav><ul class="pagination justify-content-center">';
    
    paginationHTML += `<li class="page-item ${adminRequestsPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeAdminRequestsPage(${adminRequestsPage - 1}); return false;">Previous</a>
    </li>`;
    
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= adminRequestsPage - 2 && i <= adminRequestsPage + 2)) {
            paginationHTML += `<li class="page-item ${i === adminRequestsPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changeAdminRequestsPage(${i}); return false;">${i}</a>
            </li>`;
        } else if (i === adminRequestsPage - 3 || i === adminRequestsPage + 3) {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    paginationHTML += `<li class="page-item ${adminRequestsPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeAdminRequestsPage(${adminRequestsPage + 1}); return false;">Next</a>
    </li>`;
    
    paginationHTML += '</ul></nav>';
    paginationDiv.html(paginationHTML);
}

function changeAdminRequestsPage(page) {
    const totalPages = Math.ceil(filteredAdminRequests.length / adminRequestsPerPage);
    if (page < 1 || page > totalPages) {
        return;
    }
    adminRequestsPage = page;
    renderRequests();
}

// Override loadRequests to initialize admin filters
const originalLoadRequests = window.loadRequests;
window.loadRequests = function() {
    $.get(API_URL + '?action=get_switch_requests', function(response) {
        if (response.success) {
            requests = response.requests;
            filteredAdminRequests = requests;
            applyAdminRequestFilters();
            updateStats();
        }
    });
};

// Search and filter handlers for admin requests
$('#searchAdminRequests').on('keyup', function() {
    applyAdminRequestFilters();
});

$('#filterAdminRequestStatus').on('change', function() {
    applyAdminRequestFilters();
});

// Rotation History with pagination
const originalRenderRotationHistory = window.renderRotationHistory;
window.renderRotationHistory = function(history) {
    rotationHistory = history || [];
    filteredRotationHistory = rotationHistory;
    
    // Update showing info immediately
    $('#historyShowingInfo').text(rotationHistory.length > 0 ? `${rotationHistory.length} records` : 'No history');
    
    filterHistory();
};

function filterHistory() {
    const search = $('#searchHistory').val().toLowerCase();
    
    if (rotationHistory.length === 0) {
        // No history at all - show empty state
        const tbody = $('#rotationHistoryTable tbody');
        tbody.empty();
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No rotation history yet</td></tr>');
        $('#historyShowingInfo').text('No history');
        $('#historyPagination').empty();
        return;
    }
    
    filteredRotationHistory = rotationHistory.filter(h => {
        const text = `${h.device_name} ${h.device_type} ${h.from_user_name || ''} ${h.to_user_name} ${h.rotated_by_name} ${h.reason || ''} ${h.notes || ''}`.toLowerCase();
        return text.includes(search);
    });
    
    historyPage = 1;
    renderHistoryPage();
}

function renderHistoryPage() {
    const tbody = $('#rotationHistoryTable tbody');
    tbody.empty();
    
    const startIndex = (historyPage - 1) * historyPerPage;
    const endIndex = startIndex + historyPerPage;
    const paginatedHistory = filteredRotationHistory.slice(startIndex, endIndex);
    
    if (paginatedHistory.length === 0) {
        tbody.html('<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle"></i> No rotation history found</td></tr>');
        $('#historyShowingInfo').text(filteredRotationHistory.length === 0 ? 'No history' : 'No results');
        return;
    }
    
    paginatedHistory.forEach(h => {
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
    
    const totalHistory = filteredRotationHistory.length;
    const showingStart = totalHistory > 0 ? startIndex + 1 : 0;
    const showingEnd = Math.min(endIndex, totalHistory);
    $('#historyShowingInfo').text(`Showing ${showingStart}-${showingEnd} of ${totalHistory} records`);
    
    renderHistoryPagination();
}

function renderHistoryPagination() {
    const totalPages = Math.ceil(filteredRotationHistory.length / historyPerPage);
    const paginationDiv = $('#historyPagination');
    paginationDiv.empty();
    
    if (totalPages <= 1) {
        return;
    }
    
    let paginationHTML = '<nav><ul class="pagination justify-content-center">';
    
    paginationHTML += `<li class="page-item ${historyPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeHistoryPage(${historyPage - 1}); return false;">Previous</a>
    </li>`;
    
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= historyPage - 2 && i <= historyPage + 2)) {
            paginationHTML += `<li class="page-item ${i === historyPage ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changeHistoryPage(${i}); return false;">${i}</a>
            </li>`;
        } else if (i === historyPage - 3 || i === historyPage + 3) {
            paginationHTML += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    paginationHTML += `<li class="page-item ${historyPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changeHistoryPage(${historyPage + 1}); return false;">Next</a>
    </li>`;
    
    paginationHTML += '</ul></nav>';
    paginationDiv.html(paginationHTML);
}

function changeHistoryPage(page) {
    const totalPages = Math.ceil(filteredRotationHistory.length / historyPerPage);
    if (page < 1 || page > totalPages) {
        return;
    }
    historyPage = page;
    renderHistoryPage();
}
</script>

<!-- Respond to Request Modal -->
<div class="modal fade" id="respondModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Respond to Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="respondForm">
                    <input type="hidden" id="requestId" name="request_id">
                    <input type="hidden" id="responseAction" name="response">
                    
                    <div class="mb-3">
                        <label class="form-label">Response Notes</label>
                        <textarea class="form-control" id="responseNotes" name="response_notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="respondToRequest('Rejected')">Reject</button>
                <button type="button" class="btn btn-success" onclick="respondToRequest('Approved')">Approve</button>
            </div>
        </div>
    </div>
</div>


<?php include '../../includes/footer.php'; 
