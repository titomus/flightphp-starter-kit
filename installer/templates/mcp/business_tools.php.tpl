<?php

declare(strict_types=1);

// Per-client MCP business tool registry.
//
// The variables $allowedScopes, $adminId and $principal are derived from
// the verified OAuth token. Never take administrative identity from $args.
//
// Add your tools here and delegate to app/Services/Business/*.
// Gate read/write methods with DynamicOAuth::READ / WRITE respectively.
//
// Example:
// if (in_array(\{{namespace}}\Mcp\DynamicOAuth::READ, $allowedScopes, true)) {
//     return ['business_report' => [
//         'description' => 'Read client business data',
//         'inputSchema' => ['type'=>'object','properties'=>new \stdClass()],
//         'handler' => static fn (array $args): array => (new \{{namespace}}\Services\Business\ReportsService())->report(),
//     ]];
// }
return [];
