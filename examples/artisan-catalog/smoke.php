<?php

declare(strict_types=1);

// Functional checks against the app built by the actual starter-kit installer.
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Provide a generated artisan application path');
}
require $root . '/app/bootstrap.php';

function verify(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    echo '[OK] ' . $description . PHP_EOL;
}

$parser = new \App\Services\CatalogCsv();
$rows = $parser->read($root . '/example-data/references.csv');
verify(count($rows) === 3, 'Read sample CSV with semicolon delimiter');

$service = new \App\Services\CatalogService(\App\Services\Database::connection());
$preview = $service->previewImport($rows);
verify($preview['valid'] && $preview['counts']['create'] === 3, 'Preview three new records without writing');

$invalid = $service->previewImport([$rows[0], $rows[0]]);
verify(!$invalid['valid'] && count($invalid['errors']) === 1, 'Reject duplicate SKU in one import');

$badPrice = $rows[0];
$badPrice['prix_ht'] = '-2';
verify(!$service->previewImport([$badPrice])['valid'], 'Reject invalid product price');

$applied = $service->commitImport($rows);
verify($applied['imported'] === 3 && count($service->listItems()) === 3, 'Commit three catalog references');

$second = $service->previewImport($rows);
verify($second['counts']['update'] === 3, 'Re-import previews updates instead of duplicates');

$again = $service->commitImport($rows);
verify($again['imported'] === 3 && count($service->listItems()) === 3, 'SKU upsert remains idempotent');

$tools = require $root . '/app/Mcp/tools.php';
$server = new \App\Mcp\Server($tools);
$listed = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
$names = array_column($listed['result']['tools'], 'name');
verify(in_array('catalog_list', $names, true) && in_array('catalog_preview_import', $names, true), 'Discover catalog MCP tools');

$call = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"catalog_list"}}');
$content = json_decode($call['result']['content'][0]['text'] ?? '', true);
verify(count($content['items'] ?? []) === 3, 'MCP adapter reads business catalog service');

$blocked = $server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"catalog_commit_import","arguments":{"confirmed":true,"rows":[]}}}');
verify(($blocked['result']['isError'] ?? false) === true, 'MCP mutations disabled by default');

echo "Artisan SaaS end-to-end smoke tests: PASS\n";
