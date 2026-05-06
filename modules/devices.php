<?php
require_once '../includes/auth.php';
requireLogin();

$page_title = 'Devices';
include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-laptop"></i> Device Inventory</h2>
            <p class="text-muted">View all devices and their current assignments</p>
        </div>
        <?php if (in_array($_SESSION['role'] ?? '', ['admin'], true)): ?>
        <div class="col-auto d-flex align-items-start gap-2">
            <a href="../modules/admin/devices.php" class="btn btn-outline-primary">
                <i class="fas fa-cogs"></i> Manage Devices
            </a>
            <a href="../modules/admin/device_permissions.php" class="btn btn-outline-secondary">
                <i class="fas fa-user-shield"></i> Device Permissions
            </a>
            <a href="../modules/admin/uploads_manager.php" class="btn btn-outline-danger">
                <i class="fas fa-folder-open"></i> Uploads Manager
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- My Devices Section -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-user"></i> My Devices</h5>
        </div>
        <div class="card-body">
            <div id="myDevices" class="row"></div>
        </div>
    </div>

    <!-- All Devices Section -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list"></i> All Devices</h5>
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
                            <th>Device</th>
                            <th>Type</th>
                            <th>Model</th>
                            <th>Version</th>
                            <th>Ownership</th>
                            <th>Storage</th>
                            <th>Charger/Wire</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="devicesPagination" class="mt-3"></div>
        </div>
    </div>

    <!-- Incoming Requests Section (Requests for my devices) -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-inbox"></i> Incoming Device Requests</h5>
            <small id="incomingRequestsShowingInfo">Loading...</small>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <strong>Requests for your devices:</strong> Other users have requested devices currently assigned to you. You can accept or reject these requests directly.
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" id="searchIncomingRequests" placeholder="Search requests...">
                </div>
                <div class="col-md-6">
                    <select class="form-select" id="filterIncomingRequestStatus">
                        <option value="">All Status</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="incomingRequestsTable">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Requested By</th>
                            <th>Reason</th>
                            <th>Requested At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="incomingRequestsPagination" class="mt-3"></div>
        </div>
    </div>

    <!-- My Requests Section -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-exchange-alt"></i> My Device Switch Requests</h5>
            <small class="text-muted" id="myRequestsShowingInfo">Loading...</small>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> <strong>How it works:</strong>
                <ol class="mb-0 mt-2">
                    <li>Find a device you need that's assigned to someone else</li>
                    <li>Click "Request" button and provide a reason</li>
                    <li>The device holder or an admin can approve your request</li>
                    <li>If approved, the device will be automatically assigned to you</li>
                </ol>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" id="searchMyRequests" placeholder="Search requests...">
                </div>
                <div class="col-md-6">
                    <select class="form-select" id="filterMyRequestStatus">
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
                            <th>Current Holder</th>
                            <th>Your Reason</th>
                            <th>Requested At</th>
                            <th>Status</th>
                            <th>Admin Response</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="myRequestsPagination" class="mt-3"></div>
        </div>
    </div>
</div>

<!-- Request Device Modal -->
<div class="modal fade" id="requestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="requestModalTitle">Request Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="requestForm">
                    <input type="hidden" id="requestDeviceId" name="device_id">
                    <input type="hidden" id="requestAction" value="request_switch">
                    
                    <div class="alert alert-info">
                        <strong>Device:</strong> <span id="requestDeviceName"></span><br>
                        <strong>Current Holder:</strong> <span id="requestCurrentHolder"></span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Request *</label>
                        <textarea class="form-control" id="requestReason" name="reason" rows="4" 
                                  placeholder="Please explain why you need this device..." required></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <span id="requestHelpText">Your request will be sent to the device holder. They can accept your request, or an admin can approve it.</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="requestSubmitBtn" onclick="submitRequest()">Submit Request</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Device Modal -->
<div class="modal fade" id="deviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deviceModalTitle">Edit Device</h5>
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
                            <input type="number" class="form-control" id="storageCapacity" name="storage_capacity" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Charger / Wire Details</label>
                            <input type="text" class="form-control" id="chargerWire" name="charger_wire">
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
                        <input type="text" class="form-control" id="leaseOwner" name="lease_owner">
                    </div>
                    <div class="mb-3 d-none" id="editAssignWrap">
                        <label class="form-label">Assign To</label>
                        <select class="form-select" id="editAssignUserId" name="assigned_user_id">
                            <option value="">-- Keep Current Assignment --</option>
                        </select>
                        <small class="text-muted">Select a user to reassign the device.</small>
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

<style>
.device-card {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    transition: all 0.3s;
}

.device-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.device-icon {
    font-size: 3rem;
    color: #6c757d;
}

.device-info {
    flex-grow: 1;
}

.device-status {
    position: absolute;
    top: 10px;
    right: 10px;
}
</style>

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window.DevicesConfig = { 
    currentUserId: <?php echo (int)$_SESSION['user_id']; ?>,
    canManageDevices: <?php echo (in_array($_SESSION['role'] ?? '', ['admin'], true) || !empty($_SESSION['can_manage_devices'])) ? 'true' : 'false'; ?>,
    userRole: <?php echo json_encode($_SESSION['role'] ?? ''); ?>,
    apiBasePath: '../api/' // User page: modules/ -> go up 1 level to root, then api/
};
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/devices.js"></script>

<?php include '../includes/footer.php'; 