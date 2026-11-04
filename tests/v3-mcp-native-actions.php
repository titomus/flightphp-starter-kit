<?php
declare(strict_types=1);

/**
 * Executable business action parity contract in an entirely disposable app.
 * Checks the generated registry, web routes and genuine MCP tools without
 * requiring Composer, network or a live OAuth account.
 */
require dirname(__DIR__) . '/installer/WebInstallEngine.php';

$config = require dirname(__DIR__) . '/installer/config.php';
$config['mcp']['enabled'] = true;
$config['backoffice']['enabled'] = true;
$config['composer']['auto_install'] = false;
$config['npm']['auto_build'] = false;
$root = sys_get_temp_dir() . '/flight-native-actions-' . bin2hex(random_bytes(6));
mkdir($root . '/public', 0700, true);
$config['target_dir'] = $root;
try {
    $options = InstallerOptions::fromArgv(['install', '--dry-run']);
    $features = InstallerFeatures::fromConfig($config, $options);
    $files = (new ProjectBlueprint($config, $root, $features, new TemplateCatalog($features)))->files();
    $generated = [
        'app/Actions/ActionContext.php',
        'app/Actions/BusinessActionRegistry.php',
        'app/Actions/business.php',
        'app/Controllers/Business/WebActionController.php',
        'app/Console/check_mcp_parity.php',
        'app/Mcp/tools.php',
        'app/routes/business.php',
        'app/Views/admin/mcp-settings.php',
        'app/Controllers/Admin/McpSettingsController.php',
    ];
    foreach ($generated as $relative) {
        $path = $root . '/' . $relative;
        if (!isset($files[$path])) {
            throw new RuntimeException('Missing MCP-native generated file: ' . $relative);
        }
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $files[$path]);
        if (str_ends_with($path, '.php')) {
            $output = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
            if ($status !== 0) throw new RuntimeException('Syntax: ' . $relative . ' ' . implode("\n", $output));
        }
    }
    require $root . '/app/Actions/ActionContext.php';
    require $root . '/app/Actions/BusinessActionRegistry.php';
    $ctx = \App\Actions\ActionContext::verifiedWebActor('admin', ['id' => 7]);
    if ($ctx->actorId !== 7 || $ctx->channel !== 'web') throw new RuntimeException('Untrusted web identity.');

    // Every new business action has ONE service callback, shared by web and MCP.
    $calls = 0;
    $definition = [
        'stock_count' => [
            'description' => 'Count the stored products in the current business',
            'mode' => 'read', 'web' => true, 'mcp' => true, 'tenant_scoped' => false,
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['warehouse' => ['type' => 'string', 'maxLength' => 40, 'minLength' => 1]],
                'required' => ['warehouse'],
                'additionalProperties' => false,
            ],
            'authorize' => static fn (\App\Actions\ActionContext $actor, array $args): bool =>
                $actor->actorType === 'admin' && $actor->actorId === 7,
            'handler' => static function (\App\Actions\ActionContext $actor, array $args) use (&$calls): array {
                $calls++;
                return ['warehouse' => $args['warehouse'], 'total' => 4, 'for' => $actor->actorId];
            },
        ],
    ];
    $registry = new \App\Actions\BusinessActionRegistry($definition);
    $web = $registry->invoke('stock_count', ['warehouse' => 'Main'], $ctx);
    $mcpTools = $registry->mcpTools(['admin_id' => 7, 'scopes' => ['business:read']]);
    if (!isset($mcpTools['business_stock_count']) || count($mcpTools) !== 1) {
        throw new RuntimeException('New web business function not auto-published to MCP.');
    }
    $mcp = $mcpTools['business_stock_count']['handler'](['warehouse' => 'Main']);
    if ($web !== $mcp || $calls !== 2 || $registry->report()['paired'] !== 1) {
        throw new RuntimeException('Web and MCP are not calling the same business function.');
    }
    if ($registry->mcpTools(['admin_id' => 7, 'scopes' => ['app:deploy']]) !== []) {
        throw new RuntimeException('Read tools exposed without read scope.');
    }

    $assertRejected = static function (callable $fn, string $case): void {
        try {
            $fn();
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException) {
            return;
        }
        throw new RuntimeException('Security check did not reject: ' . $case);
    };
    $assertRejected(fn () => $registry->invoke('stock_count', ['warehouse' => 'Main', 'admin_id' => 99], $ctx), 'extra caller-supplied ID');
    $assertRejected(fn () => $registry->invoke('stock_count', ['warehouse' => 13], $ctx), 'incorrect JSON type');
    $assertRejected(fn () => $registry->invoke('stock_count', [], $ctx), 'missing input');
    $assertRejected(fn () => $registry->invoke('stock_count', ['warehouse' => 'Main'],
        \App\Actions\ActionContext::verifiedWebActor('member', ['id' => 7])), 'role ACL');
    $assertRejected(fn () => $registry->invoke('stock_count', ['warehouse' => 'Main'],
        \App\Actions\ActionContext::fromVerifiedOAuth(['admin_id' => 7, 'scopes' => ['business:write']])), 'OAuth scope');
    $broken = $definition;
    $broken['stock_count']['mcp'] = false;
    $assertRejected(fn () => new \App\Actions\BusinessActionRegistry($broken), 'web-only action without MCP parity');
    $broken = $definition;
    unset($broken['stock_count']['authorize']);
    $assertRejected(fn () => new \App\Actions\BusinessActionRegistry($broken), 'missing action ACL');

    $tenantActions = $definition;
    $tenantActions['stock_count']['tenant_scoped'] = true;
    $tenantRegistry = new \App\Actions\BusinessActionRegistry($tenantActions);
    $assertRejected(fn () => $tenantRegistry->mcpTools(['admin_id' => 7, 'scopes' => ['business:read']])
        ['business_stock_count']['handler'](['warehouse' => 'Main']), 'MCP without server-bound tenant');
    // mcpTools returns a handler but refuses execution with an unbound tenant.
    $tenantActor = \App\Actions\ActionContext::verifiedWebActor('member', ['id' => 8, 'tenant_id' => 12]);
    if ($tenantActor->requireTenant() !== 12) throw new RuntimeException('Tenant resolution failed.');

    $policy = $files[$root . '/app/CodeGraft/Policy.php'] ?? '';
    foreach (['app/Actions/BusinessActionRegistry.php', 'app/routes',
        'app/Controllers/Business/WebActionController.php'] as $protected) {
        if (!str_contains($policy, $protected)) throw new RuntimeException('Missing Core CodeGraft protection: ' . $protected);
    }

    if (!str_contains($files[$root . '/app/routes/business.php'], 'WebActionController')
        || !str_contains($files[$root . '/app/Mcp/tools.php'], 'mcpTools($principal')
        || !str_contains($files[$root . '/app/Controllers/Business/WebActionController.php'], 'hash_equals(csrf_token()')) {
        throw new RuntimeException('Web/MCP dual dispatch or CSRF handler missing.');
    }

    if ((new \App\Actions\BusinessActionRegistry(require $root . '/app/Actions/business.php'))->report()['count'] !== 0) {
        throw new RuntimeException('Production action catalogue should start empty.');
    }
    echo "[OK] MCP Native Actions: same web/MCP handler, strict schemas, OAuth, ACL, tenant deny, parity, CSRF and generated files.\n";
} finally {
    WebInstallEngine::removeDirectory($root);
}
