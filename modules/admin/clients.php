<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

$auth = new Auth();
$auth->requireRole('admin');

$db = Database::getInstance();

// Handle client actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: clients.php');
        exit;
    }
    if (isset($_POST['add_client'])) {
        $name = sanitizeInput($_POST['name']);
        $description = sanitizeInput($_POST['description']);
        $prefix = isset($_POST['project_code_prefix']) ? sanitizeInput($_POST['project_code_prefix']) : null;
        
        $stmt = $db->prepare("INSERT INTO clients (name, description, project_code_prefix, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $description, $prefix, $_SESSION['user_id']]);
        
        $_SESSION['success'] = "Client added successfully!";
    } elseif (isset($_POST['edit_client'])) {
        $clientId = $_POST['client_id'];
        $name = sanitizeInput($_POST['name']);
        $description = sanitizeInput($_POST['description']);
        $prefix = isset($_POST['project_code_prefix']) ? sanitizeInput($_POST['project_code_prefix']) : null;
        
        $stmt = $db->prepare("UPDATE clients SET name = ?, description = ?, project_code_prefix = ? WHERE id = ?");
        $stmt->execute([$name, $description, $prefix, $clientId]);
        
        $_SESSION['success'] = "Client updated successfully!";
    } elseif (isset($_POST['delete_client'])) {
        $clientId = $_POST['client_id'];
        
        // Check if client has projects
        $check = $db->prepare("SELECT COUNT(*) as project_count FROM projects WHERE client_id = ?");
        $check->execute([$clientId]);
        $result = $check->fetch();
        
        if ($result['project_count'] == 0) {
            $stmt = $db->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $_SESSION['success'] = "Client deleted successfully!";
        } else {
            $_SESSION['error'] = "Cannot delete client with existing projects!";
        }
    }
    
    redirect($_SERVER['PHP_SELF']);
}

// Get all clients
$clients = $db->query("
    SELECT c.*, 
           COUNT(p.id) as project_count,
           u.full_name as created_by_name
    FROM clients c
    LEFT JOIN projects p ON c.id = p.client_id
    LEFT JOIN users u ON c.created_by = u.id
    GROUP BY c.id
    ORDER BY c.name
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<style>
/* Prevent chevron overlap in DataTables "Show entries" dropdown */
#clientsTable_length select,
div.dataTables_length select {
    padding-right: 2rem !important;
    background-position: right 0.65rem center !important;
    min-width: 4.5rem;
}
</style>
<div class="container-fluid">
    <h2>Client Management</h2>
    
    <!-- Add Client Button -->
    <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addClientModal">
        <i class="fas fa-plus"></i> Add New Client
    </button>
    
    <!-- Clients Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="clientsTable" class="table table-striped dataTable">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Description</th>
                            <th>Projects</th>
                            <th>Created By</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                        <tr>
                            <td><?php echo $client['name']; ?></td>
                            <td><?php echo $client['description'] ?: 'No description'; ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo $client['project_count']; ?> projects</span>
                            </td>
                            <td><?php echo $client['created_by_name']; ?></td>
                            <td><?php echo date('M d, Y', strtotime($client['created_at'])); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editClientModal<?php echo $client['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#deleteClientModal<?php echo $client['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        
                        <!-- Edit Client Modal -->
                        <div class="modal fade" id="editClientModal<?php echo $client['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="client_id" value="<?php echo $client['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Client: <?php echo $client['name']; ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label>Client Name *</label>
                                                <input type="text" name="name" class="form-control" 
                                                       value="<?php echo $client['name']; ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Description</label>
                                                <textarea name="description" class="form-control" rows="3"><?php echo $client['description']; ?></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label>Project Code Prefix (optional)</label>
                                                <input type="text" name="project_code_prefix" class="form-control" value="<?php echo htmlspecialchars($client['project_code_prefix'] ?? ''); ?>" placeholder="E.g. DOM">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" name="edit_client" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Delete Client Modal -->
                        <div class="modal fade" id="deleteClientModal<?php echo $client['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="client_id" value="<?php echo $client['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Delete Client</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Are you sure you want to delete <strong><?php echo $client['name']; ?></strong>?</p>
                                            <?php if ($client['project_count'] > 0): ?>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                This client has <?php echo $client['project_count']; ?> project(s). 
                                                Deleting will not remove the projects.
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="delete_client" class="btn btn-danger">Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Client Name *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3" 
                                  placeholder="Enter client description..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Project Code Prefix (optional)</label>
                        <input type="text" name="project_code_prefix" class="form-control" placeholder="E.g. DOM">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_client" class="btn btn-primary">Add Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; 