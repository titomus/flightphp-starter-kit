<?php

declare(strict_types=1);

// Emergency recovery, independent of app bootstrap/routes and MCP.
// Usage: php app/Console/atelier_rollback.php <release_id> <admin_id> [--emergency]
// Shell access to the hosting account is required.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/Mcp/CodeWorkspace.php';

$id = $argv[1] ?? '';
$adminId = (int) ($argv[2] ?? 0);
if ($id === '' || $adminId < 1) {
    fwrite(STDERR, "Usage: php app/Console/atelier_rollback.php <release_id> <admin_id> [--emergency]\n");
    exit(2);
}
try {
    $workspace = new \App\Mcp\CodeWorkspace(dirname(__DIR__, 2));
    $emergency = in_array('--emergency', $argv, true);
    $result = $emergency
        ? $workspace->emergencyRecover($adminId, $id)
        : $workspace->rollback($adminId, $id, true);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Rollback failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
