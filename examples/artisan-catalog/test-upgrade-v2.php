<?php

declare(strict_types=1);

// Exercise the production upgrade against a full generated Atelier Catalogue
// installation. Does not touch the host system or business data.
$root = realpath($argv[1] ?? '');
if (!$root) {
    throw new RuntimeException('Pass generated app path.');
}
require $root . '/app/bootstrap.php';

function testUpgrade(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    echo "[OK] $description\n";
}

$workspace = new \App\Mcp\CodeWorkspace($root);
$files = $workspace->listFiles();
$paths = array_column($files, 'path');
testUpgrade(in_array('app/Controllers/CatalogController.php', $paths, true),
    'Existing Comptoir controller still editable');
testUpgrade(in_array('app/routes/business.php', $paths, true),
    'New Flight business routes available');
testUpgrade(in_array('public/assets/business.css', $paths, true),
    'Business CSS can be directly published');

$names = ['app/Controllers/Business/TestOrdersController.php',
    'app/Services/Business/TestOrdersService.php',
    'app/Models/Business/TestOrder.php',
    'app/Views/business/orders.php'];
$sources = [
    "<?php\nnamespace App\\Controllers\\Business;\nfinal class TestOrdersController { public function index(): void { echo 'ok'; } }\n",
    "<?php\nnamespace App\\Services\\Business;\nfinal class TestOrdersService { public function total(): int { return 42; } }\n",
    "<?php\nnamespace App\\Models\\Business;\nfinal class TestOrder { public int \$id = 1; }\n",
    "<section><h1>Orders</h1></section>\n",
];
$patch = [];
foreach ($names as $i => $name) {
    $patch[] = ['path' => $name, 'expected_sha256' => '', 'content' => $sources[$i]];
}
$route = $workspace->readFile('app/routes/business.php');
$css = $workspace->readFile('public/assets/business.css');
$js = $workspace->readFile('public/assets/business.js');
$patch[] = ['path' => $route['path'], 'expected_sha256' => $route['sha256'],
    'content' => $route['content'] . "\n// Test routing only; no route actually registered.\n"];
$patch[] = ['path' => $css['path'], 'expected_sha256' => $css['sha256'],
    'content' => $css['content'] . "\n.test-v2 { color: red; }\n"];
$patch[] = ['path' => $js['path'], 'expected_sha256' => $js['sha256'],
    'content' => $js['content'] . "\nwindow.flightV2Ready = true;\n"];

$draft = $workspace->prepare(1, 'Test seven-file business feature installation', $patch);
testUpgrade(count($draft['files']) === 7, 'Seven files staged as one patch');
$check = $workspace->validate(1, $draft['id']);
testUpgrade($check['valid'] === true && count($check['checks']) === 7, 'All seven files pass validation');
$deploy = $workspace->deploy(1, $draft['id'], true);
testUpgrade($deploy['status'] === 'deployed' && is_file($root . '/' . $names[0]),
    'Seven-file install succeeded');
$restored = $workspace->rollback(1, $draft['id'], true);
testUpgrade($restored['status'] === 'rolled_back'
    && !file_exists($root . '/' . $names[0])
    && hash_file('sha256', $root . '/app/routes/business.php') === $route['sha256']
    && hash_file('sha256', $root . '/public/assets/business.css') === $css['sha256']
    && hash_file('sha256', $root . '/public/assets/business.js') === $js['sha256'],
    'Seven-file rollback restores exact source and assets');

try {
    $workspace->readFile('app/Mcp/CodeWorkspace.php');
    throw new RuntimeException('Development engine is exposed to itself');
} catch (\InvalidArgumentException) {
    testUpgrade(true, 'Development engine remains protected from MCP');
}

echo "Atelier Catalogue v2 multi-file upgrade: PASS\n";
