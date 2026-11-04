<?php
declare(strict_types=1);

require dirname(__DIR__) . '/installer/WebInstallEngine.php';
$config = require dirname(__DIR__) . '/installer/config.php';
$root = sys_get_temp_dir() . '/flight-cg-native-' . bin2hex(random_bytes(5));
mkdir($root . '/public', 0700, true);
try {
    $config['target_dir'] = $root;
    $config['mcp']['enabled'] = true;
    $config['backoffice']['enabled'] = true;
    $config['composer']['auto_install'] = false;
    $config['npm']['auto_build'] = false;
    $options = InstallerOptions::fromArgv(['install', '--dry-run']);
    $features = InstallerFeatures::fromConfig($config, $options);
    $files = (new ProjectBlueprint($config, $root, $features, new TemplateCatalog($features)))->files();

    foreach (['Policy','Vault','Workspace','Mcp','Admin'] as $class) {
        $key = $root . '/app/CodeGraft/' . $class . '.php';
        if (!isset($files[$key]) || !str_contains($files[$key], 'namespace CodeGraft;')) {
            throw new RuntimeException('Real CodeGraft core missing: ' . $class);
        }
        if (!is_dir(dirname($key))) mkdir(dirname($key), 0700, true);
        file_put_contents($key, $files[$key]);
        if (str_ends_with($key, '/Policy.php')) require_once $key;
    }

    $factory = $files[$root . '/app/Mcp/CodeGraftFactory.php'] ?? '';
    $tools = $files[$root . '/app/Mcp/tools.php'] ?? '';
    $admin = $files[$root . '/app/Controllers/Admin/McpSettingsController.php'] ?? '';
    $view = $files[$root . '/app/Views/admin/mcp-settings.php'] ?? '';
    foreach ([
        [$factory, 'class CodeGraftFactory'],
        [$tools, '\CodeGraft\Mcp::definitions()'],
        [$tools, 'CodeGraftFactory::deploymentAllowed()'],
        [$admin, 'CodeGraftAdmin::handle'],
        [$admin, 'revoke_oauth'],
        [$admin, 'requireOwnerPassword'],
        [$view, 'Dossiers autorisés en écriture'],
        [$view, 'Journal des outils MCP CodeGraft'],
    ] as [$code, $needle]) {
        if (!str_contains($code, $needle)) throw new RuntimeException('Missing CodeGraft integration: ' . $needle);
    }
    foreach (['app/CodeGraft/Mcp.php', 'app/Mcp/DynamicOAuth.php',
        'app/Controllers/Admin/McpSettingsController.php'] as $protected) {
        try {
            \CodeGraft\Policy::path(
                ['read_dirs' => ['app'], 'write_dirs' => ['app']], $protected, true
            );
            throw new RuntimeException('Core write not blocked: ' . $protected);
        } catch (InvalidArgumentException) {}
    }
    \CodeGraft\Policy::path(['write_dirs' => ['app/Services/Business']],
        'app/Services/Business/InvoiceService.php', true);
    echo "[OK] Real CodeGraft core, admin controls, MCP bridge, and path policy generated.\n";
} finally {
    if (class_exists('WebInstallEngine')) WebInstallEngine::removeDirectory($root);
}
