<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

$page_title = 'Admin Vault';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col">
            <h2><i class="fas fa-lock"></i> Admin Personal Vault</h2>
            <p class="text-muted">Secure storage for credentials, notes, todos, and meetings</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-key"></i> Credentials</h5>
                    <h2 id="credentialsCount">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-sticky-note"></i> Notes</h5>
                    <h2 id="notesCount">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-tasks"></i> Pending Todos</h5>
                    <h2 id="todosCount">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-calendar"></i> Upcoming Meetings</h5>
                    <h2 id="meetingsCount">0</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#credentialsTab">
                <i class="fas fa-key"></i> Credentials
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#notesTab">
                <i class="fas fa-sticky-note"></i> Notes
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#todosTab">
                <i class="fas fa-tasks"></i> Todos
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#meetingsTab">
                <i class="fas fa-calendar-check"></i> Meetings
            </a>
        </li>
    </ul>

    <div class="tab-content mt-3">
        <!-- Credentials Tab -->
        <div id="credentialsTab" class="tab-pane fade show active">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Secure Credentials Storage</h5>
                    <button class="btn btn-primary" onclick="showAddCredentialModal()">
                        <i class="fas fa-plus"></i> Add Credential
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchCredentials" placeholder="Search credentials...">
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="filterCategory" onchange="filterCredentialsByCategory()">
                                <option value="">All Categories</option>
                                <option value="Software">Software</option>
                                <option value="Device">Device</option>
                                <option value="Account">Account</option>
                                <option value="Server">Server</option>
                                <option value="Database">Database</option>
                                <option value="API">API</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div id="credentialsList"></div>
                </div>
            </div>
        </div>

        <!-- Notes Tab -->
        <div id="notesTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-book"></i> Personal Notes</h5>
                    <button class="btn btn-primary" onclick="showAddNoteModal()">
                        <i class="fas fa-plus"></i> Add Note
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchNotes" placeholder="Search notes..." onkeyup="filterNotes()">
                        </div>
                        <div class="col-md-6">
                            <select class="form-select" id="filterNoteCategory" onchange="filterNotes()">
                                <option value="">All Categories</option>
                                <option value="General">General</option>
                                <option value="Project">Project</option>
                                <option value="Meeting">Meeting</option>
                                <option value="Technical">Technical</option>
                                <option value="Personal">Personal</option>
                                <option value="Important">Important</option>
                            </select>
                        </div>
                    </div>
                    <div id="notesList" class="row"></div>
                </div>
            </div>
        </div>

        <!-- Todos Tab -->
        <div id="todosTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-check-square"></i> Task Management</h5>
                    <button class="btn btn-primary" onclick="showAddTodoModal()">
                        <i class="fas fa-plus"></i> Add Todo
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <select class="form-select" id="filterTodoStatus" onchange="filterTodos()">
                                <option value="">All Status</option>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="filterTodoPriority" onchange="filterTodos()">
                                <option value="">All Priorities</option>
                                <option value="Critical">Critical</option>
                                <option value="High">High</option>
                                <option value="Medium">Medium</option>
                                <option value="Low">Low</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="searchTodos" placeholder="Search todos..." onkeyup="filterTodos()">
                        </div>
                    </div>
                    <div id="todosList"></div>
                </div>
            </div>
        </div>

        <!-- Meetings Tab -->
        <div id="meetingsTab" class="tab-pane fade">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-users"></i> Meetings & Reminders</h5>
                    <button class="btn btn-primary" onclick="showAddMeetingModal()">
                        <i class="fas fa-plus"></i> Schedule Meeting
                    </button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <select class="form-select" id="filterMeetingStatus" onchange="filterMeetings()">
                                <option value="">All Status</option>
                                <option value="Scheduled">Scheduled</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="Rescheduled">Rescheduled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchMeetings" placeholder="Search meetings..." onkeyup="filterMeetings()">
                        </div>
                    </div>
                    <div id="meetingsList"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.credential-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s;
    background: white;
}
.credential-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}
.note-card {
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    min-height: 180px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s;
    position: relative;
}
.note-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    transform: translateY(-2px);
}
.note-card .pin-icon {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 1.2rem;
}
.todo-item {
    border-left: 4px solid #6c757d;
    padding: 15px;
    margin-bottom: 12px;
    background: white;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: all 0.3s;
}
.todo-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.todo-item.critical { border-left-color: #dc3545; background: #fff5f5; }
.todo-item.high { border-left-color: #fd7e14; background: #fff8f0; }
.todo-item.medium { border-left-color: #ffc107; background: #fffbf0; }
.todo-item.low { border-left-color: #28a745; background: #f0fff4; }
.todo-item.completed { opacity: 0.7; text-decoration: line-through; }
.meeting-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.3s;
}
.meeting-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.meeting-card.upcoming {
    border-left: 4px solid #007bff;
}
.meeting-card.today {
    border-left: 4px solid #28a745;
    background: #f0fff4;
}
</style>

<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<script src="../../assets/js/admin-vault.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/admin-vault.js'); ?>"></script>

<?php include '../../includes/footer.php'; 
