<?php
declare(strict_types=1);

require dirname(__DIR__) . '/installer/WebInstallEngine.php';
$config = require dirname(__DIR__) . '/installer/config.php';
$root = sys_get_temp_dir() . '/flight-v3-admin-' . bin2hex(random_bytes(5));
mkdir($root . '/public', 0700, true);
try {
    $config['target_dir'] = $root;
    $config['mcp']['enabled'] = true;
    $config['backoffice']['enabled'] = true;
    $config['composer']['auto_install'] = false;
    $config['npm']['auto_build'] = false;
    $options = InstallerOptions::fromArgv(['install', '--dry-run']);
    $features = InstallerFeatures::fromConfig($config, $options);
    $blueprint = new ProjectBlueprint($config, $root, $features, new TemplateCatalog($features));
    $files = $blueprint->files();
    $controller = $files[$root . '/app/Controllers/Admin/McpSettingsController.php'] ?? '';
    $view = $files[$root . '/app/Views/admin/mcp-settings.php'] ?? '';
    $routes = $files[$root . '/app/routes/web.php'] ?? '';
    $workspace = $files[$root . '/app/Mcp/CodeWorkspace.php'] ?? '';
    $tools = $files[$root . '/app/Mcp/tools.php'] ?? '';
    foreach ([
        [$controller, 'deployment-approval.json'],
        [$view, 'deployment_approved'],
        [$workspace, 'function deploymentAllowed'],
        [$tools, 'CodeWorkspace::deploymentAllowed()'],
    ] as [$text, $expected]) {
        if (!str_contains($text, $expected)) {
            throw new RuntimeException('Missing V3 MCP approval: ' . $expected);
        }
    }
    $adminRoutes = (new BackofficeTemplateCatalog(new TemplateRenderer()))->adminRoutes(['namespace' => 'App']);
    if (!str_contains($adminRoutes, "'/mcp'") || !str_contains($adminRoutes, 'VerifyCsrfMiddleware') || !str_contains($adminRoutes, 'AuthenticateMiddleware') || !str_contains($adminRoutes, 'McpSettingsController')) {
        throw new RuntimeException('MCP settings route missing CSRF or authentication');
    }
    if (str_contains($workspace, 'function deploymentAllowed') && !str_contains($workspace, 'self::enabled()')) {
        throw new RuntimeException('Server deployment kill switch removed');
    }
    echo "[OK] V3 MCP admin approval generated and enforced.\n";
} finally {
    if (class_exists('WebInstallEngine')) WebInstallEngine::removeDirectory($root);
    else {
        @rmdir($root . '/public');
        @rmdir($root);
    }
}
