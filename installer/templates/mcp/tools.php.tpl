<?php

declare(strict_types=1);

use {{namespace}}\Mcp\CodeGraftFactory;
use {{namespace}}\Mcp\DynamicOAuth;
use {{namespace}}\Services\Database;
use {{namespace}}\Services\EvolutionRequestService;

// The principal comes ONLY from a validated OAuth access token.
$principal = $mcpPrincipal ?? null;
$allowedScopes = is_array($principal) ? ($principal['scopes'] ?? []) : [];
$adminId = is_array($principal) ? (int) ($principal['admin_id'] ?? 0) : 0;
$evolution = static fn (): EvolutionRequestService => new EvolutionRequestService(Database::connection());
$tools = [
    'app_info' => [
        'description' => 'Informations sur ce Flight Starter Kit V3 avec CodeGraft natif.',
        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
        'handler' => static fn (array $args): array => [
            'name' => {{project_name_php}},
            'flight_starter_version' => '3.0.0-alpha',
            'mcp_enabled' => true,
            'business_tools_enabled' => in_array(DynamicOAuth::READ, $allowedScopes, true),
            'code_deployment_enabled' => in_array(DynamicOAuth::DEPLOY, $allowedScopes, true) && CodeGraftFactory::deploymentAllowed(),
        ],
    ],
];

if (in_array(DynamicOAuth::EVOLVE, $allowedScopes, true) && $adminId > 0) {
    $tools['app_request_change'] = [
        'description' => 'Enregistre une demande d’amélioration de cette application. Aucun code n’est exécuté ni déployé par cet outil. Renvoie un numéro de suivi.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 140],
                'details' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 4000],
            ],
            'required' => ['summary', 'details'],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            $evolution()->request($adminId, (string) ($args['summary'] ?? ''), (string) ($args['details'] ?? '')),
    ];

    $tools['app_get_change_request'] = [
        'description' => 'Consulte le statut d’une demande d’évolution de l’application.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            ['request' => $evolution()->status($adminId, (int) ($args['id'] ?? 0))],
    ];

    $tools['app_list_change_requests'] = [
        'description' => 'Liste les demandes d’évolution du propriétaire de l’application.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            ['requests' => $evolution()->recent($adminId, (int) ($args['limit'] ?? 20))],
    ];
}

// Business tools are defined by the client project, not by the framework.
// Handlers must delegate to the same services as the web interface.
$businessTools = require __DIR__ . '/business_tools.php';
if (!is_array($businessTools)) {
    throw new \RuntimeException('MCP business tools must return an array.');
}
foreach ($businessTools as $name => $definition) {
    if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $name)
        || isset($tools[$name]) || !is_array($definition)) {
        throw new \RuntimeException('Invalid MCP business tool definition.');
    }
    $tools[$name] = $definition;
}

// Use the REAL CodeGraft engine instead of the V3 four-file CodeWorkspace.
// OAuth authorization is already checked by the Flight HttpEndpoint. Only a
// principal with app:deploy sees these nine CONSTANT tool definitions; live
// CodeGraft permissions are checked at EVERY call, no MCP refresh required.
if ($adminId > 0 && in_array(DynamicOAuth::DEPLOY, $allowedScopes, true)) {
    $codegraft = CodeGraftFactory::mcp();
    foreach (\CodeGraft\Mcp::definitions() as $definition) {
        $name = $definition['name'];
        $tools[$name] = [
            'description' => $definition['description'],
            'inputSchema' => $definition['inputSchema'],
            'handler' => static function (array $args) use ($codegraft, $name, $adminId): array {
                $started = microtime(true);
                $status = 'error';
                try {
                    if (in_array($name, [
                        'cg_prepare_patch', 'cg_validate_patch', 'cg_deploy_patch',
                        'cg_rollback_patch',
                    ], true) && !CodeGraftFactory::deploymentAllowed()) {
                        throw new \RuntimeException('Code updates require server switch and owner approval.');
                    }
                    $rpc = $codegraft->dispatch([
                        'jsonrpc' => '2.0', 'id' => 1,
                        'method' => 'tools/call',
                        'params' => ['name' => $name, 'arguments' => $args],
                    ]);
                    if (!is_array($rpc) || ($rpc['result']['isError'] ?? false)) {
                        $message = (string) ($rpc['result']['content'][0]['text'] ?? 'CodeGraft command failed.');
                        throw new \RuntimeException(substr($message, 0, 300));
                    }
                    $status = 'ok';
                    return $rpc['result']['structuredContent'] ?? [];
                } finally {
                    CodeGraftFactory::audit($name, $adminId, $status, (int) round((microtime(true) - $started) * 1000));
                }
            },
        ];
    }
}

return $tools;
