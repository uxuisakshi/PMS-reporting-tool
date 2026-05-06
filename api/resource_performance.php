<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/performance_helper.php';
$auth = new Auth();
$auth->requireRole('admin');

header('Content-Type: application/json');

$projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int) $_GET['project_id'] : null;
$userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : 0;
$startDate = isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : null;
$endDate = isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : null;

try {
    $helper = new PerformanceHelper();
    [$startDate, $endDate] = $helper->normalizeDateRange($startDate, $endDate);

    $rawStats = $helper->getResourceStats($userId ?: null, $projectId, $startDate, $endDate);
    $userIds = array_values(array_filter(array_map(static function ($row) {
        return (int) ($row['user_id'] ?? 0);
    }, $rawStats)));

    if (!empty($userIds)) {
        $helper->queueInsightGeneration($projectId, $startDate, $endDate, $userIds);
        $helper->dispatchBackgroundInsightWorker();
    }

    $dailyRecordMap = $helper->getDailyInsightRecordsForUsers($userIds, $projectId, $startDate, $endDate);

    $results = [];

    foreach ($rawStats as $userStats) {
        $resolvedUserId = (int) ($userStats['user_id'] ?? 0);
        $report = $helper->buildRangeInsightReport(
            $userStats,
            $dailyRecordMap[$resolvedUserId] ?? [],
            $startDate,
            $endDate
        );

        $results[] = array_merge([
            'user_id' => $resolvedUserId,
            'name' => (string) ($userStats['name'] ?? ''),
            'role' => (string) ($userStats['role'] ?? ''),
        ], $report);
    }

    echo json_encode([
        'success' => true,
        'queued' => !empty($userIds),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'data' => $results,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load resource performance insights.',
        'error' => $exception->getMessage(),
    ]);
}
