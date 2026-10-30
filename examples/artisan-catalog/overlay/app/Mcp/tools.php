<?php

declare(strict_types=1);

use App\Mcp\DynamicOAuth;
use App\Mcp\CodeWorkspace;
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
            'code_changes' => 'privileged app:deploy scope; staged, verified and reversible',
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


// Optional business-only tool extensions; MCP transport and OAuth stay protected.
$extensions = require __DIR__ . '/business_tools.php';
if (!is_array($extensions)) {
    throw new \RuntimeException('Business extensions must return an array.');
}
foreach ($extensions as $name => $definition) {
    if (!is_string($name) || isset($tools[$name]) || !is_array($definition)) {
        throw new \RuntimeException('Invalid business tool extension.');
    }
    $tools[$name] = $definition;
}

// Separate OAuth grant: app:evolve only stores feature requests.
// app:deploy is a privileged code-deployment permission with explicit consent.
if ($adminId > 0 && in_array(DynamicOAuth::DEPLOY, $allowedScopes, true)) {
    $workspace = static fn (): CodeWorkspace => new CodeWorkspace();
    $writeEnabled = static function (): void {
        if (!CodeWorkspace::enabled()) {
            throw new \RuntimeException('Code updates disabled. Set ATELIER_CODE_UPDATES_ENABLED=true in the private .env file.');
        }
    };
    $fileSchema = [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'maxLength' => 140],
            'expected_sha256' => ['type' => 'string', 'description' => 'SHA-256 from dev_read_file, or empty string for a new extension'],
            'content' => ['type' => 'string', 'maxLength' => 70000],
        ],
        'required' => ['path', 'expected_sha256', 'content'],
        'additionalProperties' => false,
    ];
    $versionSchema = ['type' => 'string', 'pattern' => '^[a-f0-9]{32}
    $tools['dev_list_files'] = [
        'description' => 'Lists narrowly permitted business source files, their existence and SHA-256. No access to credentials or framework core.',
        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
        'handler' => static fn (array $args): array => ['files' => $workspace()->listFiles(), 'enabled' => CodeWorkspace::enabled()],
    ];
    $tools['dev_read_file'] = [
        'description' => 'Reads one explicitly allowlisted business source file for a proposed change.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'path' => ['type' => 'string'],
        ], 'required' => ['path'], 'additionalProperties' => false],
        'handler' => static fn (array $args): array => $workspace()->readFile((string) ($args['path'] ?? '')),
    ];
    $tools['dev_prepare_patch'] = [
        'description' => 'Stages complete replacement contents for 1-5 allowlisted files, verifying old SHA-256 and parsing PHP. Does not alter active files.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'summary' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 140],
            'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => $fileSchema],
        ], 'required' => ['summary', 'files'], 'additionalProperties' => false],
        'handler' => static function (array $args) use ($workspace, $adminId, $writeEnabled): array {
            $writeEnabled();
            return $workspace()->prepare($adminId, (string) ($args['summary'] ?? ''), $args['files'] ?? []);
        },
    ];
    $tools['dev_validate_patch'] = [
        'description' => 'Rechecks staged PHP syntax and detects file hash conflicts before deployment. Does not execute code or change files.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'id' => $versionSchema,
        ], 'required' => ['id'], 'additionalProperties' => false],
        'handler' => static fn (array $args): array => $workspace()->validate($adminId, (string) ($args['id'] ?? '')),
    ];
    $tools['dev_deploy'] = [
        'description' => 'HIGH RISK: executes privileged PHP after deployment. Requires explicit human approval, confirmed=true, app:deploy scope, enabled setting and validated snapshot. Changes only allowlisted business files; provides rollback ID.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'id' => $versionSchema,
            'confirmed' => ['type' => 'boolean'],
        ], 'required' => ['id', 'confirmed'], 'additionalProperties' => false],
        'handler' => static function (array $args) use ($workspace, $adminId, $writeEnabled): array {
            $writeEnabled();
            return $workspace()->deploy($adminId, (string) ($args['id'] ?? ''), ($args['confirmed'] ?? false) === true);
        },
    ];
    $tools['dev_rollback'] = [
        'description' => 'Restores original source files from immutable private release snapshot; requires explicit confirmation, refuses conflicting newer changes.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'id' => $versionSchema,
            'confirmed' => ['type' => 'boolean'],
        ], 'required' => ['id', 'confirmed'], 'additionalProperties' => false],
        'handler' => static function (array $args) use ($workspace, $adminId): array {
            return $workspace()->rollback($adminId, (string) ($args['id'] ?? ''), ($args['confirmed'] ?? false) === true);
        },
    ];
    $tools['dev_history'] = [
        'description' => 'Lists previous deployments, snapshots and rollback states.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
        ], 'additionalProperties' => false],
        'handler' => static fn (array $args): array => ['releases' => $workspace()->history($adminId, (int) ($args['limit'] ?? 20))],
    ];
}

