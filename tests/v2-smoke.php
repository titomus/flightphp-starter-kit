<?php

declare(strict_types=1);

// v2 reusable client starter smoke test, independent of Composer and a live server.
// Usage: php tests/v2-smoke.php
require dirname(__DIR__) . '/installer/FlightStarterInstaller.php';

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed { return $default; }
}


function assertV2(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = require dirname(__DIR__) . '/installer/config.php';
assertV2(($config['mcp']['enabled'] ?? false) === true, 'V2 must generate MCP by default');
$config['project_name'] = 'Client Independent';
$config['base_url'] = 'http://127.0.0.1:8765';
$config['composer']['auto_install'] = false;
$config['npm']['auto_build'] = false;
$config['installer']['cleanup_on_success'] = false;
$config['backoffice']['default_email'] = 'owner@example.test';
$config['backoffice']['default_password'] = 'TEST_ONLY_STRONG_UNIQUE_PASSWORD';

$root = sys_get_temp_dir() . '/flight-v2-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$config['target_dir'] = $root;
$files = (new ProjectBlueprint(
    $config, $root, InstallerFeatures::fromConfig($config, InstallerOptions::fromArgv(['install', '--dry-run'])),
    new TemplateCatalog(InstallerFeatures::fromConfig($config, InstallerOptions::fromArgv(['install', '--dry-run'])))
))->files();
assertV2(isset($files[$root . '/app/Mcp/DynamicOAuth.php']), 'OAuth provider generated');
assertV2(isset($files[$root . '/app/Mcp/CodeWorkspace.php']), 'Code deployment workspace generated');
assertV2(isset($files[$root . '/app/Mcp/business_tools.php']), 'Per-client business MCP tool registry generated');
assertV2(isset($files[$root . '/app/routes/mcp.php']), 'FlightPHP routed MCP generated');
assertV2(isset($files[$root . '/app/routes/business.php']), 'Client-specific Flight routes generated');
assertV2(isset($files[$root . '/app/Database/Migrations/20261030020000_create_mcp_dcr_tables.php']), 'OAuth database migration generated');
assertV2(isset($files[$root . '/app/Console/atelier_rollback.php']), 'Independent rollback CLI generated');
assertV2(!isset($files[$root . '/public/mcp.php']), 'No blocked direct PHP MCP entrypoint');
assertV2(str_contains($files[$root . '/app/bootstrap.php'], "require \$basePath . '/app/routes/mcp.php'"), 'Framework bootstrap loads MCP routes');
assertV2(str_contains($files[$root . '/.env.example'], 'ATELIER_CODE_UPDATES_ENABLED=false'), 'Code deploy remains disabled by default');

$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $child) {
            if (!in_array($child, ['.', '..'], true)) {
                $cleanup($path . '/' . $child);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

try {
    foreach ($files as $filename => $body) {
        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0700, true);
        }
        file_put_contents($filename, $body);
    }
    $phpFiles = array_filter(array_keys($files), static fn (string $p): bool => str_ends_with($p, '.php'));
    foreach ($phpFiles as $phpFile) {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($phpFile) . ' 2>&1', $output, $status);
        assertV2($status === 0, 'Generated PHP syntax error: ' . $phpFile . ' ' . implode(' ', $output));
    }

    require $root . '/app/Mcp/Server.php';
    require $root . '/app/Mcp/CodeWorkspace.php';
    require $root . '/app/Mcp/DynamicOAuth.php';

    assertV2(\App\Mcp\DynamicOAuth::validChatGptRedirect(
        'https://chatgpt.com/connector/oauth/client-test'
    ), 'Approved ChatGPT OAuth callback accepted');
    assertV2(!\App\Mcp\DynamicOAuth::validChatGptRedirect(
        'https://attacker.example/callback'
    ), 'Unknown OAuth callback rejected');

    // tools.php is evaluated with the authenticated caller identity from HttpEndpoint.
    $mcpPrincipal = ['admin_id' => 1, 'scopes' => ['business:read', 'app:evolve', 'app:deploy']];
    $tools = require $root . '/app/Mcp/tools.php';
    assertV2(isset($tools['app_info'], $tools['dev_list_files'], $tools['dev_read_file'],
        $tools['dev_prepare_patch'], $tools['dev_validate_patch'],
        $tools['dev_deploy'], $tools['dev_rollback'], $tools['dev_history']),
        'Client-neutral developer toolset generated');
    $server = new \App\Mcp\Server($tools);
    $list = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
    assertV2(count($list['result']['tools'] ?? []) >= 8, 'MCP discovery returns v2 tools');
    $info = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"app_info","arguments":{}}}');
    $data = json_decode($info['result']['content'][0]['text'] ?? '', true);
    assertV2(($data['name'] ?? '') === 'Client Independent', 'Generated app name is not demo-specific');

    $workspace = new \App\Mcp\CodeWorkspace($root);
    try {
        $workspace->readFile('app/Mcp/DynamicOAuth.php');
        throw new RuntimeException('OAuth source was exposed!');
    } catch (InvalidArgumentException) {
        assertV2(true, 'OAuth code protected');
    }
    $original = $workspace->readFile('app/routes/business.php');
    $newFile = 'app/Services/Business/ReportService.php';
    $source = "<?php\nnamespace App\\Services\\Business;\nfinal class ReportService { public static function sum(): int { return 42; } }\n";
    $draft = $workspace->prepare(1, 'Create and revert isolated business module', [
        ['path' => $newFile, 'expected_sha256' => '', 'content' => $source],
        ['path' => 'app/routes/business.php', 'expected_sha256' => $original['sha256'],
            'content' => $original['content'] . "\n// v2 reversible code smoke test\n"],
    ]);
    $validation = $workspace->validate(1, $draft['id']);
    assertV2($validation['valid'] === true, 'Draft validates before deployment');
    $release = $workspace->deploy(1, $draft['id'], true);
    assertV2($release['status'] === 'deployed' && is_file($root . '/' . $newFile),
        'Standalone business module deploys');
    $workspace->rollback(1, $draft['id'], true);
    assertV2(!is_file($root . '/' . $newFile)
        && hash_file('sha256', $root . '/app/routes/business.php') === $original['sha256'],
        'Rollback restores original routes and removes new business module');

    $noDeveloperPrincipal = ['admin_id' => 1, 'scopes' => ['business:read']];
    $mcpPrincipal = $noDeveloperPrincipal;
    $limitedTools = require $root . '/app/Mcp/tools.php';
    assertV2(!isset($limitedTools['dev_deploy'], $limitedTools['dev_read_file']),
        'Basic business token cannot deploy or read source');

    echo "Flight Starter Kit v2 generic install, OAuth, MCP, business module deployment and rollback: PASS\n";
} finally {
    $cleanup($root);
}
