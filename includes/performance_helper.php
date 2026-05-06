<?php
require_once __DIR__ . '/../config/database.php';

class PerformanceHelper {
    private $db;
    private static $schemaEnsured = false;
    private static $hoursReminderSchemaEnsured = false;

    private const WORKER_LOCK_NAME = 'resource_performance_feedback_worker';
    private const STALE_AFTER_SECONDS = 21600;
    private const DISPATCH_THROTTLE_SECONDS = 90;
    private const DEFAULT_ON_TIME_LOGIN_CUTOFF = '10:30:00';
    private const DEFAULT_ON_TIME_STATUS_CUTOFF = '11:00:00';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureResourcePerformanceFeedbackSchema();
        $this->ensureHoursReminderSettingsSchema();
    }

    private function getAssignedPageMatchSql($userExpression = 'ptl.user_id', $pageAlias = 'pp') {
        return "({$pageAlias}.at_tester_id = {$userExpression}
                OR {$pageAlias}.ft_tester_id = {$userExpression}
                OR {$pageAlias}.qa_id = {$userExpression}
                OR ({$pageAlias}.at_tester_ids IS NOT NULL AND JSON_VALID({$pageAlias}.at_tester_ids) AND JSON_CONTAINS({$pageAlias}.at_tester_ids, JSON_ARRAY({$userExpression})))
                OR ({$pageAlias}.ft_tester_ids IS NOT NULL AND JSON_VALID({$pageAlias}.ft_tester_ids) AND JSON_CONTAINS({$pageAlias}.ft_tester_ids, JSON_ARRAY({$userExpression}))))";
    }

    private function ensureHoursReminderSettingsSchema() {
        if (self::$hoursReminderSchemaEnsured) {
            return;
        }

        try {
            $columns = [
                "ADD COLUMN login_cutoff_time TIME DEFAULT '10:30:00' AFTER minimum_hours",
                "ADD COLUMN status_cutoff_time TIME DEFAULT '11:00:00' AFTER login_cutoff_time",
                "ADD COLUMN exclude_weekends TINYINT(1) DEFAULT 1 AFTER status_cutoff_time",
                "ADD COLUMN exclude_leave_days TINYINT(1) DEFAULT 1 AFTER exclude_weekends",
            ];

            foreach ($columns as $definition) {
                try {
                    $this->db->exec("ALTER TABLE hours_reminder_settings {$definition}");
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
        }

        self::$hoursReminderSchemaEnsured = true;
    }

    private function getHoursReminderSettings() {
        $this->ensureHoursReminderSettingsSchema();

        try {
            $stmt = $this->db->query("SELECT minimum_hours, login_cutoff_time, status_cutoff_time, exclude_weekends, exclude_leave_days FROM hours_reminder_settings LIMIT 1");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e) {
            $row = [];
        }

        return [
            'minimum_hours' => isset($row['minimum_hours']) ? (float) $row['minimum_hours'] : 8.0,
            'login_cutoff_time' => (string) ($row['login_cutoff_time'] ?? self::DEFAULT_ON_TIME_LOGIN_CUTOFF),
            'status_cutoff_time' => (string) ($row['status_cutoff_time'] ?? self::DEFAULT_ON_TIME_STATUS_CUTOFF),
            'exclude_weekends' => array_key_exists('exclude_weekends', $row) ? (bool) $row['exclude_weekends'] : true,
            'exclude_leave_days' => array_key_exists('exclude_leave_days', $row) ? (bool) $row['exclude_leave_days'] : true,
        ];
    }

    public function normalizeDateRange($startDate = null, $endDate = null) {
        $today = date('Y-m-d');
        $normalizedStart = $this->normalizeDateValue($startDate, date('Y-m-d', strtotime('-30 days')));
        $normalizedEnd = $this->normalizeDateValue($endDate, $today);

        if ($normalizedStart > $normalizedEnd) {
            [$normalizedStart, $normalizedEnd] = [$normalizedEnd, $normalizedStart];
        }

        return [$normalizedStart, $normalizedEnd];
    }

    /**
     * Aggregates performance stats for a specific user or all users.
     */
    public function getResourceStats($userId = null, $projectId = null, $startDate = null, $endDate = null) {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $stats = [];

        $params = [];
        if ($userId) {
            $usersQuery = "SELECT id, full_name, email, role FROM users WHERE id = :user_id";
            $params[':user_id'] = $userId;
        } else if ($projectId) {
            $usersQuery = "SELECT DISTINCT u.id, u.full_name, u.email, u.role 
                           FROM users u
                           JOIN project_pages pp ON (pp.at_tester_id = u.id OR pp.ft_tester_id = u.id OR pp.qa_id = u.id 
                                OR (pp.at_tester_ids IS NOT NULL AND JSON_VALID(pp.at_tester_ids) AND JSON_CONTAINS(pp.at_tester_ids, JSON_ARRAY(u.id)))
                                OR (pp.ft_tester_ids IS NOT NULL AND JSON_VALID(pp.ft_tester_ids) AND JSON_CONTAINS(pp.ft_tester_ids, JSON_ARRAY(u.id))))
                           WHERE pp.project_id = :project_id AND u.is_active = 1";
            $params[':project_id'] = $projectId;
        } else {
            $usersQuery = "SELECT id, full_name, email, role FROM users WHERE role != 'admin' AND is_active = 1";
        }

        $stmt = $this->db->prepare($usersQuery);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) return [];

        // If analyzing a single user, use the detailed per-user logic (fast)
        if ($userId) {
            $user = $users[0];
            return [$this->buildDetailedUserStats($user, $projectId, $startDate, $endDate)];
        }

        // BATCH MODE: Handle all users in 3 grouped queries
        $userIds = array_column($users, 'id');
        $idPlaceholder = implode(',', array_fill(0, count($userIds), '?'));

        // 1. Batch Accuracy
        $accSql = "SELECT created_by as user_id, COUNT(*) as total, 
                   SUM(CASE WHEN updated_at > DATE_ADD(created_at, INTERVAL 1 MINUTE) THEN 1 ELSE 0 END) as corrected 
                   FROM automated_a11y_findings 
                   WHERE created_by IN ($idPlaceholder)";
        $accParams = $userIds;
        if ($projectId) {
            $accSql .= " AND project_id = ?";
            $accParams[] = $projectId;
        }
        $accSql .= " AND DATE(created_at) BETWEEN ? AND ? GROUP BY created_by";
        $accParams[] = $startDate;
        $accParams[] = $endDate;
        
        $accStmt = $this->db->prepare($accSql);
        $accStmt->execute($accParams);
        $accLookup = $accStmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

        // 2. Batch Activity
        $actSql = "SELECT user_id, COUNT(*) FROM activity_log WHERE user_id IN ($idPlaceholder)";
        $actParams = $userIds;
        if ($projectId) {
            $actSql .= " AND entity_type = 'project' AND entity_id = ?";
            $actParams[] = $projectId;
        }
        $actSql .= " AND DATE(created_at) BETWEEN ? AND ? GROUP BY user_id";
        $actParams[] = $startDate;
        $actParams[] = $endDate;
        
        $actStmt = $this->db->prepare($actSql);
        $actStmt->execute($actParams);
        $actLookup = $actStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 3. Batch Communication
        $comSql = "SELECT ic.user_id, COUNT(*) FROM issue_comments ic 
                   LEFT JOIN issues i ON ic.issue_id = i.id 
                   WHERE ic.user_id IN ($idPlaceholder)";
        $comParams = $userIds;
        if ($projectId) {
            $comSql .= " AND i.project_id = ?";
            $comParams[] = $projectId;
        }
        $comSql .= " AND DATE(ic.created_at) BETWEEN ? AND ? GROUP BY ic.user_id";
        $comParams[] = $startDate;
        $comParams[] = $endDate;

        $comStmt = $this->db->prepare($comSql);
        $comStmt->execute($comParams);
        $comLookup = $comStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 4. Batch hours / projects touched
        $pageTestingMatch = $this->getAssignedPageMatchSql('ptl.user_id', 'pp');
        $hoursSql = "SELECT ptl.user_id,
                COALESCE(SUM(ptl.hours_spent), 0) AS total_hours,
                COUNT(DISTINCT ptl.project_id) AS project_count,
                COUNT(DISTINCT ptl.page_id) AS page_count,
                COALESCE(SUM(CASE WHEN ptl.task_type = 'page_testing' AND ptl.page_id IS NOT NULL THEN ptl.hours_spent ELSE 0 END), 0) AS page_testing_hours,
                COUNT(DISTINCT CASE WHEN ptl.task_type = 'page_testing' AND ptl.page_id IS NOT NULL THEN ptl.page_id END) AS assigned_page_testing_count
                 FROM project_time_logs ptl
                 LEFT JOIN project_pages pp ON pp.id = ptl.page_id
                 WHERE ptl.user_id IN ($idPlaceholder)";
        $hoursParams = $userIds;
        if ($projectId) {
            $hoursSql .= " AND project_id = ?";
            $hoursParams[] = $projectId;
        }
        $hoursSql .= " AND log_date BETWEEN ? AND ? GROUP BY user_id";
        $hoursParams[] = $startDate;
        $hoursParams[] = $endDate;

        $hoursStmt = $this->db->prepare($hoursSql);
        $hoursStmt->execute($hoursParams);
        $hoursLookup = $hoursStmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        // 5. Batch issue throughput
        $issueSql = "SELECT reporter_id AS user_id, COUNT(*) AS reported_issue_count
                     FROM issues
                     WHERE reporter_id IN ($idPlaceholder)";
        $issueParams = $userIds;
        if ($projectId) {
            $issueSql .= " AND project_id = ?";
            $issueParams[] = $projectId;
        }
        $issueSql .= " AND DATE(created_at) BETWEEN ? AND ? GROUP BY reporter_id";
        $issueParams[] = $startDate;
        $issueParams[] = $endDate;

        $issueStmt = $this->db->prepare($issueSql);
        $issueStmt->execute($issueParams);
        $issueLookup = $issueStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Merge Everything
        foreach ($users as $user) {
            $uId = $user['id'];
            $a = $accLookup[$uId] ?? ['total' => 0, 'corrected' => 0];
            $accTotal = (int)$a['total'];
            $accCorrected = (int)$a['corrected'];
            $accuracy = $accTotal > 0 ? round((($accTotal - $accCorrected) / $accTotal) * 100, 2) : null;
            $pageCount = (int) ($hoursLookup[$uId]['page_count'] ?? 0);
            $totalHours = (float) ($hoursLookup[$uId]['total_hours'] ?? 0);
            $pageTestingHours = (float) ($hoursLookup[$uId]['page_testing_hours'] ?? 0);
            $assignedPageTestingCount = (int) ($hoursLookup[$uId]['assigned_page_testing_count'] ?? 0);
            $reportedIssueCount = (int) ($issueLookup[$uId] ?? 0);
            $hoursRow = $hoursLookup[$uId] ?? ['total_hours' => 0, 'project_count' => 0, 'page_count' => 0, 'page_testing_hours' => 0, 'assigned_page_testing_count' => 0];

            $stats[] = [
                'user_id' => $uId,
                'name' => $user['full_name'],
                'role' => $user['role'],
                'stats' => [
                    'accuracy' => [
                        'total_findings' => $accTotal,
                        'corrected_count' => $accCorrected,
                        'accuracy_percentage' => $accuracy,
                        'accuracy_available' => $accTotal > 0
                    ],
                    'communication' => [
                        'total_comments' => (int)($comLookup[$uId] ?? 0),
                        'recent_samples' => [] // Skip samples for batch listing
                    ],
                    'activity' => [
                        'total_actions' => (int)($actLookup[$uId] ?? 0)
                    ],
                    'hours' => [
                        'total_hours' => $totalHours,
                        'project_count' => (int)($hoursRow['project_count'] ?? 0),
                        'page_count' => $pageCount,
                        'page_testing_hours' => $pageTestingHours,
                        'assigned_page_testing_count' => $assignedPageTestingCount,
                        'reported_issue_count' => $reportedIssueCount,
                        'avg_hours_per_page' => $assignedPageTestingCount > 0 ? round($pageTestingHours / $assignedPageTestingCount, 2) : null,
                        'issues_per_hour' => $pageTestingHours > 0 ? round($reportedIssueCount / $pageTestingHours, 2) : null
                    ]
                ]
            ];
        }

        return $stats;
    }

    public function getInsightRecord($userId, $projectId = null, $startDate = null, $endDate = null) {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $sql = "SELECT *
                FROM resource_performance_feedback
                WHERE user_id = ?
                  AND report_scope_start_date = ?
                  AND report_scope_end_date = ?";
        $params = [(int) $userId, $startDate, $endDate];

        if ($projectId === null) {
            $sql .= " AND project_id IS NULL";
        } else {
            $sql .= " AND project_id = ?";
            $params[] = (int) $projectId;
        }

        $sql .= " ORDER BY generated_at DESC, id DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getInsightRecordsForScope($projectId = null, $startDate = null, $endDate = null) {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);

        $sql = "SELECT *
                FROM resource_performance_feedback
                WHERE report_scope_start_date = ?
                  AND report_scope_end_date = ?";
        $params = [$startDate, $endDate];

        if ($projectId === null) {
            $sql .= " AND project_id IS NULL";
        } else {
            $sql .= " AND project_id = ?";
            $params[] = (int) $projectId;
        }

        $sql .= " ORDER BY user_id ASC, generated_at DESC, id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $records = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userKey = (int) ($row['user_id'] ?? 0);
            if ($userKey <= 0 || isset($records[$userKey])) {
                continue;
            }
            $records[$userKey] = $row;
        }

        return $records;
    }

    public function getDailyInsightRecordsForUsers(array $userIds, $projectId = null, $startDate = null, $endDate = null) {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $normalizedUserIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($normalizedUserIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedUserIds), '?'));
        $sql = "SELECT *
                FROM resource_performance_feedback
                WHERE user_id IN ($placeholders)
                  AND report_scope_start_date = report_scope_end_date
                  AND report_scope_start_date BETWEEN ? AND ?";
        $params = $normalizedUserIds;
        $params[] = $startDate;
        $params[] = $endDate;

        if ($projectId === null) {
            $sql .= " AND project_id IS NULL";
        } else {
            $sql .= " AND project_id = ?";
            $params[] = (int) $projectId;
        }

        $sql .= " ORDER BY user_id ASC, report_scope_start_date ASC, generated_at DESC, id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $records = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $resolvedUserId = (int) ($row['user_id'] ?? 0);
            $dayKey = (string) ($row['report_scope_start_date'] ?? '');
            if ($resolvedUserId <= 0 || $dayKey === '') {
                continue;
            }
            if (!isset($records[$resolvedUserId])) {
                $records[$resolvedUserId] = [];
            }
            if (!isset($records[$resolvedUserId][$dayKey])) {
                $records[$resolvedUserId][$dayKey] = $row;
            }
        }

        return $records;
    }

    public function buildRangeInsightReport(array $userStats, array $dailyRecords, $startDate = null, $endDate = null) {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $statusSummary = $this->resolveRangeInsightStatus($dailyRecords, $startDate, $endDate);
        $summaryInput = $userStats;
        $summaryInput['date_range'] = [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        return [
            'cached' => $statusSummary['status'] === 'ready',
            'has_report_content' => $statusSummary['ready_days'] > 0,
            'report_status' => $statusSummary['status'],
            'report_generated_at' => $statusSummary['generated_at'],
            'summary' => $this->buildRangeInsightPayload($dailyRecords, $summaryInput, $statusSummary),
            'stats' => $userStats['stats'] ?? [],
            'coverage' => [
                'ready_days' => $statusSummary['ready_days'],
                'total_days' => $statusSummary['total_days'],
                'missing_days' => $statusSummary['missing_days'],
            ],
        ];
    }

    public function queueInsightGeneration($projectId = null, $startDate = null, $endDate = null, array $userIds = []) {
        [$startDate, $endDate] = $this->normalizeDateRange($startDate, $endDate);
        $queued = 0;

        $normalizedUserIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($normalizedUserIds)) {
            return 0;
        }

        $dateKeys = $this->buildDateKeys($startDate, $endDate);
        $existingMap = $this->getDailyInsightRecordsForUsers($normalizedUserIds, $projectId, $startDate, $endDate);

        foreach ($normalizedUserIds as $userId) {
            foreach ($dateKeys as $dayKey) {
                $record = $existingMap[$userId][$dayKey] ?? null;
                if ($record) {
                    $status = (string) ($record['analysis_status'] ?? 'queued');
                    if ($status === 'processing') {
                        continue;
                    }

                    if ($status === 'ready' && !$this->isDailyInsightStale($record, $dayKey)) {
                        continue;
                    }

                    $update = $this->db->prepare("UPDATE resource_performance_feedback
                                                 SET analysis_status = 'queued',
                                                     last_error = NULL,
                                                     report_scope_start_date = ?,
                                                     report_scope_end_date = ?,
                                                     last_updated_at = NOW()
                                                 WHERE id = ?");
                    $update->execute([$dayKey, $dayKey, (int) $record['id']]);
                    $queued++;
                    continue;
                }

                $insertSql = "INSERT INTO resource_performance_feedback
                    (user_id, project_id, report_scope_start_date, report_scope_end_date, accuracy_score, activity_score,
                     positive_feedback, negative_feedback, ai_summary, stats_snapshot_json, analysis_status, generated_at, last_error)
                    VALUES (?, ?, ?, ?, 0, 0, '', '', NULL, NULL, 'queued', NULL, NULL)";
                $insert = $this->db->prepare($insertSql);
                $insert->execute([
                    $userId,
                    $projectId !== null ? (int) $projectId : null,
                    $dayKey,
                    $dayKey,
                ]);
                $queued++;
            }
        }

        return $queued;
    }

    public function dispatchBackgroundInsightWorker() {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $dispatchMarker = dirname(__DIR__) . '/tmp/resource-performance-worker.dispatch';
        $dispatchDir = dirname($dispatchMarker);
        if (!is_dir($dispatchDir)) {
            @mkdir($dispatchDir, 0775, true);
        }

        $lastDispatchAt = is_file($dispatchMarker) ? (int) @filemtime($dispatchMarker) : 0;
        if ($lastDispatchAt > 0 && (time() - $lastDispatchAt) < self::DISPATCH_THROTTLE_SECONDS) {
            return false;
        }

        @touch($dispatchMarker);

        if (!function_exists('exec')) {
            return false;
        }

        $workerScript = dirname(__DIR__) . '/cron/process_resource_performance_queue.php';
        $phpBinary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript) . ' > /dev/null 2>&1 &';

        try {
            @exec($command);
            return true;
        } catch (Throwable $e) {
            error_log('resource performance dispatch failed: ' . $e->getMessage());
            return false;
        }
    }

    public function processInsightQueue($limit = 1, $maxRuntimeSeconds = 20) {
        $processed = 0;
        $failed = 0;
        $startedAt = time();

        if (!$this->acquireWorkerLock()) {
            return ['processed' => 0, 'failed' => 0, 'skipped' => true];
        }

        try {
            $stmt = $this->db->prepare("SELECT *
                                        FROM resource_performance_feedback
                                        WHERE analysis_status IN ('queued', 'failed')
                                        ORDER BY FIELD(analysis_status, 'queued', 'failed'), last_updated_at ASC, id ASC
                                        LIMIT ?");
            $stmt->bindValue(1, max(1, (int) $limit), PDO::PARAM_INT);
            $stmt->execute();
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($jobs as $job) {
                if ((time() - $startedAt) >= max(5, (int) $maxRuntimeSeconds)) {
                    break;
                }

                $jobId = (int) ($job['id'] ?? 0);
                if ($jobId <= 0) {
                    continue;
                }

                $markProcessing = $this->db->prepare("UPDATE resource_performance_feedback
                                                      SET analysis_status = 'processing',
                                                          last_error = NULL,
                                                          last_updated_at = NOW()
                                                      WHERE id = ?");
                $markProcessing->execute([$jobId]);

                try {
                    $projectId = isset($job['project_id']) && $job['project_id'] !== null ? (int) $job['project_id'] : null;
                    $statsBundle = $this->getResourceStats(
                        (int) $job['user_id'],
                        $projectId,
                        $job['report_scope_start_date'] ?? null,
                        $job['report_scope_end_date'] ?? null
                    );
                    $stats = $statsBundle[0] ?? null;

                    if (!$stats) {
                        throw new RuntimeException('No performance stats available for queued insight job.');
                    }

                    $summary = $this->generateAIInsight($stats);
                    $this->saveInsightRecord($jobId, $stats, $summary);
                    $processed++;
                } catch (Throwable $e) {
                    $failed++;
                    $markFailed = $this->db->prepare("UPDATE resource_performance_feedback
                                                      SET analysis_status = 'failed',
                                                          last_error = ?,
                                                          last_updated_at = NOW()
                                                      WHERE id = ?");
                    $markFailed->execute([mb_substr($e->getMessage(), 0, 1000), $jobId]);
                    error_log('resource performance worker failed: ' . $e->getMessage());
                }
            }
        } finally {
            $this->releaseWorkerLock();
        }

        return ['processed' => $processed, 'failed' => $failed, 'skipped' => false];
    }

    public function buildInsightPayload($record, array $stats = []) {
        $status = (string) ($record['analysis_status'] ?? 'queued');
        $parsed = [];

        if (!empty($record['ai_summary'])) {
            $parsed = json_decode((string) $record['ai_summary'], true) ?: [];
        }

        if (empty($parsed) && !empty($stats)) {
            $parsed = $this->generateFallbackInsight($stats);
        }

        $overallSummary = (string) ($parsed['overall_summary'] ?? 'Background analysis has been queued. The report will appear automatically once ready.');
        if ($status === 'processing') {
            $overallSummary = 'Background analysis is currently running. Please check the report again shortly.';
        } elseif ($status === 'failed') {
            $overallSummary = 'The last background run failed. The report has been re-queued and will retry automatically.';
        }

        return [
            'overall_summary' => $overallSummary,
            'positive' => array_values(array_filter((array) ($parsed['positive'] ?? []))),
            'negative' => array_values(array_filter((array) ($parsed['negative'] ?? []))),
            'work_patterns' => array_values(array_filter((array) ($parsed['work_patterns'] ?? []))),
            'project_focus' => array_values(array_filter((array) ($parsed['project_focus'] ?? []))),
        ];
    }

    public function buildRangeInsightPayload(array $dailyRecords, array $stats = [], array $statusSummary = []) {
        $status = (string) ($statusSummary['status'] ?? 'queued');
        $readyRecords = [];
        foreach ($dailyRecords as $dayKey => $record) {
            if ((string) ($record['analysis_status'] ?? '') === 'ready') {
                $readyRecords[$dayKey] = $record;
            }
        }

        $baseSummary = !empty($stats) ? $this->generateFallbackInsight($stats) : [
            'overall_summary' => 'Background analysis has been queued. The report will appear automatically once ready.',
            'positive' => [],
            'negative' => [],
            'work_patterns' => [],
            'project_focus' => [],
        ];

        $positive = $this->mergeSummaryListItems($readyRecords, 'positive', $baseSummary['positive'] ?? []);
        $negative = $this->mergeSummaryListItems($readyRecords, 'negative', $baseSummary['negative'] ?? []);
        $workPatterns = $this->mergeSummaryListItems($readyRecords, 'work_patterns', $baseSummary['work_patterns'] ?? []);
        $projectFocus = $this->mergeSummaryListItems($readyRecords, 'project_focus', $baseSummary['project_focus'] ?? []);
        $dailyProgress = $this->buildDailyProgressEntries($dailyRecords);

        $readyDays = (int) ($statusSummary['ready_days'] ?? count($readyRecords));
        $totalDays = (int) ($statusSummary['total_days'] ?? max(1, count($dailyRecords)));
        $coverageSuffix = 'Daily coverage: ' . $readyDays . '/' . $totalDays . ' day(s).';
        $overallSummary = trim((string) ($baseSummary['overall_summary'] ?? ''));
        if ($overallSummary !== '') {
            $overallSummary .= ' ' . $coverageSuffix;
        } else {
            $overallSummary = $coverageSuffix;
        }

        if ($status === 'processing') {
            $overallSummary = 'Daily insight generation is currently running. ' . $coverageSuffix;
        } elseif ($status === 'queued') {
            $overallSummary = 'Daily insight records are being queued in the background. ' . $coverageSuffix;
        } elseif ($status === 'failed') {
            $overallSummary = 'Some daily insight runs failed and have been marked for retry. ' . $coverageSuffix;
        }

        return [
            'overall_summary' => $overallSummary,
            'positive' => $positive,
            'negative' => $negative,
            'work_patterns' => $workPatterns,
            'project_focus' => $projectFocus,
            'daily_progress' => $dailyProgress,
        ];
    }

    private function getFindingStats($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT COUNT(*) as total, 
                SUM(CASE WHEN updated_at > DATE_ADD(created_at, INTERVAL 1 MINUTE) THEN 1 ELSE 0 END) as corrected 
                FROM automated_a11y_findings 
                WHERE created_by = :user_id";
        
        $params = [':user_id' => $userId];
        
        if ($projectId) {
            $sql .= " AND project_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($res['total'] ?? 0);
            $corrected = (int)($res['corrected'] ?? 0);
            $accuracy = $total > 0 ? round((($total - $corrected) / $total) * 100, 2) : null;
            
            return [
                'total_findings' => $total,
                'corrected_count' => $corrected,
                'accuracy_percentage' => $accuracy,
                'accuracy_available' => $total > 0
            ];
        } catch (Exception $e) {
            return ['total_findings' => 0, 'corrected_count' => 0, 'accuracy_percentage' => null, 'accuracy_available' => false];
        }
    }

    private function getReportedIssueCount($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT COUNT(*) FROM issues WHERE reporter_id = :user_id";
        $params = [':user_id' => $userId];

        if ($projectId) {
            $sql .= " AND project_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getUserComments($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT ic.comment_html as text, ic.created_at 
                FROM issue_comments ic 
                LEFT JOIN issues i ON ic.issue_id = i.id 
                WHERE ic.user_id = :user_id";
        $params = [':user_id' => $userId];
        
        if ($projectId) {
            $sql .= " AND i.project_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(ic.created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }
        
        $sql .= " ORDER BY ic.created_at DESC LIMIT 20";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getActivityCount($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT COUNT(*) FROM activity_log WHERE user_id = :user_id";
        $params = [':user_id' => $userId];
        
        if ($projectId) {
            $sql .= " AND entity_type = 'project' AND entity_id = :project_id";
            $params[':project_id'] = $projectId;
        }

        if ($startDate && $endDate) {
            $sql .= " AND DATE(created_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function buildDetailedUserStats(array $user, $projectId = null, $startDate = null, $endDate = null) {
        $userId = (int) ($user['id'] ?? 0);
        $comments = $this->getUserComments($userId, $projectId, $startDate, $endDate);
        $hours = $this->getHoursSummary($userId, $projectId, $startDate, $endDate);
        $sessions = $this->getSessionStats($userId, $startDate, $endDate);
        $navigation = $this->getNavigationSummary($userId, $projectId, $startDate, $endDate);
        $recentJourney = $this->getRecentJourney($userId, $projectId, $startDate, $endDate);
        $activityBreakdown = $this->getActivityBreakdown($userId, $projectId, $startDate, $endDate);
        $discipline = $this->getDailyDisciplineSummary($userId, $startDate, $endDate);

        return [
            'user_id' => $userId,
            'project_id' => $projectId !== null ? (int) $projectId : null,
            'name' => (string) ($user['full_name'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'stats' => [
                'accuracy' => $this->getFindingStats($userId, $projectId, $startDate, $endDate),
                'communication' => [
                    'total_comments' => count($comments),
                    'recent_samples' => $comments,
                ],
                'activity' => [
                    'total_actions' => $this->getActivityCount($userId, $projectId, $startDate, $endDate),
                    'action_breakdown' => $activityBreakdown,
                ],
                'hours' => $hours,
                'sessions' => $sessions,
                'discipline' => $discipline,
                'navigation' => [
                    'top_pages' => $navigation,
                    'recent_journey' => $recentJourney,
                ],
            ],
        ];
    }

    private function getMinimumHoursRequirement() {
        $settings = $this->getHoursReminderSettings();
        return (float) ($settings['minimum_hours'] ?? 8.0);
    }

    private function getHoursSummary($userId, $projectId = null, $startDate = null, $endDate = null) {
                $pageTestingMatch = $this->getAssignedPageMatchSql('ptl.user_id', 'pp');
                $sql = "SELECT
                                        COALESCE(SUM(ptl.hours_spent), 0) AS total_hours,
                                        COALESCE(SUM(CASE WHEN ptl.is_utilized = 1 THEN ptl.hours_spent ELSE 0 END), 0) AS utilized_hours,
                                        COALESCE(SUM(CASE WHEN p.project_code = 'OFF-PROD-001' OR p.title LIKE 'Off-Production / Bench%' THEN ptl.hours_spent ELSE 0 END), 0) AS off_production_hours,
                                        COUNT(DISTINCT ptl.project_id) AS project_count,
                                        COUNT(DISTINCT ptl.page_id) AS page_count,
                                        COALESCE(SUM(CASE WHEN ptl.task_type = 'page_testing' AND ptl.page_id IS NOT NULL THEN ptl.hours_spent ELSE 0 END), 0) AS page_testing_hours,
                                        COUNT(DISTINCT CASE WHEN ptl.task_type = 'page_testing' AND ptl.page_id IS NOT NULL THEN ptl.page_id END) AS assigned_page_testing_count,
                                        COUNT(DISTINCT ptl.log_date) AS active_days
                                FROM project_time_logs ptl
                                LEFT JOIN projects p ON p.id = ptl.project_id
                                LEFT JOIN project_pages pp ON pp.id = ptl.page_id
                                WHERE ptl.user_id = ?
                                    AND ptl.log_date BETWEEN ? AND ?";
        $params = [$userId, $startDate, $endDate];

        if ($projectId) {
            $sql .= " AND ptl.project_id = ?";
            $params[] = $projectId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $projectSql = "SELECT
                            p.id AS project_id,
                            p.title AS project_title,
                            COALESCE(NULLIF(p.project_code, ''), NULLIF(p.po_number, ''), CONCAT('PROJECT-', p.id)) AS project_code,
                            COALESCE(SUM(ptl.hours_spent), 0) AS total_hours,
                            COUNT(DISTINCT ptl.log_date) AS active_days,
                            COUNT(DISTINCT ptl.page_id) AS page_count
                       FROM project_time_logs ptl
                       INNER JOIN projects p ON p.id = ptl.project_id
                       WHERE ptl.user_id = ?
                         AND ptl.log_date BETWEEN ? AND ?";
        $projectParams = [$userId, $startDate, $endDate];

        if ($projectId) {
            $projectSql .= " AND p.id = ?";
            $projectParams[] = $projectId;
        }

        $projectSql .= " GROUP BY p.id, p.title, p.project_code, p.po_number
                         ORDER BY total_hours DESC, active_days DESC, project_title ASC
                         LIMIT 5";

        $projectStmt = $this->db->prepare($projectSql);
        $projectStmt->execute($projectParams);
        $topProjects = $projectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $activeDays = max(1, (int) ($row['active_days'] ?? 0));
        $pageCount = (int) ($row['page_count'] ?? 0);
        $totalHours = round((float) ($row['total_hours'] ?? 0), 2);
        $pageTestingHours = round((float) ($row['page_testing_hours'] ?? 0), 2);
        $assignedPageTestingCount = (int) ($row['assigned_page_testing_count'] ?? 0);
        $reportedIssueCount = $this->getReportedIssueCount($userId, $projectId, $startDate, $endDate);

        return [
            'total_hours' => $totalHours,
            'utilized_hours' => round((float) ($row['utilized_hours'] ?? 0), 2),
            'off_production_hours' => round((float) ($row['off_production_hours'] ?? 0), 2),
            'project_count' => (int) ($row['project_count'] ?? 0),
            'page_count' => $pageCount,
            'page_testing_hours' => $pageTestingHours,
            'assigned_page_testing_count' => $assignedPageTestingCount,
            'active_days' => (int) ($row['active_days'] ?? 0),
            'daily_average_hours' => round(((float) ($row['total_hours'] ?? 0)) / $activeDays, 2),
            'reported_issue_count' => $reportedIssueCount,
            'avg_hours_per_page' => $assignedPageTestingCount > 0 ? round($pageTestingHours / $assignedPageTestingCount, 2) : null,
            'issues_per_hour' => $pageTestingHours > 0 ? round($reportedIssueCount / $pageTestingHours, 2) : null,
            'top_projects' => array_map(function ($projectRow) {
                return [
                    'project_id' => (int) ($projectRow['project_id'] ?? 0),
                    'project_title' => (string) ($projectRow['project_title'] ?? ''),
                    'project_code' => (string) ($projectRow['project_code'] ?? ''),
                    'total_hours' => round((float) ($projectRow['total_hours'] ?? 0), 2),
                    'active_days' => (int) ($projectRow['active_days'] ?? 0),
                    'page_count' => (int) ($projectRow['page_count'] ?? 0),
                ];
            }, $topProjects),
        ];
    }

    private function getSessionStats($userId, $startDate = null, $endDate = null) {
        $loginStmt = $this->db->prepare("SELECT
                                            COUNT(*) AS login_count,
                                            MAX(created_at) AS latest_login_at
                                         FROM activity_log
                                         WHERE user_id = ?
                                           AND action IN ('login', 'login_2fa')
                                           AND DATE(created_at) BETWEEN ? AND ?");
        $loginStmt->execute([$userId, $startDate, $endDate]);
        $loginRow = $loginStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sessionStmt = $this->db->prepare("SELECT
                                              COUNT(*) AS session_count,
                                              COALESCE(SUM(GREATEST(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(logout_at, last_activity)), 0)), 0) AS total_session_minutes,
                                              MAX(last_activity) AS latest_activity_at
                                           FROM user_sessions
                                           WHERE user_id = ?
                                             AND DATE(created_at) BETWEEN ? AND ?");
        $sessionStmt->execute([$userId, $startDate, $endDate]);
        $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'login_count' => (int) ($loginRow['login_count'] ?? 0),
            'latest_login_at' => (string) ($loginRow['latest_login_at'] ?? ''),
            'session_count' => (int) ($sessionRow['session_count'] ?? 0),
            'total_session_minutes' => (int) ($sessionRow['total_session_minutes'] ?? 0),
            'latest_activity_at' => (string) ($sessionRow['latest_activity_at'] ?? ''),
        ];
    }

    private function getDailyDisciplineSummary($userId, $startDate, $endDate) {
        $dateKeys = $this->buildDateKeys($startDate, $endDate);
        $settings = $this->getHoursReminderSettings();
        $minimumHours = (float) ($settings['minimum_hours'] ?? 8.0);
        $loginCutoff = (string) ($settings['login_cutoff_time'] ?? self::DEFAULT_ON_TIME_LOGIN_CUTOFF);
        $statusCutoff = (string) ($settings['status_cutoff_time'] ?? self::DEFAULT_ON_TIME_STATUS_CUTOFF);
        $excludeWeekends = !empty($settings['exclude_weekends']);
        $excludeLeaveDays = !empty($settings['exclude_leave_days']);

        $loginStmt = $this->db->prepare("SELECT DATE(created_at) AS activity_date,
                                                MIN(created_at) AS first_login_at
                                         FROM activity_log
                                         WHERE user_id = ?
                                           AND action IN ('login', 'login_2fa')
                                           AND DATE(created_at) BETWEEN ? AND ?
                                         GROUP BY DATE(created_at)");
        $loginStmt->execute([$userId, $startDate, $endDate]);
        $loginRows = $loginStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $loginMap = [];
        foreach ($loginRows as $row) {
            $dayKey = (string) ($row['activity_date'] ?? '');
            if ($dayKey !== '') {
                $loginMap[$dayKey] = (string) ($row['first_login_at'] ?? '');
            }
        }

        $statusStmt = $this->db->prepare("SELECT status_date, status, updated_at
                                          FROM user_daily_status
                                          WHERE user_id = ?
                                            AND status_date BETWEEN ? AND ?");
        $statusStmt->execute([$userId, $startDate, $endDate]);
        $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $statusMap = [];
        foreach ($statusRows as $row) {
            $dayKey = (string) ($row['status_date'] ?? '');
            if ($dayKey !== '') {
                $statusMap[$dayKey] = [
                    'status' => (string) ($row['status'] ?? 'not_updated'),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }
        }

        $hoursStmt = $this->db->prepare("SELECT log_date,
                                                COALESCE(SUM(hours_spent), 0) AS total_hours
                                         FROM project_time_logs
                                         WHERE user_id = ?
                                           AND log_date BETWEEN ? AND ?
                                         GROUP BY log_date");
        $hoursStmt->execute([$userId, $startDate, $endDate]);
        $hoursRows = $hoursStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hoursMap = [];
        foreach ($hoursRows as $row) {
            $dayKey = (string) ($row['log_date'] ?? '');
            if ($dayKey !== '') {
                $hoursMap[$dayKey] = (float) ($row['total_hours'] ?? 0);
            }
        }

        $observedDays = 0;
        $loginDays = 0;
        $onTimeLoginDays = 0;
        $lateLoginDays = 0;
        $statusUpdatedDays = 0;
        $onTimeStatusDays = 0;
        $lateStatusDays = 0;
        $compliantDays = 0;
        $sameDayCompliantDays = 0;
        $onTimeLoginDates = [];
        $lateLoginDates = [];
        $onTimeStatusDates = [];
        $lateStatusDates = [];
        $sameDayCompliantDates = [];

        foreach ($dateKeys as $dayKey) {
            $weekday = (int) date('N', strtotime($dayKey));
            $loginAt = $loginMap[$dayKey] ?? '';
            $statusRow = $statusMap[$dayKey] ?? null;
            $totalHours = (float) ($hoursMap[$dayKey] ?? 0);
            $statusKey = (string) ($statusRow['status'] ?? 'not_updated');
            $isWeekend = $weekday >= 6;
            $isLeaveDay = in_array($statusKey, ['on_leave', 'sick_leave'], true);
            if (($excludeWeekends && $isWeekend) || ($excludeLeaveDays && $isLeaveDay)) {
                continue;
            }

            $hasStatusUpdate = $statusRow && $statusKey !== 'not_updated';
            $hasAnySignal = ($loginAt !== '') || $hasStatusUpdate || $totalHours > 0;

            if ($hasAnySignal) {
                $observedDays++;
            }

            if ($loginAt !== '') {
                $loginDays++;
                $loginTime = date('H:i:s', strtotime($loginAt));
                if ($loginTime <= $loginCutoff) {
                    $onTimeLoginDays++;
                    $onTimeLoginDates[] = $dayKey;
                } else {
                    $lateLoginDays++;
                    $lateLoginDates[] = $dayKey;
                }
            }

            if ($hasStatusUpdate) {
                $statusUpdatedDays++;
                $statusTime = date('H:i:s', strtotime((string) ($statusRow['updated_at'] ?? '')));
                if ($statusTime <= $statusCutoff) {
                    $onTimeStatusDays++;
                    $onTimeStatusDates[] = $dayKey;
                } else {
                    $lateStatusDays++;
                    $lateStatusDates[] = $dayKey;
                }
            }

            if ($totalHours >= $minimumHours) {
                $compliantDays++;
                if ($hasStatusUpdate) {
                    $sameDayCompliantDays++;
                    $sameDayCompliantDates[] = $dayKey;
                }
            }
        }

        return [
            'observed_days' => $observedDays,
            'login_days' => $loginDays,
            'on_time_login_days' => $onTimeLoginDays,
            'late_login_days' => $lateLoginDays,
            'status_updated_days' => $statusUpdatedDays,
            'on_time_status_days' => $onTimeStatusDays,
            'late_status_days' => $lateStatusDays,
            'compliant_days' => $compliantDays,
            'same_day_compliant_days' => $sameDayCompliantDays,
            'minimum_hours' => $minimumHours,
            'login_cutoff_time' => $loginCutoff,
            'status_cutoff_time' => $statusCutoff,
            'exclude_weekends' => $excludeWeekends,
            'exclude_leave_days' => $excludeLeaveDays,
            'on_time_login_dates' => array_slice($onTimeLoginDates, 0, 7),
            'late_login_dates' => array_slice($lateLoginDates, 0, 7),
            'on_time_status_dates' => array_slice($onTimeStatusDates, 0, 7),
            'late_status_dates' => array_slice($lateStatusDates, 0, 7),
            'same_day_compliant_dates' => array_slice($sameDayCompliantDates, 0, 7),
        ];
    }

    private function getNavigationSummary($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT
                    pp.id AS page_id,
                    COALESCE(NULLIF(pp.page_name, ''), NULLIF(pp.page_number, ''), 'Unnamed Page') AS page_name,
                    COALESCE(p.title, 'Unassigned Project') AS project_title,
                    COALESCE(SUM(ptl.hours_spent), 0) AS total_hours,
                    COUNT(*) AS touch_count
                FROM project_time_logs ptl
                INNER JOIN project_pages pp ON pp.id = ptl.page_id
                LEFT JOIN projects p ON p.id = ptl.project_id
                WHERE ptl.user_id = ?
                  AND ptl.log_date BETWEEN ? AND ?
                  AND ptl.page_id IS NOT NULL";
        $params = [$userId, $startDate, $endDate];

        if ($projectId) {
            $sql .= " AND ptl.project_id = ?";
            $params[] = $projectId;
        }

        $sql .= " GROUP BY pp.id, pp.page_name, pp.page_number, p.title
                  ORDER BY total_hours DESC, touch_count DESC, page_name ASC
                  LIMIT 5";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(function ($row) {
            return [
                'page_id' => (int) ($row['page_id'] ?? 0),
                'page_name' => (string) ($row['page_name'] ?? ''),
                'project_title' => (string) ($row['project_title'] ?? ''),
                'total_hours' => round((float) ($row['total_hours'] ?? 0), 2),
                'touch_count' => (int) ($row['touch_count'] ?? 0),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function getRecentJourney($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "(SELECT al.created_at AS event_time,
                        'activity' AS event_type,
                        al.action AS title,
                        COALESCE(p.title, pp.page_name, al.entity_type, 'Activity') AS subtitle
                 FROM activity_log al
                 LEFT JOIN projects p ON al.entity_type = 'project' AND al.entity_id = p.id
                 LEFT JOIN project_pages pp ON al.entity_type = 'page' AND al.entity_id = pp.id
                 WHERE al.user_id = ?
                   AND DATE(al.created_at) BETWEEN ? AND ?";
        $params = [$userId, $startDate, $endDate];

        if ($projectId) {
            $sql .= " AND (
                            al.entity_type = 'auth'
                            OR (al.entity_type = 'project' AND al.entity_id = ?)
                            OR (al.entity_type = 'page' AND al.entity_id IN (SELECT id FROM project_pages WHERE project_id = ?))
                        )";
            $params[] = $projectId;
            $params[] = $projectId;
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT 5)
                 UNION ALL
                 (SELECT ptl.created_at AS event_time,
                         'hours' AS event_type,
                         CONCAT('Logged ', FORMAT(ptl.hours_spent, 2), 'h') AS title,
                         COALESCE(p.title, pp.page_name, 'Time Log') AS subtitle
                  FROM project_time_logs ptl
                  LEFT JOIN projects p ON p.id = ptl.project_id
                  LEFT JOIN project_pages pp ON pp.id = ptl.page_id
                  WHERE ptl.user_id = ?
                    AND ptl.log_date BETWEEN ? AND ?";
        $params[] = $userId;
        $params[] = $startDate;
        $params[] = $endDate;

        if ($projectId) {
            $sql .= " AND ptl.project_id = ?";
            $params[] = $projectId;
        }

        $sql .= " ORDER BY ptl.created_at DESC LIMIT 5)
                  ORDER BY event_time DESC
                  LIMIT 8";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(function ($row) {
            return [
                'event_time' => (string) ($row['event_time'] ?? ''),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'title' => ucwords(str_replace('_', ' ', (string) ($row['title'] ?? ''))),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function getActivityBreakdown($userId, $projectId = null, $startDate = null, $endDate = null) {
        $sql = "SELECT action, COUNT(*) AS action_count
                FROM activity_log
                WHERE user_id = ?
                  AND DATE(created_at) BETWEEN ? AND ?";
        $params = [$userId, $startDate, $endDate];

        if ($projectId) {
            $sql .= " AND (
                            entity_type = 'auth'
                            OR (entity_type = 'project' AND entity_id = ?)
                            OR (entity_type = 'page' AND entity_id IN (SELECT id FROM project_pages WHERE project_id = ?))
                        )";
            $params[] = $projectId;
            $params[] = $projectId;
        }

        $sql .= " GROUP BY action ORDER BY action_count DESC, action ASC LIMIT 8";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(function ($row) {
            return [
                'action' => (string) ($row['action'] ?? ''),
                'action_label' => ucwords(str_replace('_', ' ', (string) ($row['action'] ?? ''))),
                'count' => (int) ($row['action_count'] ?? 0),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function saveInsightRecord($recordId, array $stats, array $summary) {
        $update = $this->db->prepare("UPDATE resource_performance_feedback
                                     SET accuracy_score = ?,
                                         activity_score = ?,
                                         positive_feedback = ?,
                                         negative_feedback = ?,
                                         ai_summary = ?,
                                         stats_snapshot_json = ?,
                                         analysis_status = 'ready',
                                         generated_at = NOW(),
                                         last_error = NULL,
                                         last_updated_at = NOW()
                                     WHERE id = ?");

        $update->execute([
            (float) ($stats['stats']['accuracy']['accuracy_percentage'] ?? 0),
            (int) ($stats['stats']['activity']['total_actions'] ?? 0),
            implode("\n", (array) ($summary['positive'] ?? [])),
            implode("\n", (array) ($summary['negative'] ?? [])),
            json_encode($summary),
            json_encode($stats['stats'] ?? []),
            (int) $recordId,
        ]);
    }

    private function generateAIInsight(array $stats) {
        $fallback = $this->generateFallbackInsight($stats);

        if (!function_exists('curl_init')) {
            return $fallback;
        }

        $projectLabel = 'Overall portfolio';
        if (!empty($stats['project_id'])) {
            $projectLabel = $this->getProjectTitle((int) $stats['project_id']) ?: $projectLabel;
        }

        $payload = [
            'resource' => [
                'name' => $stats['name'] ?? '',
                'role' => $stats['role'] ?? '',
                'project_context' => $projectLabel,
                'date_range' => $stats['date_range'] ?? [],
            ],
            'metrics' => $stats['stats'] ?? [],
        ];

        $prompt = "Analyze the following PMS resource performance snapshot and return balanced, actionable feedback.\n"
            . "Focus on login discipline, on-time availability status updates, same-day hours compliance, navigation consistency, project contribution, hours, issue quality, and communication.\n"
            . "Return strict JSON with keys overall_summary, positive, negative, work_patterns, project_focus.\n\n"
            . json_encode($payload, JSON_PRETTY_PRINT);

        $ch = curl_init('http://127.0.0.1:11434/api/generate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'llama3:latest',
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
        ]));

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200 || !$response) {
            return $fallback;
        }

        $decoded = json_decode($response, true);
        $aiContent = (string) ($decoded['response'] ?? '');
        if (preg_match('/\{.*\}/s', $aiContent, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return [
                    'overall_summary' => (string) ($parsed['overall_summary'] ?? $fallback['overall_summary']),
                    'positive' => array_values(array_filter((array) ($parsed['positive'] ?? []))),
                    'negative' => array_values(array_filter((array) ($parsed['negative'] ?? []))),
                    'work_patterns' => array_values(array_filter((array) ($parsed['work_patterns'] ?? []))),
                    'project_focus' => array_values(array_filter((array) ($parsed['project_focus'] ?? []))),
                ];
            }
        }

        return $fallback;
    }

    private function generateFallbackInsight(array $stats) {
        $accuracyAvailable = !empty($stats['stats']['accuracy']['accuracy_available']);
        $accuracy = $accuracyAvailable ? (float) ($stats['stats']['accuracy']['accuracy_percentage'] ?? 0) : null;
        $actions = (int) ($stats['stats']['activity']['total_actions'] ?? 0);
        $hours = (float) ($stats['stats']['hours']['total_hours'] ?? 0);
        $projects = (int) ($stats['stats']['hours']['project_count'] ?? 0);
        $pages = (int) ($stats['stats']['hours']['page_count'] ?? 0);
        $assignedPageTestingCount = (int) ($stats['stats']['hours']['assigned_page_testing_count'] ?? 0);
        $pageTestingHours = (float) ($stats['stats']['hours']['page_testing_hours'] ?? 0);
        $reportedIssues = (int) ($stats['stats']['hours']['reported_issue_count'] ?? 0);
        $avgHoursPerPage = $stats['stats']['hours']['avg_hours_per_page'] ?? null;
        $issuesPerHour = $stats['stats']['hours']['issues_per_hour'] ?? null;
        $comments = (int) ($stats['stats']['communication']['total_comments'] ?? 0);
        $loginCount = (int) ($stats['stats']['sessions']['login_count'] ?? 0);
        $discipline = (array) ($stats['stats']['discipline'] ?? []);
        $topProjects = (array) ($stats['stats']['hours']['top_projects'] ?? []);
        $topPages = (array) ($stats['stats']['navigation']['top_pages'] ?? []);
        $observedDays = (int) ($discipline['observed_days'] ?? 0);
        $loginDays = (int) ($discipline['login_days'] ?? 0);
        $onTimeLoginDays = (int) ($discipline['on_time_login_days'] ?? 0);
        $statusUpdatedDays = (int) ($discipline['status_updated_days'] ?? 0);
        $onTimeStatusDays = (int) ($discipline['on_time_status_days'] ?? 0);
        $sameDayCompliantDays = (int) ($discipline['same_day_compliant_days'] ?? 0);
        $minimumHours = (float) ($discipline['minimum_hours'] ?? 8);
        $loginCutoff = (string) ($discipline['login_cutoff_time'] ?? self::DEFAULT_ON_TIME_LOGIN_CUTOFF);
        $statusCutoff = (string) ($discipline['status_cutoff_time'] ?? self::DEFAULT_ON_TIME_STATUS_CUTOFF);
        $excludeWeekends = !empty($discipline['exclude_weekends']);
        $excludeLeaveDays = !empty($discipline['exclude_leave_days']);

        $positive = [];
        $negative = [];
        $workPatterns = [];
        $projectFocus = [];
        $formatDates = function (array $dateKeys) {
            return implode(', ', array_map(static function ($dayKey) {
                $ts = strtotime((string) $dayKey);
                return $ts ? date('M j', $ts) : (string) $dayKey;
            }, $dateKeys));
        };

        $positive[] = $accuracyAvailable
            ? ($accuracy >= 85
                ? 'Accessibility finding accuracy stayed strong during the selected period.'
                : 'The resource maintained measurable issue contribution across the selected period.')
            : 'Manual accessibility accuracy sample was not available, so behavioural and throughput metrics were used instead.';
        $positive[] = $hours > 0
            ? 'Logged ' . number_format($hours, 1) . 'h across ' . max(1, $projects) . ' project(s).'
            : 'No delivery hours were logged in the selected window.';
        if ($avgHoursPerPage !== null) {
            $positive[] = 'Average page-testing effort was ' . number_format((float) $avgHoursPerPage, 2) . 'h across ' . $assignedPageTestingCount . ' assigned tested page(s).';
        }
        if ($loginDays > 0 && $onTimeLoginDays > 0) {
            $positive[] = 'Logged in on time on ' . $onTimeLoginDays . ' of ' . $loginDays . ' observed login day(s).';
        }
        if ($sameDayCompliantDays > 0) {
            $positive[] = 'Closed the day as compliant on ' . $sameDayCompliantDays . ' day(s) after updating availability and meeting the ' . number_format($minimumHours, 0) . 'h target.';
        }

        if ($actions < 5) {
            $negative[] = 'System activity was low, so the report has less behavioural evidence than usual.';
        }
        if (!$accuracyAvailable) {
            $negative[] = 'No accessibility findings were created in this window, so an accuracy percentage cannot be scored yet.';
        }
        if ($comments === 0) {
            $negative[] = 'Communication trail is thin; add comments/context more consistently when progressing work.';
        }
        if ($hours === 0) {
            $negative[] = 'No project hours were logged, which makes throughput and workload evaluation incomplete.';
        }
        if ($loginDays > 0 && $onTimeLoginDays < $loginDays) {
            $negative[] = 'Login discipline slipped on ' . ($loginDays - $onTimeLoginDays) . ' observed day(s).';
        }
        if ($statusUpdatedDays < max(1, $observedDays)) {
            $negative[] = 'Availability status was not updated consistently on all observed workdays.';
        }
        if ($sameDayCompliantDays < $statusUpdatedDays) {
            $negative[] = 'Some days had availability updates but did not finish compliant with the required same-day counts/hours.';
        }

        $workPatterns[] = $loginCount > 0
            ? 'Login events recorded: ' . $loginCount . ' during the selected period.'
            : 'No login event was captured in the selected period.';
        if ($loginDays > 0) {
            $workPatterns[] = 'On-time login days: ' . $onTimeLoginDays . '/' . $loginDays . ' before ' . $loginCutoff . '.';
        }
        if ($statusUpdatedDays > 0) {
            $workPatterns[] = 'Availability status updated on ' . $statusUpdatedDays . ' day(s); on-time updates: ' . $onTimeStatusDays . '/' . $statusUpdatedDays . ' before ' . $statusCutoff . '.';
        }
        if ($excludeWeekends || $excludeLeaveDays) {
            $scopeLabels = [];
            if ($excludeWeekends) {
                $scopeLabels[] = 'weekends';
            }
            if ($excludeLeaveDays) {
                $scopeLabels[] = 'leave days';
            }
            $workPatterns[] = 'Compliance denominator excludes ' . implode(' and ', $scopeLabels) . '.';
        }
        if (!empty($discipline['on_time_login_dates'])) {
            $workPatterns[] = 'Timely login days: ' . $formatDates((array) $discipline['on_time_login_dates']) . '.';
        }
        if (!empty($discipline['on_time_status_dates'])) {
            $workPatterns[] = 'Timely availability-update days: ' . $formatDates((array) $discipline['on_time_status_dates']) . '.';
        }
        if (!empty($topPages)) {
            $pageHighlights = array_map(function ($page) {
                return ($page['page_name'] ?? 'Page') . ' (' . number_format((float) ($page['total_hours'] ?? 0), 1) . 'h)';
            }, array_slice($topPages, 0, 3));
            $workPatterns[] = 'Most-touched pages: ' . implode(', ', $pageHighlights) . '.';
        }
        if ($avgHoursPerPage !== null) {
            $workPatterns[] = 'Page testing time logged: ' . number_format($pageTestingHours, 2) . 'h across ' . $assignedPageTestingCount . ' assigned page(s), averaging ' . number_format((float) $avgHoursPerPage, 2) . 'h per page.';
        }

        if (!empty($topProjects)) {
            $projectHighlights = array_map(function ($project) {
                return ($project['project_code'] ?? 'Project') . ' - ' . number_format((float) ($project['total_hours'] ?? 0), 1) . 'h';
            }, array_slice($topProjects, 0, 3));
            $projectFocus[] = 'Primary project focus: ' . implode(', ', $projectHighlights) . '.';
        }
        if ($issuesPerHour !== null) {
            $projectFocus[] = 'Reported ' . $reportedIssues . ' issue(s), averaging ' . number_format((float) $issuesPerHour, 2) . ' issues per page-testing hour.';
        } else {
            $projectFocus[] = 'Reported ' . $reportedIssues . ' issue(s); issues-per-hour will appear once assigned page-testing hours are available.';
        }
        if ($observedDays > 0) {
            $projectFocus[] = 'Same-day compliant days: ' . $sameDayCompliantDays . '/' . $observedDays . ' with availability updated and minimum ' . number_format($minimumHours, 0) . 'h completed.';
        }
        if (!empty($discipline['same_day_compliant_dates'])) {
            $projectFocus[] = 'Compliant same-day closure was achieved on: ' . $formatDates((array) $discipline['same_day_compliant_dates']) . '.';
        }
        $projectFocus[] = 'Total logged actions: ' . $actions . '; comments posted: ' . $comments . '.';

        return [
            'overall_summary' => $accuracyAvailable
                ? sprintf(
                    '%s worked across %d project(s), logged %.1f hours, spent %.1f page-testing hours, averaged %s per assigned tested page, and maintained %.2f%% finding accuracy in the selected range.',
                    (string) ($stats['name'] ?? 'This resource'),
                    max(0, $projects),
                    $hours,
                    $pageTestingHours,
                    $avgHoursPerPage !== null ? number_format((float) $avgHoursPerPage, 2) . 'h' : 'N/A',
                    (float) $accuracy
                )
                : sprintf(
                    '%s worked across %d project(s), logged %.1f hours, spent %.1f page-testing hours, averaged %s per assigned tested page, and reported %d issue(s) at %s in the selected range. Finding accuracy is unavailable because no accessibility findings were created in this window.',
                    (string) ($stats['name'] ?? 'This resource'),
                    max(0, $projects),
                    $hours,
                    $pageTestingHours,
                    $avgHoursPerPage !== null ? number_format((float) $avgHoursPerPage, 2) . 'h' : 'N/A',
                    $reportedIssues,
                    $issuesPerHour !== null ? number_format((float) $issuesPerHour, 2) . ' issues/page-testing hour' : 'N/A'
                ),
            'positive' => array_values(array_unique(array_filter($positive))),
            'negative' => array_values(array_unique(array_filter($negative))),
            'work_patterns' => array_values(array_unique(array_filter($workPatterns))),
            'project_focus' => array_values(array_unique(array_filter($projectFocus))),
        ];
    }

    private function getProjectTitle($projectId) {
        if ($projectId <= 0) {
            return '';
        }

        $stmt = $this->db->prepare('SELECT title FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function buildDateKeys($startDate, $endDate) {
        $dateKeys = [];
        $cursor = strtotime($startDate ?: date('Y-m-d'));
        $endTs = strtotime($endDate ?: date('Y-m-d'));

        while ($cursor !== false && $cursor <= $endTs) {
            $dateKeys[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $dateKeys;
    }

    private function resolveRangeInsightStatus(array $dailyRecords, $startDate, $endDate) {
        $dateKeys = $this->buildDateKeys($startDate, $endDate);
        $readyDays = 0;
        $processingFound = false;
        $failedFound = false;
        $queuedFound = false;
        $generatedAtCandidates = [];
        $missingDays = [];

        foreach ($dateKeys as $dayKey) {
            $record = $dailyRecords[$dayKey] ?? null;
            if (!$record) {
                $queuedFound = true;
                $missingDays[] = $dayKey;
                continue;
            }

            $status = (string) ($record['analysis_status'] ?? 'queued');
            if ($status === 'ready') {
                $readyDays++;
                if (!empty($record['generated_at'])) {
                    $generatedAtCandidates[] = (string) $record['generated_at'];
                }
                continue;
            }

            if ($status === 'processing') {
                $processingFound = true;
            } elseif ($status === 'failed') {
                $failedFound = true;
            } else {
                $queuedFound = true;
            }
        }

        $status = 'ready';
        if ($processingFound) {
            $status = 'processing';
        } elseif ($queuedFound || !empty($missingDays)) {
            $status = 'queued';
        } elseif ($failedFound) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'ready_days' => $readyDays,
            'total_days' => count($dateKeys),
            'missing_days' => $missingDays,
            'generated_at' => empty($generatedAtCandidates) ? '' : max($generatedAtCandidates),
        ];
    }

    private function parseInsightSummary(array $record) {
        if (empty($record['ai_summary'])) {
            return [];
        }

        $parsed = json_decode((string) $record['ai_summary'], true);
        return is_array($parsed) ? $parsed : [];
    }

    private function mergeSummaryListItems(array $dailyRecords, $key, array $fallbackItems = [], $limit = 5) {
        $merged = [];

        foreach ($dailyRecords as $record) {
            $parsed = $this->parseInsightSummary($record);
            foreach ((array) ($parsed[$key] ?? []) as $item) {
                $item = trim((string) $item);
                if ($item === '') {
                    continue;
                }
                $merged[$item] = true;
            }
        }

        foreach ($fallbackItems as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $merged[$item] = true;
        }

        return array_slice(array_keys($merged), 0, max(1, (int) $limit));
    }

    private function buildDailyProgressEntries(array $dailyRecords, $limit = 10) {
        ksort($dailyRecords);
        $entries = [];

        foreach ($dailyRecords as $dayKey => $record) {
            $stats = [];
            if (!empty($record['stats_snapshot_json'])) {
                $decoded = json_decode((string) $record['stats_snapshot_json'], true);
                if (is_array($decoded)) {
                    $stats = $decoded;
                }
            }

            $parts = [];
            $hours = (float) ($stats['hours']['total_hours'] ?? 0);
            $actions = (int) ($stats['activity']['total_actions'] ?? 0);
            $comments = (int) ($stats['communication']['total_comments'] ?? 0);
            $projects = (int) ($stats['hours']['project_count'] ?? 0);
            $discipline = (array) ($stats['discipline'] ?? []);

            if ($hours > 0) {
                $parts[] = '+' . number_format($hours, 1) . 'h logged';
            }
            if ($projects > 0) {
                $parts[] = $projects . ' project(s) touched';
            }
            if ($actions > 0) {
                $parts[] = $actions . ' actions';
            }
            if ($comments > 0) {
                $parts[] = $comments . ' comments';
            }
            if (!empty($discipline['on_time_login_days'])) {
                $parts[] = 'on-time login';
            }
            if (!empty($discipline['on_time_status_days'])) {
                $parts[] = 'status updated';
            }
            if (!empty($discipline['same_day_compliant_days'])) {
                $parts[] = 'same-day compliant';
            }

            $parsed = $this->parseInsightSummary($record);
            $headline = trim((string) ($parsed['overall_summary'] ?? ''));
            $dateLabel = date('M j, Y', strtotime($dayKey));

            if (empty($parts) && $headline === '') {
                $entries[] = $dateLabel . ': No tracked contribution was recorded.';
            } else {
                $line = $dateLabel . ': ' . implode(', ', $parts);
                if ($headline !== '') {
                    $line .= ' - ' . $headline;
                }
                $entries[] = trim($line);
            }
        }

        return array_slice(array_reverse($entries), 0, max(1, (int) $limit));
    }

    private function isInsightStale(array $record) {
        $generatedAt = strtotime((string) ($record['generated_at'] ?? $record['last_updated_at'] ?? ''));
        if ($generatedAt <= 0) {
            return true;
        }

        return (time() - $generatedAt) >= self::STALE_AFTER_SECONDS;
    }

    private function isDailyInsightStale(array $record, $dayKey) {
        $dayKey = (string) $dayKey;
        if ($dayKey === date('Y-m-d')) {
            return $this->isInsightStale($record);
        }

        return false;
    }

    private function normalizeDateValue($value, $fallback) {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $fallback;
        }

        return date('Y-m-d', $timestamp);
    }

    private function ensureResourcePerformanceFeedbackSchema() {
        if (self::$schemaEnsured) {
            return;
        }

        $createSql = "CREATE TABLE IF NOT EXISTS resource_performance_feedback (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            project_id INT DEFAULT NULL COMMENT 'NULL for Overall performance',
            report_scope_start_date DATE DEFAULT NULL,
            report_scope_end_date DATE DEFAULT NULL,
            accuracy_score FLOAT DEFAULT 0,
            activity_score INT DEFAULT 0,
            positive_feedback TEXT,
            negative_feedback TEXT,
            ai_summary LONGTEXT,
            stats_snapshot_json LONGTEXT DEFAULT NULL,
            analysis_status VARCHAR(20) NOT NULL DEFAULT 'queued',
            generated_at DATETIME DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_project (user_id, project_id),
            INDEX idx_analysis_status (analysis_status, last_updated_at),
            INDEX idx_scope_dates (report_scope_start_date, report_scope_end_date),
            CONSTRAINT fk_rpf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($createSql);

        $columns = $this->db->query('SHOW COLUMNS FROM resource_performance_feedback')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $requiredColumns = [
            'report_scope_start_date' => "ALTER TABLE resource_performance_feedback ADD COLUMN report_scope_start_date DATE DEFAULT NULL AFTER project_id",
            'report_scope_end_date' => "ALTER TABLE resource_performance_feedback ADD COLUMN report_scope_end_date DATE DEFAULT NULL AFTER report_scope_start_date",
            'stats_snapshot_json' => "ALTER TABLE resource_performance_feedback ADD COLUMN stats_snapshot_json LONGTEXT DEFAULT NULL AFTER ai_summary",
            'analysis_status' => "ALTER TABLE resource_performance_feedback ADD COLUMN analysis_status VARCHAR(20) NOT NULL DEFAULT 'queued' AFTER stats_snapshot_json",
            'generated_at' => "ALTER TABLE resource_performance_feedback ADD COLUMN generated_at DATETIME DEFAULT NULL AFTER analysis_status",
            'last_error' => "ALTER TABLE resource_performance_feedback ADD COLUMN last_error TEXT DEFAULT NULL AFTER generated_at",
        ];

        foreach ($requiredColumns as $columnName => $alterSql) {
            if (!in_array($columnName, $columns, true)) {
                $this->db->exec($alterSql);
            }
        }

        self::$schemaEnsured = true;
    }

    private function acquireWorkerLock() {
        $lockName = $this->getWorkerLockName();
        try {
            $stmt = $this->db->query("SELECT GET_LOCK('" . $lockName . "', 0)");
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            error_log('resource performance worker lock failed: ' . $e->getMessage());
            return false;
        }
    }

    private function releaseWorkerLock() {
        $lockName = $this->getWorkerLockName();
        try {
            $this->db->query("DO RELEASE_LOCK('" . $lockName . "')");
        } catch (Throwable $e) {
            error_log('resource performance worker unlock failed: ' . $e->getMessage());
        }
    }

    private function getWorkerLockName() {
        $dbName = defined('DB_NAME') ? (string) DB_NAME : 'default';
        return self::WORKER_LOCK_NAME . '_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $dbName);
    }
}
