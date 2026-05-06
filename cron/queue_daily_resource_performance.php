<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/performance_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This worker can only run from CLI.\n";
    exit(1);
}

$targetDate = isset($argv[1]) && trim((string) $argv[1]) !== '' ? trim((string) $argv[1]) : date('Y-m-d');
$projectId = isset($argv[2]) && trim((string) $argv[2]) !== '' ? (int) $argv[2] : null;
$processLimit = isset($argv[3]) ? max(1, (int) $argv[3]) : 5;
$maxRuntimeSeconds = isset($argv[4]) ? max(5, (int) $argv[4]) : 20;

try {
    $helper = new PerformanceHelper();
    [$targetDate] = $helper->normalizeDateRange($targetDate, $targetDate);

    $rawStats = $helper->getResourceStats(null, $projectId, $targetDate, $targetDate);
    $userIds = array_values(array_unique(array_filter(array_map(static function ($row) {
        return (int) ($row['user_id'] ?? 0);
    }, $rawStats))));

    $queued = $helper->queueInsightGeneration($projectId, $targetDate, $targetDate, $userIds);
    $processed = $helper->processInsightQueue($processLimit, $maxRuntimeSeconds);

    echo json_encode([
        'success' => true,
        'date' => $targetDate,
        'project_id' => $projectId,
        'resource_count' => count($userIds),
        'queued' => $queued,
        'processed' => $processed,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Daily resource performance queue failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}