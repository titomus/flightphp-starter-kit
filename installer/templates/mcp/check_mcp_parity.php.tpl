<?php
declare(strict_types=1);

/** Run: php app/Console/check_mcp_parity.php */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

try {
    $report = \{{namespace}}\Actions\BusinessActionRegistry::fromApplication()->report();
    printf("MCP Native Actions: %d web/MCP pairs validated.\n", $report['paired']);
    foreach ($report['actions'] as $action) {
        printf("[OK] %s => %s\n", $action['name'], $action['tool']);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[FAIL] MCP action parity: " . $e->getMessage() . "\n");
    exit(1);
}
