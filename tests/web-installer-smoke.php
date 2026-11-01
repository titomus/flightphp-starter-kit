<?php

declare(strict_types=1);

require dirname(__DIR__) . '/installer/WebInstallEngine.php';

function expectWeb(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException('FAILED: ' . $message);
    echo "[OK] $message\n";
}

$password = 'WebInstallerStrongPassword_2026!';
foreach (array_keys(WebInstallRecipe::TYPES) as $type) {
    $d = WebInstallRecipe::validate([
        'type' => $type,
        'project_name' => 'Projet Démo',
        'base_url' => 'https://example.test',
        'admin_email' => 'owner@example.test',
        'admin_password' => $password,
        'password_confirm' => $password,
        'members' => $type === 'saas' ? '1' : '0',
        'mcp' => '0',
        'stripe' => '0',
    ]);
    expectWeb($d['type'] === $type && $d['preset'] === WebInstallRecipe::TYPES[$type]['design'],
        'Recipe valid: ' . $type);
}
foreach ([
    ['type'=>'unsupported'],
    ['base_url'=>'http://example.test'],
    ['base_url'=>'https://example.test/a'],
    ['base_url'=>'https://user:password@example.test'],
    ['admin_email'=>'notanemail'],
    ['admin_password'=>'short', 'password_confirm'=>'short'],
    ['members'=>'0', 'stripe'=>'1'],
] as $override) {
    $valid = [
        'type' => 'saas', 'project_name' => 'Client',
        'base_url' => 'https://example.test', 'admin_email' => 'owner@example.test',
        'admin_password' => $password, 'password_confirm' => $password,
        'members'=>'1', 'mcp'=>'1', 'stripe'=>'0',
    ];
    try {
        WebInstallRecipe::validate(array_merge($valid, $override));
        throw new RuntimeException('Invalid recipe was accepted: ' . json_encode($override));
    } catch (InvalidArgumentException) {
        // Expected.
    }
}
echo "[OK] Invalid settings rejected\n";

$bundle = $argv[1] ?? null;
if (!is_string($bundle) || !is_dir($bundle)) {
    echo "Recipe checks PASS. Supply unpacked GitHub distribution to test a full installation.\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/flight-web-install-' . bin2hex(random_bytes(6));
mkdir($root . '/public', 0700, true);
try {
    $config = [
        'type' => 'saas',
        'project_name' => 'Client Démonstration',
        'base_url' => 'https://example.test',
        'admin_email' => 'owner@example.test',
        'admin_password' => $password,
        'password_confirm' => $password,
        'members' => '1', 'mcp' => '1', 'stripe' => '0',
    ];
    $result = WebInstallEngine::install($bundle, $root, $config);
    expectWeb($result['mcp'] === 'https://example.test/mcp', 'MCP endpoint described');
    foreach ([
        'app/bootstrap.php','app/Mcp/DynamicOAuth.php',
        'app/Database/Seeds/DatabaseSeeder.php','vendor/autoload.php',
        'public/index.php','public/build/manifest.json',
        'app/storage/database.sqlite','app/config/backoffice.php',
        '.env','.flight-project.json',
    ] as $path) {
        expectWeb(is_file($root . '/' . $path), 'Generated: ' . $path);
    }
    $pdo = new PDO('sqlite:' . $root . '/app/storage/database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query('SELECT email, password FROM admin_users LIMIT 1');
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    expectWeb(($owner['email'] ?? '') === 'owner@example.test', 'Owner persisted in database');
    expectWeb(password_verify($password, $owner['password']), 'Owner password hash correct');
    foreach (['app/config/backoffice.php', '.env', '.env.example', 'README.md'] as $path) {
        $body = file_get_contents($root . '/' . $path);
        expectWeb(!str_contains((string) $body, $password), 'Seed secret scrubbed: ' . $path);
    }
    $manifest = json_decode((string) file_get_contents($root . '/public/build/manifest.json'), true);
    expectWeb(is_array($manifest) && $manifest !== [], 'Real Vite asset manifest installed');
    $dbMigrations = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    expectWeb($dbMigrations >= 2, 'Database migrations applied');

    // No replacement of existing live application.
    try {
        WebInstallEngine::install($bundle, $root, $config);
        throw new RuntimeException('Second installation unexpectedly overwrote existing site.');
    } catch (RuntimeException $e) {
        expectWeb(str_contains($e->getMessage(), 'existante détectée'), 'Reinstallation refused');
    }
    echo "Web installer production-bundle integration: PASS\n";
} finally {
    WebInstallEngine::removeDirectory($root);
}
