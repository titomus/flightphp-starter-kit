

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
