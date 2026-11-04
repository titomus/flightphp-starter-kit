<?php

declare(strict_types=1);

// Your client-specific FlightPHP routes belong here.
// Example (after creating app/Controllers/Business/OrdersController.php):
// \Flight::route('GET /orders', [new \{{namespace}}\Controllers\Business\OrdersController(), 'index']);

// All new business actions use this route, registered once. It dispatches to
// the same ActionRegistry service handler as the auto-generated MCP tool.
// Do not create duplicate POST handlers in this file for new user features.
\Flight::route('POST /business/actions/@name', [new \{{namespace}}\Controllers\Business\WebActionController(), 'run']);
