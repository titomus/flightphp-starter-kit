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
// Assert JSON wire types, not just PHP arrays, because MCP discovery
// rejects [] where JSON Schema requires {}.
$wire = json_decode(json_encode($listed, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
verify(count($wire->result->tools) === 7, 'All seven authorized MCP tools are listed');
foreach ($wire->result->tools as $toolDefinition) {
    verify(is_object($toolDefinition->inputSchema)
        && is_object($toolDefinition->inputSchema->properties),
        'MCP ' . $toolDefinition->name . ' has JSON object inputSchema and properties');
}
$synthetic = new \App\Mcp\Server([
    'empty_schema' => [
        'description' => 'Regression for empty JSON Schema properties',
        'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
        'handler' => static fn (array $args): array => $args,
    ],
    'nested_empty' => [
        'description' => 'Regression for nested JSON Schema objects',
        'inputSchema' => [
            'type' => 'object', 'properties' => [
                'child' => ['type' => 'object', 'properties' => []],
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]],
            ],
            'required' => ['child'],
        ],
        'handler' => static fn (array $args): array => $args,
    ],
]);
$syntheticWire = json_decode(json_encode(
    $synthetic->handle('{"jsonrpc":"2.0","id":88,"method":"tools/list"}'),
    JSON_THROW_ON_ERROR
), false, 512, JSON_THROW_ON_ERROR);
$emptySchema = $syntheticWire->result->tools[0]->inputSchema;
verify(is_object($emptySchema->properties) && is_array($emptySchema->required)
    && count($emptySchema->required) === 0,
    'Empty properties encode as {}, while empty required encodes as []');
$nested = $syntheticWire->result->tools[1]->inputSchema;
verify(is_object($nested->properties->child->properties)
    && is_object($nested->properties->rows->items->properties)
    && is_array($nested->required),
    'Nested empty properties remain {}, and required stays a JSON array');

$names = array_column($listed['result']['tools'], 'name');
verify(in_array('catalog_commit_import', $names, true), 'Catalog writes available only with write scope');
verify(in_array('app_request_change', $names, true), 'Evolution request tool available with app:evolve scope');
verify(!in_array('write_file', $names, true) && !in_array('run_shell', $names, true), 'No generic file or command execution tools');
verify(!isset($tools['dev_deploy']), 'Existing app:evolve users cannot deploy PHP without app:deploy scope');

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


// Code workspace: explicit OAuth permission, narrow paths, staged update,
// non-destructive backups, conflict guards and emergency rollback.
$mcpPrincipal = ['admin_id' => 1, 'scopes' => [
    'catalog:read', 'catalog:write', 'app:evolve', 'app:deploy',
]];
$tools = require $root . '/app/Mcp/tools.php';
verify(isset($tools['dev_list_files'], $tools['dev_read_file'], $tools['dev_prepare_patch'],
    $tools['dev_validate_patch'], $tools['dev_deploy'], $tools['dev_rollback'], $tools['dev_history']),
    'All seven GitHub-free code lifecycle MCP tools require app:deploy scope');

$devServer = new \App\Mcp\Server($tools);
$devList = $devServer->handle('{"jsonrpc":"2.0","id":100,"method":"tools/list"}');
$devWire = json_decode(json_encode($devList, JSON_THROW_ON_ERROR),
    false, 512, JSON_THROW_ON_ERROR);
verify(count($devWire->result->tools) === 14,
    'MCP discovery exposes all 14 business and privileged code tools');
foreach ($devWire->result->tools as $definition) {
    verify(is_object($definition->inputSchema)
        && is_object($definition->inputSchema->properties),
        'All MCP tool JSON schemas serialize as objects: ' . $definition->name);
}
$blocked = $devServer->handle('{"jsonrpc":"2.0","id":101,"method":"tools/call","params":{"name":"dev_read_file","arguments":{"path":"app/Mcp/DynamicOAuth.php"}}}');
verify(($blocked['result']['isError'] ?? false) === true
    && str_contains($blocked['result']['content'][0]['text'] ?? '', 'Protected'),
    'Privileged development errors are actionable but do not reveal protected source');

