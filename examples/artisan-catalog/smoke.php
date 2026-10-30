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
verify(!in_array('catalog_commit_import', $names, true), 'No MCP write tool is advertised');
verify(($blocked['error']['code'] ?? null) === -32602, 'MCP write tool calls are denied');

// Test private OAuth with deterministic, fake credentials. Never store real
// credentials in source control or GitHub Action logs.
$_ENV['MCP_OAUTH_CLIENT_ID'] = 'mcp-test-client';
$_ENV['MCP_OAUTH_CLIENT_SECRET'] = str_repeat('test', 16);
$_ENV['MCP_OAUTH_REDIRECT_URI'] = 'https://example.test/oauth/callback';
$oauth = new \App\Mcp\OAuthStore();
verify(\App\Mcp\OAuthStore::enabled(), 'OAuth provider requires explicit configuration');
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authRequest = [
    'client_id' => 'mcp-test-client',
    'redirect_uri' => 'https://example.test/oauth/callback',
    'response_type' => 'code',
    'code_challenge_method' => 'S256',
    'code_challenge' => $challenge,
    'scope' => 'catalog:read',
    'state' => 'test-random-state',
];
verify(\App\Mcp\OAuthStore::isValidRequest($authRequest), 'Valid authorization request with PKCE');
verify(!\App\Mcp\OAuthStore::isValidRequest(array_replace($authRequest, ['redirect_uri' => 'https://evil.example/'])), 'Reject unapproved OAuth redirect');

verify(\App\Mcp\OAuthStore::authenticateClient('mcp-test-client', str_repeat('test', 16)), 'Authenticate static OAuth client');
verify(!\App\Mcp\OAuthStore::authenticateClient('mcp-test-client', 'wrong'), 'Reject wrong OAuth client secret');
$code = $oauth->authorize(1, 'mcp-test-client', 'https://example.test/oauth/callback', $challenge);
try {
    $oauth->exchange($code, 'mcp-test-client', 'https://example.test/oauth/callback', str_repeat('X', 43));
    throw new RuntimeException('Invalid PKCE verifier accepted');
} catch (\InvalidArgumentException) {
    verify(true, 'Reject incorrect PKCE verifier');
}
$tokens = $oauth->exchange($code, 'mcp-test-client', 'https://example.test/oauth/callback', $verifier);
verify($tokens['scope'] === 'catalog:read' && strlen($tokens['access_token']) > 40, 'Issue OAuth access token with read-only scope');
verify($oauth->principal($tokens['access_token'])['admin_id'] === 1, 'Validate authorized OAuth token');
try {
    $oauth->exchange($code, 'mcp-test-client', 'https://example.test/oauth/callback', $verifier);
    throw new RuntimeException('Reused authorization code accepted');
} catch (\InvalidArgumentException) {
    verify(true, 'Prevent authorization code reuse');
}
$rotated = $oauth->refresh($tokens['refresh_token'], 'mcp-test-client');
verify($oauth->principal($tokens['access_token']) === null, 'Revoke access token when refreshing');
verify($oauth->principal($rotated['access_token'])['scope'] === 'catalog:read', 'Rotate refresh and access tokens');
try {
    $oauth->refresh($tokens['refresh_token'], 'mcp-test-client');
    throw new RuntimeException('Reused refresh token accepted');
} catch (\InvalidArgumentException) {
    verify(true, 'Prevent refresh token reuse');
}

echo "Artisan SaaS end-to-end smoke tests: PASS\n";

