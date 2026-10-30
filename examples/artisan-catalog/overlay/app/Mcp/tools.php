<?php

declare(strict_types=1);

use App\Mcp\DynamicOAuth;
use App\Services\CatalogService;
use App\Services\Database;
use App\Services\EvolutionRequestService;

// This principal comes only from an authenticated and resource-bound OAuth token
// in public/mcp.php. User arguments NEVER determine the administrator ID.
$principal = $mcpPrincipal ?? null;
$allowedScopes = is_array($principal) ? ($principal['scopes'] ?? []) : [];
$adminId = is_array($principal) ? (int) ($principal['admin_id'] ?? 0) : 0;
$catalog = static fn (): CatalogService => new CatalogService(Database::connection());
$evolution = static fn (): EvolutionRequestService => new EvolutionRequestService(Database::connection());
$tools = [
    'app_info' => [
        'description' => 'Informations sur Atelier Catalogue et les fonctions MCP disponibles.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        'handler' => static fn (array $args): array => [
            'name' => 'Atelier Catalogue',
            'mcp_enabled' => true,
            'supports_change_requests' => true,
            'code_changes' => 'reviewed separately, never executed via public MCP',
        ],
    ],
];

$rowsSchema = [
    'type' => 'array',
    'minItems' => 1,
    'maxItems' => 500,
    'items' => [
        'type' => 'object',
        'properties' => [
            'reference' => ['type' => 'string'],
            'designation' => ['type' => 'string'],
            'prix_ht' => ['type' => 'string'],
            'stock' => ['type' => 'string'],
        ],
        'required' => ['reference', 'designation', 'prix_ht', 'stock'],
        'additionalProperties' => false,
    ],
];

if (in_array(DynamicOAuth::READ, $allowedScopes, true)) {
    $tools['catalog_list'] = [
        'description' => 'Liste les références du catalogue artisan.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            ['items' => $catalog()->listItems((int) ($args['limit'] ?? 200))],
    ];

    $tools['catalog_preview_import'] = [
        'description' => 'Prévisualise les créations, mises à jour et erreurs, sans modifier les données.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['rows' => $rowsSchema],
            'required' => ['rows'],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            $catalog()->previewImport($args['rows'] ?? []),
    ];
}

if (in_array(DynamicOAuth::WRITE, $allowedScopes, true)) {
    $tools['catalog_commit_import'] = [
        'description' => 'Ajoute ou met à jour des références du catalogue après validation. Utiliser catalog_preview_import avant de confirmer. Écriture en base.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'rows' => $rowsSchema,
                'confirmed' => ['type' => 'boolean', 'description' => 'True only after explicit human confirmation of the import'],
            ],
            'required' => ['rows', 'confirmed'],
            'additionalProperties' => false,
        ],
        'handler' => static function (array $args) use ($catalog): array {
            if (($args['confirmed'] ?? false) !== true) {
                throw new \InvalidArgumentException('Une validation humaine explicite est requise.');
            }
            return $catalog()->commitImport($args['rows'] ?? []);
        },
    ];
}

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

return $tools;
