<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/performance_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This worker can only run from CLI.\n";
    exit(1);
}

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 1;
$maxRuntimeSeconds = isset($argv[2]) ? max(5, (int) $argv[2]) : 20;

try {
    $helper = new PerformanceHelper();
    $result = $helper->processInsightQueue($limit, $maxRuntimeSeconds);
    echo json_encode([
        'success' => true,
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Resource performance worker failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}