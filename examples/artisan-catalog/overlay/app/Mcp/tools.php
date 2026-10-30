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
    ],
];

return [
    'app_info' => [
        'description' => 'Informations sur le logiciel artisan.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        'handler' => static fn (array $args): array => [
            'name' => 'Atelier Catalogue',
            'mcp_enabled' => true,
            'mode' => 'single-tenant demo',
        ],
    ],
    'catalog_list' => [
        'description' => 'Liste les références existantes du catalogue.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
        ],
        'handler' => static fn (array $args): array =>
            ['items' => $catalog()->listItems((int) ($args['limit'] ?? 200))],
    ],
    'catalog_preview_import' => [
        'description' => 'Prévisualise les créations, mises à jour et erreurs d’un import de références, sans écrire.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['rows' => $rowsSchema],
            'required' => ['rows'],
        ],
        'handler' => static fn (array $args): array =>
            $catalog()->previewImport($args['rows'] ?? []),
    ],
    'catalog_commit_import' => [
        'description' => 'Enregistre un import validé. Nécessite une confirmation explicite et le droit d’écriture MCP.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'rows' => $rowsSchema,
                'confirmed' => ['type' => 'boolean'],
            ],
            'required' => ['rows', 'confirmed'],
        ],
        'handler' => static function (array $args) use ($catalog): array {
            if (env('MCP_ALLOW_WRITES', 'false') !== 'true') {
                throw new RuntimeException('Écriture MCP désactivée');
            }
            if (($args['confirmed'] ?? false) !== true) {
                throw new RuntimeException('Confirmation explicite nécessaire');
            }
            return $catalog()->commitImport($args['rows'] ?? []);
        },
    ],
];
