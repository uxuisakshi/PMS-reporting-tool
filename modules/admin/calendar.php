<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$auth = new Auth();
$auth->requireRole('admin');
$db = Database::getInstance();
$baseDir = getBaseDir();
$availabilityStatuses = getAvailabilityStatusOptions(false);
$availabilityStatusMap = [];
foreach ($availabilityStatuses as $statusRow) {
    $statusKey = strtolower(trim((string)($statusRow['status_key'] ?? '')));
    if ($statusKey === '') continue;
    $availabilityStatusMap[$statusKey] = [
        'status_label' => (string)($statusRow['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))),
        'badge_color' => (string)($statusRow['badge_color'] ?? 'secondary')
    ];
}
if (!isset($availabilityStatusMap['not_updated'])) {
    $availabilityStatusMap['not_updated'] = [
        'status_label' => 'Not Updated',
        'badge_color' => 'secondary'
    ];
}
$availabilityFilterOptions = $availabilityStatusMap;
$badgeToHex = [
    'primary' => '#0d6efd',
    'secondary' => '#6c757d',
    'success' => '#198754',
    'danger' => '#dc3545',
    'warning' => '#ffc107',
    'info' => '#0dcaf0',
    'light' => '#f8f9fa',
    'dark' => '#212529'
];

$pageTitle = 'Team Availability';
// Selected user filter for admin view (optional)
$selectedUser = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? intval($_GET['user_id']) : null;

// Handle AJAX request for events
if (isset($_GET['action']) && $_GET['action'] === 'get_events') {
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
    $selectedUser = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? $_GET['user_id'] : null;
    
    // Get status filters as array (from checkboxes)
    $statusFilters = isset($_GET['status_filter']) ? explode(',', $_GET['status_filter']) : ['all'];
    $statusFilters = array_values(array_filter(array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $statusFilters)));
    if (in_array('all', $statusFilters) || empty($statusFilters)) {
        $statusFilters = ['all'];
    }
    $statusFilterAllows = static function ($statusKey) use ($statusFilters) {
        $statusKey = strtolower(trim((string)$statusKey));
        if (in_array('all', $statusFilters, true)) return true;
        if (in_array($statusKey, $statusFilters, true)) return true;
        if (($statusKey === 'on_leave' || $statusKey === 'sick_leave') && in_array('leave', $statusFilters, true)) return true;
        return false;
    };
    $statusColor = static function ($statusKey) use ($availabilityStatusMap, $badgeToHex) {
        $statusKey = strtolower(trim((string)$statusKey));
        $badge = strtolower((string)($availabilityStatusMap[$statusKey]['badge_color'] ?? 'secondary'));
        return $badgeToHex[$badge] ?? '#6c757d';
    };
    $statusLabelFn = static function ($statusKey) use ($availabilityStatusMap) {
        $statusKey = strtolower(trim((string)$statusKey));
        return (string)($availabilityStatusMap[$statusKey]['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey)));
    };

    $events = [];

    // Fetch explicit statuses in range and index them by user+date (exclude admin and admin)
    $sql = "SELECT uds.*, u.full_name, u.role
         FROM user_daily_status uds
         JOIN users u ON uds.user_id = u.id
         WHERE uds.status_date BETWEEN ? AND ?
         AND u.role NOT IN ('admin')";
    $params = [$start, $end];
    if ($selectedUser && $selectedUser !== 'all') {
        $sql .= " AND u.id = ?";
        $params[] = $selectedUser;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $status_map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_map[$row['user_id']][$row['status_date']] = $row;
    }

    // Fetch summed hours per user per date in the range
    $hours_map = [];
    $hoursSql = "SELECT ptl.user_id, ptl.log_date, SUM(ptl.hours_spent) as total_hours
                 FROM project_time_logs ptl
                 JOIN users u ON ptl.user_id = u.id
                 WHERE ptl.log_date BETWEEN ? AND ?
                 AND u.role NOT IN ('admin')";
    $hparams = [$start, $end];
    if ($selectedUser && $selectedUser !== 'all') {
        $hoursSql .= " AND ptl.user_id = ?";
        $hparams[] = $selectedUser;
    }
    $hoursSql .= " GROUP BY ptl.user_id, ptl.log_date";
    $hstmt = $db->prepare($hoursSql);
    $hstmt->execute($hparams);
    while ($hr = $hstmt->fetch(PDO::FETCH_ASSOC)) {
        $hours_map[$hr['user_id']][$hr['log_date']] = floatval($hr['total_hours']);
    }

    // Fetch users to show "Not updated" where no status exists (exclude admin and admin)
    if ($selectedUser && $selectedUser !== 'all') {
        $users = $db->prepare("SELECT id, full_name, role FROM users WHERE is_active = 1 AND id = ? AND role NOT IN ('admin')");
        $users->execute([$selectedUser]);
        $users = $users->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $users = $db->query("SELECT id, full_name, role FROM users WHERE is_active = 1 AND role NOT IN ('admin') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Build date range.
    // Future dates stay empty unless a user has explicitly saved a status for that date.
    $todayDate = date('Y-m-d');
    $period = new DatePeriod(
        new DateTime($start), 
        new DateInterval('P1D'), 
        (new DateTime($end))->modify('+1 day')
    );

    // If "All Users" is selected, create consolidated events
    if ($selectedUser === 'all') {
        // Group users by date and status for consolidated view
        $consolidated_events = [];
        
        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $date_users = [];
            
            // Collect all users for this date
            foreach ($users as $u) {
                $userHours = $hours_map[$u['id']][$d] ?? 0;
                $userStatus = $status_map[$u['id']][$d] ?? null;
                
                if ($userStatus) {
                    $statusType = strtolower(trim((string)($userStatus['status'] ?? 'not_updated')));
                    
                    if ($statusFilterAllows($statusType)) {
                        $date_users[$statusType][] = [
                            'name' => $u['full_name'],
                            'id' => $u['id'],
                            'hours' => $userHours,
                            'status' => $userStatus['status'],
                            'notes' => $userStatus['notes'] ?? ''
                        ];
                    }
                } else {
                    // Not updated
                    if ($d <= $todayDate && $statusFilterAllows('not_updated')) {
                        $date_users['not_updated'][] = [
                            'name' => $u['full_name'],
                            'id' => $u['id'],
                            'hours' => $userHours,
                            'status' => 'not_updated',
                            'notes' => ''
                        ];
                    }
                }
            }
            
            // Create consolidated events for each status type
            foreach ($date_users as $statusType => $userList) {
                if (empty($userList)) continue;
                
                $count = count($userList);
                $totalHours = array_sum(array_column($userList, 'hours'));
                
                $color = $statusColor($statusType);
                $statusLabel = $statusLabelFn($statusType);
                
                // Highlight if total hours < expected (assuming 8h per person per day)
                if ($totalHours > 0 && $totalHours < ($count * 8)) {
                    $color = '#ff4d4f';
                }
                
                $title = $statusLabel . ' (' . $count . ')';
                if ($totalHours > 0) {
                    $title .= ' - ' . $totalHours . 'h';
                }
                
                $events[] = [
                    'title' => $title,
                    'start' => $d,
                    'color' => $color,
                    'description' => '',
                    'extendedProps' => [
                        'statusType' => $statusType,
                        'userCount' => $count,
                        'totalHours' => $totalHours,
                        'userList' => $userList,
                        'consolidated' => true
                    ]
                ];
            }
        }
    } else {
        // Individual user view (existing logic)
        foreach ($users as $u) {
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                
                $userHours = $hours_map[$u['id']][$d] ?? 0;
                if (empty($status_map[$u['id']][$d])) {
                    if ($d <= $todayDate && $statusFilterAllows('not_updated')) {
                        $title = $u['full_name'] . ' (Not updated)';
                        if ($userHours > 0) $title .= ' - ' . $userHours . 'h';
                        
                        // Truncate long titles for better display
                        $displayTitle = $title;
                        if (strlen($title) > 25) {
                            $displayTitle = substr($title, 0, 22) . '...';
                        }
                        
                        $color = $userHours > 0 && $userHours < 8 ? '#ff4d4f' : '#6c757d';
                        $events[] = [
                            'title' => $displayTitle,
                            'start' => $d,
                            'color' => $color,
                            'description' => '',
                            'extendedProps' => [
                                'role' => $u['role'],
                                'notes' => '',
                                'statusType' => 'not_updated',
                                'total_hours' => $userHours,
                                'user_id' => $u['id'],
                                'user_full_name' => $u['full_name'],
                                'fullTitle' => $title // Store full title for tooltip/modal
                            ]
                        ];
                    }
                } else {
                    $st = $status_map[$u['id']][$d];
                    $stType = strtolower(trim((string)($st['status'] ?? 'not_updated')));
                    if (!$statusFilterAllows($stType)) {
                        continue;
                    }
                    $title = $st['full_name'] . ' (' . $statusLabelFn($stType) . ')';
                    $userHours = $hours_map[$u['id']][$d] ?? 0;
                    if ($userHours > 0) $title .= ' - ' . $userHours . 'h';
                    
                    // Truncate long titles for better display
                    $displayTitle = $title;
                    if (strlen($title) > 25) {
                        $displayTitle = substr($title, 0, 22) . '...';
                    }
                    
                    $color = $statusColor($stType);
                    if ($userHours > 0 && $userHours < 8) $color = '#ff4d4f';
                    $events[] = [
                        'title' => $displayTitle,
                        'start' => $d,
                        'color' => $color,
                        'description' => $st['notes'] ?? '',
                        'extendedProps' => [
                            'role' => $st['role'],
                            'notes' => $st['notes'] ?? '',
                            'statusType' => $stType,
                            'total_hours' => $userHours,
                            'user_id' => $st['user_id'] ?? $u['id'],
                            'user_full_name' => $st['full_name'] ?? $u['full_name'],
                            'fullTitle' => $title // Store full title for tooltip/modal
                        ]
                    ];
                }
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($events);
    exit;
}

// Handle AJAX request for edit-request events (same page endpoint for reliability)
if (isset($_GET['action']) && $_GET['action'] === 'get_edit_requests') {
    $filterUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
    $sql = "
        SELECT uer.id, uer.user_id, uer.req_date, uer.reason, uer.status, uer.request_type, u.full_name AS user_name
        FROM user_edit_requests uer
        JOIN users u ON uer.user_id = u.id
        WHERE 1=1
    ";
    $params = [];
    if ($filterUserId) {
        $sql .= " AND uer.user_id = ?";
        $params[] = $filterUserId;
    }
    $sql .= " ORDER BY uer.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'requests' => $requests]);
    exit;
}

include __DIR__ . '/../../includes/header.php';

// Fetch users for dropdown (exclude admin/admin)
$allUsers = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role NOT IN ('admin') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>

<style>
    /* Modern Calendar UI/UX Improvements */
    .calendar-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    }
    
    .calendar-header h2 {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    
    .calendar-header p {
        opacity: 0.9;
        font-size: 1.1rem;
    }
    
    .calendar-controls {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.05);
        margin-bottom: 1.5rem;
    }
    
    .calendar-legend {
        background: white;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border: 1px solid rgba(0,0,0,0.05);
        margin-bottom: 1.5rem;
    }
    
    .calendar-container {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        border: 1px solid rgba(0,0,0,0.05);
        position: relative;
        min-height: 600px;
    }
    
    /* Day view specific container */
    .fc-dayGridDay-view .calendar-container {
        min-height: 700px;
        overflow-y: auto;
        max-height: 90vh;
    }
    
    .fc-dayGridDay-view #calendar {
        min-height: 650px;
        padding: 1rem 2rem !important;
    }
    
    /* Ensure day view content is fully visible */
    .fc-dayGridDay-view .fc-daygrid {
        min-height: 600px;
    }
    
    .fc-dayGridDay-view .fc-scrollgrid {
        height: auto !important;
        min-height: 600px;
    }
    
    .fc-dayGridDay-view .fc-scrollgrid-section-body {
        height: auto !important;
    }
    
    /* Enhanced Form Controls */
    .form-select {
        border-radius: 8px;
        border: 2px solid #e9ecef;
        padding: 0.75rem 1rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }
    
    /* Modern Button Styles */
    .btn-outline-primary {
        border: 2px solid #667eea;
        color: #667eea;
        font-weight: 600;
        border-radius: 8px;
        padding: 0.75rem 1.5rem;
        transition: all 0.3s ease;
    }
    
    .btn-outline-primary:hover {
        background: #667eea;
        border-color: #667eea;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }
    
    /* Status Filter Buttons */
    .status-filter-group {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .btn-check + .btn {
        border-radius: 20px;
        padding: 0.5rem 1rem;
        font-weight: 500;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        border-width: 2px;
    }
    
    .btn-check:checked + .btn {
        transform: scale(1.05);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    
    /* Calendar Enhancements */
    .fc-event {
        cursor: pointer;
        border: none !important;
        border-radius: 8px;
        font-size: 0.8em;
        padding: 4px 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }
    
    .fc-event:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 10;
        position: relative;
    }
    
    .fc-event-title {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Limit events per day to prevent overflow in month view only */
    .fc-dayGridMonth-view .fc-daygrid-day-events {
        max-height: 120px;
        overflow: hidden;
    }
    
    /* Allow full height in day view */
    .fc-dayGridDay-view .fc-daygrid-day-events {
        max-height: none !important;
        overflow: visible !important;
    }
    
    /* Better day view styling */
    .fc-dayGridDay-view .fc-daygrid-day {
        min-height: 600px !important;
        padding: 1rem !important;
    }
    
    .fc-dayGridDay-view .fc-event {
        margin-bottom: 0.5rem !important;
        padding: 0.75rem !important;
        font-size: 0.9rem !important;
        line-height: 1.4 !important;
    }
    
    .fc-daygrid-more-link {
        background: #f8f9fa !important;
        border: 1px solid #dee2e6 !important;
        color: #6c757d !important;
        font-size: 0.75rem !important;
        padding: 2px 6px !important;
        border-radius: 4px !important;
        font-weight: 600 !important;
    }
    
    .fc-daygrid-more-link:hover {
        background: #e9ecef !important;
        color: #495057 !important;
    }
    
    .fc-toolbar-title {
        font-size: 1.5rem !important;
        font-weight: 700 !important;
        color: #2d3748;
    }
    
    .fc-button-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        border: none !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        padding: 0.5rem 1rem !important;
        transition: all 0.3s ease !important;
    }
    
    .fc-button-primary:hover {
        transform: translateY(-1px) !important;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3) !important;
    }
    
    .fc-today-button {
        background: #48bb78 !important;
        border: none !important;
    }
    
    .fc-today-button:hover {
        background: #38a169 !important;
    }
    
    /* Legend Improvements */
    .legend-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.9rem;
        color: #4a5568;
        font-weight: 500;
        padding: 0.5rem;
        border-radius: 8px;
        transition: all 0.2s ease;
    }
    
    .legend-item:hover {
        background: #f7fafc;
        transform: translateX(2px);
    }
    
    .legend-dot {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.8);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        flex-shrink: 0;
    }
    
    /* Loading States */
    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 16px;
        z-index: 1000;
    }
    
    .loading-spinner {
        width: 40px;
        height: 40px;
        border: 4px solid #e2e8f0;
        border-top: 4px solid #667eea;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Responsive Improvements */
    @media (max-width: 768px) {
        .calendar-header {
            padding: 1.5rem;
            text-align: center;
        }
        
        .calendar-header h2 {
            font-size: 1.5rem;
        }
        
        .calendar-controls {
            padding: 1rem;
        }
        
        .status-filter-group {
            justify-content: center;
        }
        
        .legend-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Animation for smooth transitions */
    .calendar-container {
        animation: fadeInUp 0.6s ease-out;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Day Detail Cards (for day view) */
    .fc-day-detail {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        transition: all 0.2s ease;
    }
    
    .fc-day-detail:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        transform: translateY(-1px);
    }
    
    .fc-day-detail-title {
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
        color: #2d3748;
    }
    
    .badge-status {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        margin-right: 0.25rem;
        border-radius: 4px;
    }
    
    /* Fix FullCalendar Popover Positioning */
    .fc-popover {
        z-index: 1050 !important;
        max-width: 300px !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15) !important;
        border: 1px solid #e9ecef !important;
        border-radius: 8px !important;
        overflow: hidden !important;
    }
    
    .fc-more-popover {
        z-index: 1050 !important;
        max-width: 320px !important;
        max-height: 400px !important;
        overflow-y: auto !important;
    }
    
    .fc-popover-header {
        background: #f8f9fa !important;
        border-bottom: 1px solid #e9ecef !important;
        padding: 0.75rem 1rem !important;
        font-weight: 600 !important;
        color: #495057 !important;
    }
    
    .fc-popover-body {
        padding: 0.5rem !important;
        max-height: 300px !important;
        overflow-y: auto !important;
    }
    
    .fc-popover .fc-event {
        margin-bottom: 0.25rem !important;
        border-radius: 6px !important;
        font-size: 0.8rem !important;
        padding: 0.4rem 0.6rem !important;
    }
    
    /* Ensure popover stays within viewport */
    .calendar-container {
        position: relative;
        overflow: visible !important;
    }
    
    /* Fix popover positioning for edge cases */
    .fc-popover.fc-popover-start {
        transform: translateX(0) !important;
    }
    
    .fc-popover.fc-popover-end {
        transform: translateX(-100%) !important;
    }
    
    /* Custom scrollbar for popover */
    .fc-popover-body::-webkit-scrollbar {
        width: 6px;
    }
    
    .fc-popover-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }
    
    .fc-popover-body::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .fc-popover-body::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
</style>

<div class="container-fluid">
    <!-- Modern Header -->
    <div class="calendar-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2><i class="fas fa-calendar-alt me-3"></i>Team Availability</h2>
                <p class="mb-0">Real-time overview of resource production hours and availability status</p>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/modules/admin/production_logs.php" class="btn btn-outline-light btn-lg">
                    <i class="fas fa-list me-2"></i>Production Logs
                </a>
            </div>
        </div>
    </div>

    <!-- Enhanced Controls -->
    <div class="calendar-controls">
        <div class="row g-4 align-items-end">
            <div class="col-lg-3">
                <label class="form-label fw-semibold text-dark mb-2">
                    <i class="fas fa-users me-2 text-primary"></i>View Scope
                </label>
                <select id="userSelect" class="form-select">
                    <option value="">&#128100; Individual Users</option>
                    <option value="all">&#128101; All Users (Consolidated)</option>
                    <?php foreach ($allUsers as $au): ?>
                        <option value="<?php echo $au['id']; ?>" <?php echo ($selectedUser && $selectedUser == $au['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($au['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-9">
                <label class="form-label fw-semibold text-dark mb-2">
                    <i class="fas fa-filter me-2 text-primary"></i>Status Filters
                </label>
                <div class="status-filter-group">
                    <?php foreach ($availabilityFilterOptions as $statusKey => $meta): ?>
                        <?php
                        $inputId = 'filter_' . preg_replace('/[^a-z0-9_]+/i', '_', $statusKey);
                        $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                        $outlineClass = in_array($badgeColor, ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'], true)
                            ? $badgeColor
                            : 'secondary';
                        ?>
                        <input type="checkbox" class="btn-check status-filter-check" id="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>" checked autocomplete="off">
                        <label class="btn btn-outline-<?php echo htmlspecialchars($outlineClass, ENT_QUOTES, 'UTF-8'); ?>" for="<?php echo htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>
                    
                    <div class="vr mx-2"></div>

                    <input type="checkbox" class="btn-check hours-filter-check" id="filterUnder8Hours" value="under_8_hours" checked autocomplete="off">
                    <label class="btn btn-outline-warning" for="filterUnder8Hours">
                        <i class="fas fa-hourglass-half me-2"></i>Under 8 Hours
                    </label>

                    <input type="checkbox" class="btn-check hours-filter-check" id="filterCompliantHours" value="compliant" checked autocomplete="off">
                    <label class="btn btn-outline-success" for="filterCompliantHours">
                        <i class="fas fa-check-circle me-2"></i>Compliant
                    </label>

                    <div class="vr mx-2"></div>
                    
                    <input type="checkbox" class="btn-check" id="filterEditRequests" checked autocomplete="off" onchange="if(window.__adminCalendarToggleEditRequests){window.__adminCalendarToggleEditRequests();}">
                    <label class="btn btn-outline-info" for="filterEditRequests">
                        <i class="fas fa-bell me-2"></i>Edit Requests
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Legend -->
    <div class="calendar-legend">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="fw-bold text-dark mb-0">
                <i class="fas fa-info-circle me-2 text-primary"></i>Status Legend
            </h6>
            <small class="text-muted">Click on calendar events for detailed information</small>
        </div>
        <div class="legend-grid">
            <?php foreach ($availabilityFilterOptions as $statusKey => $meta): ?>
                <?php
                $badgeColor = strtolower((string)($meta['badge_color'] ?? 'secondary'));
                $legendColor = $badgeToHex[$badgeColor] ?? '#6c757d';
                ?>
                <div class="legend-item">
                    <span class="legend-dot" style="background:<?php echo htmlspecialchars($legendColor, ENT_QUOTES, 'UTF-8'); ?>"></span>
                    <span><?php echo htmlspecialchars((string)($meta['status_label'] ?? ucwords(str_replace('_', ' ', $statusKey))), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endforeach; ?>
            <div class="legend-item">
                <span class="legend-dot" style="background:#ff4d4f"></span>
                <span>Under 8h Logged</span>
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background:#17a2b8"></span>
                <span>Edit Request: Pending</span>
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background:#28a745"></span>
                <span>Edit Request: Approved</span>
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background:#dc3545"></span>
                <span>Edit Request: Rejected</span>
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background:#343a40"></span>
                <span>Edit Request: Used</span>
            </div>
        </div>
    </div>

    <!-- Enhanced Calendar Container -->
    <div class="calendar-container position-relative">
        <div id="calendar-loading" class="loading-overlay" style="display: none;">
            <div class="text-center">
                <div class="loading-spinner mb-3"></div>
                <div class="fw-semibold text-muted">Loading calendar data...</div>
            </div>
        </div>
        <div id="calendar" class="p-4"></div>
    </div>
</div>

<!-- Removed duplicate FullCalendar JS (already included at top) -->

<script nonce="<?php echo $cspNonce ?? ''; ?>">
window.AdminCalendarConfig = {
    statusMeta: <?php echo json_encode($availabilityFilterOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
    eventsUrl: <?php echo json_encode($_SERVER['PHP_SELF'] . '?action=get_events', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    editRequestsUrl: <?php echo json_encode($_SERVER['PHP_SELF'] . '?action=get_edit_requests', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    userHoursUrl: <?php echo json_encode($baseDir . '/api/user_hours.php', JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    dailyStatusUrl: <?php echo json_encode($baseDir . '/modules/my_daily_status.php', JSON_HEX_TAG | JSON_HEX_AMP); ?>
};

/* DOMContentLoaded logic moved to assets/js/admin-calendar.js */
</script>
<script src="<?php echo htmlspecialchars($baseDir, ENT_QUOTES, 'UTF-8'); ?>/assets/js/admin-calendar.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/admin-calendar.js'); ?>"></script>

<!-- Summernote (AdminLTE editor) -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>

<!-- Admin Edit Modal (Same structure as user calendar) -->
<div class="modal fade" id="adminEditModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form id="adminCalendarEditForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Left Column - Status Form -->
                        <div class="col-md-6">
                            <input type="hidden" id="a_user_id" name="user_id" value="">
                            <input type="hidden" id="a_date" name="date" value="">
                            <div class="mb-3">
                                <label for="a_status" class="form-label">Availability Status</label>
                                <select id="a_status" name="status" class="form-select">
                                    <?php foreach ($availabilityStatuses as $st): ?>
                                        <?php $stKey = (string)($st['status_key'] ?? ''); ?>
                                        <?php if ($stKey === '') continue; ?>
                                        <option value="<?php echo htmlspecialchars($stKey); ?>">
                                            <?php echo htmlspecialchars((string)($st['status_label'] ?? ucfirst(str_replace('_', ' ', $stKey)))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="a_notes" class="form-label">Notes (Visible to team)</label>
                                <textarea id="a_notes" name="notes" class="form-control" rows="4" placeholder="Add notes for this date..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="a_personal_note" class="form-label">Personal Note <small class="text-muted">(Visible only to user)</small></label>
                                <textarea id="a_personal_note" name="personal_note" class="form-control" rows="4" placeholder="Personal note or todo for this date..."></textarea>
                            </div>
                        </div>
                        
                        <!-- Right Column - Production Hours -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="fas fa-clock"></i> Production Hours
                                        <span id="adminHoursDate" class="text-muted ms-2"></span>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div id="adminHoursHeader" class="mb-3 text-center">
                                        <h4 id="adminTotalHours" class="text-primary">0.00 hrs</h4>
                                        <div class="progress mb-2">
                                            <div id="adminUtilizedProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%">
                                                Utilized
                                            </div>
                                            <div id="adminBenchProgress" class="progress-bar bg-secondary" role="progressbar" style="width: 100%">
                                                Bench/Off
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Utilized: <span id="adminUtilizedHours">0.00</span>h | 
                                            Off-Prod: <span id="adminBenchHours">0.00</span>h
                                        </small>
                                    </div>
                                    <div id="adminHoursEntries">
                                        <p class="text-muted text-center">Loading...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="adminEditBtn" class="btn btn-primary">Edit</button>
                    <!-- Dynamic save button will be added here -->
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JS moved to assets/js/admin-calendar.js -->

<!-- Consolidated Users Modal -->
<div class="modal fade" id="consolidatedModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="consolidatedModalTitle">Users Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="consolidatedContent">
                    <p class="text-muted">Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Request Modal -->
<div class="modal fade" id="editRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong>User:</strong> <span id="ermUser"></span></p>
                <p class="mb-2"><strong>Date:</strong> <span id="ermDate"></span></p>
                <div class="border rounded p-2 bg-light">
                    <p class="mb-0" id="ermReason"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
