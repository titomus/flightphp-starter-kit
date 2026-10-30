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

// OAuth-based MCP capabilities are bound to a verified principal, never to
// user-supplied arguments or a public, arbitrary file-write method.
$mcpPrincipal = ['admin_id' => 1, 'scopes' => ['catalog:read']];
$tools = require $root . '/app/Mcp/tools.php';
$server = new \App\Mcp\Server($tools);
$listed = $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
$names = array_column($listed['result']['tools'], 'name');
verify(in_array('catalog_list', $names, true) && in_array('catalog_preview_import', $names, true), 'Read-only MCP tools are discoverable');
verify(!in_array('catalog_commit_import', $names, true), 'Catalog writes require catalog:write scope');
verify(!in_array('app_request_change', $names, true), 'Evolution requests require app:evolve scope');

$read = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"catalog_list"}}');
$items = json_decode($read['result']['content'][0]['text'] ?? '', true);
verify(count($items['items'] ?? []) === 3, 'Read catalog through authenticated MCP business adapter');

$denied = $server->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"catalog_commit_import","arguments":{"confirmed":true,"rows":[]}}}');
verify(($denied['error']['code'] ?? null) === -32602, 'Unauthorized tool calls are refused');

$mcpPrincipal = ['admin_id' => 1, 'scopes' => ['catalog:read', 'catalog:write', 'app:evolve']];
$tools = require $root . '/app/Mcp/tools.php';
$server = new \App\Mcp\Server($tools);
$listed = $server->handle('{"jsonrpc":"2.0","id":4,"method":"tools/list"}');
$names = array_column($listed['result']['tools'], 'name');
verify(in_array('catalog_commit_import', $names, true), 'Catalog writes available only with write scope');
verify(in_array('app_request_change', $names, true), 'Evolution request tool available with app:evolve scope');
verify(!in_array('write_file', $names, true) && !in_array('run_shell', $names, true), 'No generic file or command execution tools');

$importNoConsent = $server->handle(json_encode([
    'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
    'params' => ['name' => 'catalog_commit_import', 'arguments' => ['rows' => $rows, 'confirmed' => false]],
], JSON_THROW_ON_ERROR));
verify(($importNoConsent['result']['isError'] ?? false) === true, 'Import requires explicit confirmation');

$importApproved = $server->handle(json_encode([
    'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
    'params' => ['name' => 'catalog_commit_import', 'arguments' => ['rows' => $rows, 'confirmed' => true]],
], JSON_THROW_ON_ERROR));
$importResult = json_decode($importApproved['result']['content'][0]['text'] ?? '', true);
verify(($importResult['imported'] ?? 0) === 3, 'Authorized MCP catalog write uses existing domain service');

$change = $server->handle(json_encode([
    'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
    'params' => ['name' => 'app_request_change', 'arguments' => [
        'summary' => 'Ajouter la fiche fournisseur',
        'details' => 'Je souhaite rattacher chaque référence à un fournisseur et retrouver son prix d’achat depuis le catalogue.',
    ]],
], JSON_THROW_ON_ERROR));
$changeRecord = json_decode($change['result']['content'][0]['text'] ?? '', true);
verify(($changeRecord['status'] ?? '') === 'requested' && (int) ($changeRecord['id'] ?? 0) > 0, 'AI can submit an evolution request without developer account');
$service = new \App\Services\EvolutionRequestService(\App\Services\Database::connection());
verify($service->status(1, (int) $changeRecord['id']) !== null, 'Owner can track evolution request');
verify($service->status(2, (int) $changeRecord['id']) === null, 'Another admin cannot see request');

// Dynamic OAuth client registration: no manual client ID, client secret or
// GitHub account is needed. ChatGPT receives a public client ID automatically.
$oauth = new \App\Mcp\DynamicOAuth();
$redirect = 'https://chatgpt.com/connector/oauth/artisan-test';
$client = $oauth->register([
    'redirect_uris' => [$redirect],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => 'none',
]);
verify(isset($client['client_id']) && !isset($client['client_secret']), 'Dynamic registration returns a public PKCE client without a secret');
try {
    $oauth->register(['redirect_uris' => ['https://attacker.example/callback']]);
    throw new RuntimeException('Untrusted redirect was accepted');
} catch (\InvalidArgumentException) {
    verify(true, 'DCR refuses untrusted callback domains');
}

$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$authRequest = [
    'client_id' => $client['client_id'],
    'redirect_uri' => $redirect,
    'response_type' => 'code',
    'code_challenge_method' => 'S256',
    'code_challenge' => $challenge,
    'scope' => 'catalog:read catalog:write app:evolve',
    'state' => 'random-nonce',
    'resource' => \App\Mcp\DynamicOAuth::resource(),
];
$validated = $oauth->verifyAuthorizationRequest($authRequest);
verify(is_array($validated), 'DCR authorization validates registered callback, scopes and PKCE');
verify($oauth->verifyAuthorizationRequest(array_replace($authRequest, ['redirect_uri' => 'https://attacker.example/callback'])) === null, 'OAuth rejects redirect substitution');

$code = $oauth->authorize(1, $validated);
try {
    $oauth->exchange([
        'client_id' => $client['client_id'], 'redirect_uri' => $redirect,
        'code' => $code, 'code_verifier' => str_repeat('Z', 43),
        'resource' => \App\Mcp\DynamicOAuth::resource(),
    ]);
    throw new RuntimeException('Wrong PKCE verifier was accepted');
} catch (\InvalidArgumentException) {
    verify(true, 'OAuth rejects incorrect PKCE verifier');
}
$token = $oauth->exchange([
    'client_id' => $client['client_id'], 'redirect_uri' => $redirect,
    'code' => $code, 'code_verifier' => $verifier,
    'resource' => \App\Mcp\DynamicOAuth::resource(),
]);
$principal = $oauth->principal($token['access_token']);
verify(is_array($principal) && in_array('app:evolve', $principal['scopes'], true), 'OAuth token grants approved business and evolution scopes');
try {
    $oauth->exchange([
        'client_id' => $client['client_id'], 'redirect_uri' => $redirect,
        'code' => $code, 'code_verifier' => $verifier,
    ]);
    throw new RuntimeException('OAuth authorization code was reused');
} catch (\InvalidArgumentException) {
    verify(true, 'OAuth authorization codes are single-use');
}
$rotated = $oauth->refresh(['client_id' => $client['client_id'], 'refresh_token' => $token['refresh_token']]);
verify($oauth->principal($token['access_token']) === null, 'Refreshing revokes the previous access token');
verify(is_array($oauth->principal($rotated['access_token'])), 'Refresh issues a valid new access token');
try {
    $oauth->refresh(['client_id' => $client['client_id'], 'refresh_token' => $token['refresh_token']]);
    throw new RuntimeException('OAuth refresh token was reused');
} catch (\InvalidArgumentException) {
    verify(true, 'Refresh tokens are single-use');
}

echo "Artisan SaaS DCR, MCP business writes and evolution-request smoke tests: PASS\n";
