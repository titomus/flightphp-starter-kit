<?php
declare(strict_types=1);

/**
 * CodeGraft full lifecycle in a disposable project (never a live website).
 * Exercises the real vendored engine, not the old four-file CodeWorkspace.
 */
require dirname(__DIR__) . '/installer/WebInstallEngine.php';
$config = require dirname(__DIR__) . '/installer/config.php';
$tag = bin2hex(random_bytes(6));
$root = sys_get_temp_dir() . '/flight-cg-flow-' . $tag;
$private = sys_get_temp_dir() . '/.flight-cg-flow-vault-' . $tag;
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
    foreach ($files as $path => $bytes) {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $bytes);
    }
    foreach (['Policy', 'Vault', 'Workspace', 'Mcp', 'Admin'] as $class) {
        require $root . '/app/CodeGraft/' . $class . '.php';
    }
    $vault = new \CodeGraft\Vault($root, $private, 'flight');
    $policyForm = [
        'action' => 'save_policy', 'enabled' => '1',
        'permission' => array_fill_keys(array_keys(\CodeGraft\Policy::OPERATIONS), '1'),
        'read_dirs' => "app/Services/Business\npublic/assets",
        'write_dirs' => "app/Services/Business\npublic/assets",
        'extra_denied' => '',
    ];
    \CodeGraft\Admin::handle($vault, $policyForm);
    $mcp = new \CodeGraft\Mcp($vault, new \CodeGraft\Workspace($vault));
    $call = static function (string $name, array $args = []) use ($mcp): array {
        $result = $mcp->dispatch(['jsonrpc' => '2.0', 'id' => 42,
            'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
        if (!is_array($result) || ($result['result']['isError'] ?? true)) {
            throw new RuntimeException($name . ': ' . json_encode($result));
        }
        return $result['result']['structuredContent'] ?? [];
    };
    $created = [
        ['path' => 'app/Services/Business/CustomerService.php', 'expected_sha256' => '',
            'content' => "<?php\nnamespace App\\Services\\Business;\nfinal class CustomerService { public function count(): int { return 2; } }\n"],
        ['path' => 'public/assets/custom.css', 'expected_sha256' => '',
            'content' => ".custom-page { display: grid; gap: 1rem; }\n"],
    ];
    $prepared = $call('cg_prepare_patch', ['summary' => 'Create business service and custom CSS', 'files' => $created]);
    $id = $prepared['id'] ?? '';
    if (!is_string($id) || strlen($id) !== 32) throw new RuntimeException('Missing patch identifier.');
    if (($call('cg_validate_patch', ['id' => $id])['valid'] ?? false) !== true) {
        throw new RuntimeException('CodeGraft patch not valid.');
    }
    $deployed = $call('cg_deploy_patch', ['id' => $id, 'confirmed' => true]);
    if (($deployed['status'] ?? '') !== 'deployed') throw new RuntimeException('Deploy not confirmed.');
    foreach ($created as $entry) {
        if (!is_file($root . '/' . $entry['path'])) throw new RuntimeException('Missing deployed file.');
    }
    $read = $call('cg_read_files', ['paths' => array_column($created, 'path')]);
    if (count($read['files'] ?? []) !== 2) throw new RuntimeException('Cannot read modified files.');
    $releases = $call('cg_history', ['limit' => 10]);
    if (count($releases['releases'] ?? []) !== 1) throw new RuntimeException('Missing release history.');
    $call('cg_rollback_patch', ['id' => $id, 'confirmed' => true]);
    foreach ($created as $entry) {
        if (is_file($root . '/' . $entry['path'])) throw new RuntimeException('Rollback failed.');
    }
    $policyForm['enabled'] = '0';
    \CodeGraft\Admin::handle($vault, $policyForm);
    $denied = $mcp->dispatch(['jsonrpc' => '2.0', 'id' => 43,
        'method' => 'tools/call',
        'params' => ['name' => 'cg_list_files', 'arguments' => []]]);
    if (($denied['result']['isError'] ?? false) !== true) {
        throw new RuntimeException('Disabled CodeGraft policy did not deny tool.');
    }
    echo "[OK] CodeGraft MCP: prepare, validate, deploy, read, history, rollback, live revoke.\n";
} finally {
    WebInstallEngine::removeDirectory($root);
    WebInstallEngine::removeDirectory($private);
}
