

// Artisan catalog demo. Web access is restricted to authenticated admin sessions.
\Flight::route('GET /catalog', middleware([
    new \App\Middleware\AuthenticateMiddleware('admin'),
], [new \App\Controllers\CatalogController(), 'index']));

\Flight::route('POST /catalog/preview', middleware([
    new \App\Middleware\AuthenticateMiddleware('admin'),
    \App\Middleware\VerifyCsrfMiddleware::class,
], [new \App\Controllers\CatalogController(), 'preview']));

\Flight::route('POST /catalog/commit', middleware([
    new \App\Middleware\AuthenticateMiddleware('admin'),
    \App\Middleware\VerifyCsrfMiddleware::class,
], [new \App\Controllers\CatalogController(), 'commit']));

\Flight::route('POST /catalog/cancel', middleware([
    new \App\Middleware\AuthenticateMiddleware('admin'),
    \App\Middleware\VerifyCsrfMiddleware::class,
], [new \App\Controllers\CatalogController(), 'cancel']));


// OAuth 2.1-style authorization code with PKCE for read-only private MCP access.
// These endpoints do not expose the business catalog without authorization.
\Flight::route('GET /.well-known/oauth-authorization-server', [new \App\Controllers\McpOAuthController(), 'metadata']);
\Flight::route('GET /.well-known/oauth-protected-resource', [new \App\Controllers\McpOAuthController(), 'protectedResource']);
\Flight::route('GET /.well-known/oauth-protected-resource/mcp.php', [new \App\Controllers\McpOAuthController(), 'protectedResource']);
\Flight::route('GET /oauth/authorize', [new \App\Controllers\McpOAuthController(), 'authorize']);
\Flight::route('POST /oauth/authorize', [new \App\Controllers\McpOAuthController(), 'authorize']);
\Flight::route('POST /oauth/token', [new \App\Controllers\McpOAuthController(), 'token']);
\Flight::route('POST /oauth/register', [new \App\Controllers\McpOAuthController(), 'register']);