$workspace = new \App\Mcp\CodeWorkspace($root);
$files = $workspace->listFiles();
verify(count($files) >= 5, 'Code workspace enumerates business allowlisted files');
$original = $workspace->readFile('app/Mcp/business_tools.php');
verify(strlen($original['sha256']) === 64, 'Editable file read returns SHA-256 for optimistic lock');
try {
    $workspace->readFile('app/Mcp/DynamicOAuth.php');
    throw new RuntimeException('Sensitive OAuth code was exposed!');
} catch (\InvalidArgumentException) {
    verify(true, 'OAuth and MCP core code cannot be edited via code workspace');
}
$extension = 'app/Services/Extensions/AtelierSmokeExample.php';
$extensionSource = "<?php\n\nnamespace App\\Services\\Extensions;\nfinal class AtelierSmokeExample { public static function ping(): bool { return true; } }\n";
$draft = $workspace->prepare(1, 'Test versioned change and rollback without GitHub', [
    ['path' => 'app/Mcp/business_tools.php', 'expected_sha256' => $original['sha256'],
        'content' => $original['content'] . "\n// Atelier integration smoke test\n"],
    ['path' => $extension, 'expected_sha256' => '', 'content' => $extensionSource],
]);
verify($draft['status'] === 'prepared' && count($draft['files']) === 2,
    'Code workspace stages existing and new business files');
verify(hash_file('sha256', $root . '/app/Mcp/business_tools.php') === $original['sha256'],
    'Preparing code does not modify active business files');
$validation = $workspace->validate(1, $draft['id']);
verify($validation['valid'] === true && count($validation['checks']) === 2,
    'Prepared source syntax and original hashes validate');
try {
    $workspace->deploy(1, $draft['id'], false);
    throw new RuntimeException('Deployment without confirmation was accepted!');
} catch (\InvalidArgumentException) {
    verify(true, 'Deployment requires explicit confirmation');
}
$release = $workspace->deploy(1, $draft['id'], true);
verify($release['status'] === 'deployed' && is_file($root . '/' . $extension),
    'Confirmed deployment installs business extension and saves snapshot');
verify(count($workspace->history(1)) >= 1,
    'Deployment is visible in version history');
try {
    $workspace->rollback(1, $draft['id'], false);
    throw new RuntimeException('Rollback without confirmation was accepted!');
} catch (\InvalidArgumentException) {
    verify(true, 'Rollback requires explicit confirmation');
}
$restored = $workspace->rollback(1, $draft['id'], true);
verify($restored['status'] === 'rolled_back'
    && !file_exists($root . '/' . $extension)
    && hash_file('sha256', $root . '/app/Mcp/business_tools.php') === $original['sha256'],
    'Rollback restores original file byte-for-byte and removes newly created file');
try {
    $workspace->prepare(1, 'Reject invalid file path in code manager', [
        ['path' => 'app/Mcp/HttpEndpoint.php', 'expected_sha256' => '',
            'content' => "<?php echo 'unsafe';"],
    ]);
    throw new RuntimeException('Protected code was accepted!');
} catch (\InvalidArgumentException) {
    verify(true, 'Code workspace rejects protected application infrastructure');
}
try {
    $workspace->prepare(1, 'Reject syntactically invalid source code', [
        ['path' => 'app/Mcp/business_tools.php', 'expected_sha256' => $original['sha256'],
            'content' => "<?php function broken( {"],
    ]);
    throw new RuntimeException('Invalid PHP syntax was accepted!');
} catch (\InvalidArgumentException) {
    verify(true, 'Code workspace refuses malformed PHP before deployment');
}
try {
    $workspace->prepare(1, 'Reject stale file fingerprints for safe edits', [
        ['path' => 'app/Mcp/business_tools.php', 'expected_sha256' => str_repeat('a', 64),
            'content' => "<?php return [];"],
    ]);
    throw new RuntimeException('Stale SHA256 fingerprint was accepted!');
} catch (\InvalidArgumentException) {
    verify(true, 'Code workspace prevents overwriting unexpected file changes');
}

// Crash-recovery path: cannot rely on the MCP being operational.
// A fully deployed release can also be restored by the CLI-only fallback.
$originalRecovery = $workspace->readFile('app/Mcp/business_tools.php');
$emergencyDraft = $workspace->prepare(1, 'Exercise independent emergency code rollback', [
    ['path' => 'app/Mcp/business_tools.php',
        'expected_sha256' => $originalRecovery['sha256'],
        'content' => $originalRecovery['content'] . "\n// emergency recovery smoke test\n"],
]);
$workspace->deploy(1, $emergencyDraft['id'], true);
$emergency = $workspace->emergencyRecover(1, $emergencyDraft['id']);
verify($emergency['status'] === 'emergency_rolled_back'
    && hash_file('sha256', $root . '/app/Mcp/business_tools.php') === $originalRecovery['sha256'],
    'Standalone CLI recovery restores exact original even when MCP fails');

echo "Atelier versioned file update, strict permissions and rollback: PASS\n";

echo "Artisan SaaS DCR, MCP business writes and evolution-request smoke tests: PASS\n";
