<?php

declare(strict_types=1);

// Standalone test: no Composer packages, database or network needed.
require dirname(__DIR__) . '/installer/FlightStarterInstaller.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = require dirname(__DIR__) . '/installer/config.php';
$options = InstallerOptions::fromArgv(['install', '--dry-run']);
$config['mcp']['enabled'] = false;
$disabled = new ProjectBlueprint(
    $config,
    __DIR__,
    InstallerFeatures::fromConfig($config, $options),
    new TemplateCatalog(InstallerFeatures::fromConfig($config, $options))
);
$none = array_filter(array_keys($disabled->files()), static fn (string $name): bool => str_contains($name, '/Mcp/'));
check($none === [], 'MCP must be disabled by default');

$config['mcp']['enabled'] = true;
$features = InstallerFeatures::fromConfig($config, $options);
$blueprint = new ProjectBlueprint($config, __DIR__, $features, new TemplateCatalog($features));
$files = $blueprint->files();

$temp = sys_get_temp_dir() . '/flight-mcp-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
try {
    $generated = [];
    foreach ($files as $path => $contents) {
        if (!str_contains($path, '/Mcp/') && !str_ends_with($path, '/public/mcp.php')) {
            continue;
        }
        $relative = substr($path, strlen(__DIR__) + 1);
        $destination = $temp . '/' . $relative;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        file_put_contents($destination, $contents);
        $generated[$relative] = $destination;
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($destination), $output, $exitCode);
        check($exitCode === 0, 'PHP syntax error in ' . $relative . ': ' . implode(' ', $output));
    }
    check(count($generated) === 3, 'Three MCP files should be generated');

    require $generated['app/Mcp/Server.php'];
    $tools = require $generated['app/Mcp/tools.php'];
    $class = trim((string) $config['namespace'], '\\') . '\\Mcp\\Server';
    $server = new $class($tools);

    $initialize = $server->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
    check(($initialize['result']['protocolVersion'] ?? '') === '2025-03-26', 'Initialize failed');

    $listed = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
    check(($listed['result']['tools'][0]['name'] ?? '') === 'app_info', 'Tool registry missing');

    $called = $server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"app_info","arguments":{}}}');
    $value = json_decode($called['result']['content'][0]['text'] ?? '', true);
    check(($value['mcp_enabled'] ?? false) === true, 'Tool call failed');

    $unknown = $server->handle('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"missing"}}');
    check(($unknown['error']['code'] ?? null) === -32602, 'Unknown tool should fail');

    $invalid = $server->handle('{');
    check(($invalid['error']['code'] ?? null) === -32700, 'Malformed JSON should fail');

    check($server->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}') === null, 'Notification must not produce a response');

    echo "MCP generation and dispatch smoke test: OK\n";
} finally {
    foreach (glob($temp . '/app/Mcp/*') ?: [] as $path) {
        unlink($path);
    }
    if (isset($generated['public/mcp.php'])) {
        unlink($generated['public/mcp.php']);
    }
    foreach ([$temp . '/app/Mcp', $temp . '/app', $temp . '/public', $temp] as $dir) {
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