return $tools;
];
    $tools['dev_list_files'] = [
        'description' => 'Lists narrowly permitted business source files, their existence and SHA-256. No access to credentials or framework core.',
        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
        'handler' => static fn (array $args): array => ['files' => $workspace()->listFiles(), 'enabled' => CodeWorkspace::enabled()],
    ];
    $tools['dev_read_file'] = [
        'description' => 'Reads one explicitly allowlisted business source file for a proposed change.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'path' => ['type' => 'string'],
        ], 'required' => ['path'], 'additionalProperties' => false],
        'handler' => static fn (array $args): array => $workspace()->readFile((string) ($args['path'] ?? '')),
    ];
    $tools['dev_prepare_patch'] = [
        'description' => 'Stages complete replacement contents for 1-5 allowlisted files, verifying old SHA-256 and parsing PHP. Does not alter active files.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'summary' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 140],
            'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => $fileSchema],
        ], 'required' => ['summary', 'files'], 'additionalProperties' => false],
        'handler' => static function (array $args) use ($workspace, $adminId, $writeEnabled): array {
            $writeEnabled();
            return $workspace()->prepare($adminId, (string) ($args['summary'] ?? ''), $args['files'] ?? []);
        },
    ];
    $tools['dev_validate_patch'] = [
        'description' => 'Rechecks staged PHP syntax and detects file hash conflicts before deployment. Does not execute code or change files.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'id' => $versionSchema,
        ], 'required' => ['id'], 'additionalProperties' => false],
        'handler' => static fn (array $args): array => $workspace()->validate($adminId, (string) ($args['id'] ?? '')),
    ];
    $tools['dev_deploy'] = [
        'description' => 'HIGH RISK: executes privileged PHP after deployment. Requires explicit human approval, confirmed=true, app:deploy scope, enabled setting and validated snapshot. Changes only allowlisted business files; provides rollback ID.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'id' => $versionSchema,
            'confirmed' => ['type' => 'boolean'],
        ], 'required' => ['id', 'confirmed'], 'additionalProperties' => false],
        'handler' => static function (array $args) use ($workspace, $adminId, $writeEnabled): array {
            $writeEnabled();
            return $workspace()->deploy($adminId, (string) ($args['id'] ?? ''), ($args['confirmed'] ?? false) === true);
        },
    ];
    $tools['dev_rollback'] = [
        'description' => 'Restores original source files from immutable private release snapshot; requires explicit confirmation, refuses conflicting newer changes.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'id' => $versionSchema,
            'confirmed' => ['type' => 'boolean'],
        ], 'required' => ['id', 'confirmed'], 'additionalProperties' => false],
        'handler' => static function (array $args) use ($workspace, $adminId): array {
            return $workspace()->rollback($adminId, (string) ($args['id'] ?? ''), ($args['confirmed'] ?? false) === true);
        },
    ];
    $tools['dev_history'] = [
        'description' => 'Lists previous deployments, snapshots and rollback states.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
        ], 'additionalProperties' => false],
        'handler' => static fn (array $args): array => ['releases' => $workspace()->history($adminId, (int) ($args['limit'] ?? 20))],
    ];
}

return $tools;
