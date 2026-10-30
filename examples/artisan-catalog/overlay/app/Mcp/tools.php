<?php

declare(strict_types=1);

use App\Services\CatalogService;
use App\Services\Database;

$catalog = static fn (): CatalogService => new CatalogService(Database::connection());

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

// Read-only ChatGPT connection. The web admin keeps its existing confirmed
// CSV import workflow, but MCP never exposes commit / delete / mutation tools.
return [
    'app_info' => [
        'description' => 'Informations générales sur le logiciel artisan (lecture seule).',
        'inputSchema' => [
            'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array => [
            'name' => 'Atelier Catalogue',
            'mcp_enabled' => true,
            'access' => 'catalog:read',
        ],
    ],
    'catalog_list' => [
        'description' => 'Lister les références du catalogue (lecture seule).',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            ['items' => $catalog()->listItems((int) ($args['limit'] ?? 200))],
    ],
    'catalog_preview_import' => [
        'description' => 'Prévisualiser les créations, modifications et erreurs pour un lot de références. N’écrit aucune donnée.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['rows' => $rowsSchema],
            'required' => ['rows'],
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $args): array =>
            $catalog()->previewImport($args['rows'] ?? []),
    ],
];
