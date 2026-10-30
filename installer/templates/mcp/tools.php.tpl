<?php

declare(strict_types=1);

// Register ONLY approved business capabilities here.
// Each handler calls the same service used by your UI and REST API.
// A CSV import is one possible business capability, not a special MCP feature.
return [
    'app_info' => [
        'description' => 'Read the application name and MCP availability.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ],
        'handler' => static fn (array $arguments): array => [
            'name' => {{project_name_php}},
            'mcp_enabled' => true,
        ],
    ],
];
