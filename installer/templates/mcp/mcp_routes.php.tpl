<?php

declare(strict_types=1);

// Generated FlightPHP routes. No direct public/mcp.php entrypoint: compatible
// with shared hosting configurations that block direct PHP file requests.
\Flight::route('GET /.well-known/oauth-authorization-server', [new \{{namespace}}\Controllers\McpOAuthController(), 'metadata']);
\Flight::route('GET /.well-known/oauth-protected-resource', [new \{{namespace}}\Controllers\McpOAuthController(), 'protectedResource']);
\Flight::route('GET /.well-known/oauth-protected-resource/mcp', [new \{{namespace}}\Controllers\McpOAuthController(), 'protectedResource']);
\Flight::route('GET /oauth/authorize', [new \{{namespace}}\Controllers\McpOAuthController(), 'authorize']);
\Flight::route('POST /oauth/authorize', [new \{{namespace}}\Controllers\McpOAuthController(), 'authorize']);
\Flight::route('POST /oauth/token', [new \{{namespace}}\Controllers\McpOAuthController(), 'token']);
\Flight::route('POST /oauth/register', [new \{{namespace}}\Controllers\McpOAuthController(), 'register']);
\Flight::route('GET /mcp', [new \{{namespace}}\Mcp\HttpEndpoint(), 'handle']);
\Flight::route('POST /mcp', [new \{{namespace}}\Mcp\HttpEndpoint(), 'handle']);
