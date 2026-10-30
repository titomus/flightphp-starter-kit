<?php

declare(strict_types=1);

namespace App\Mcp;

/** Routed MCP endpoint. The front controller loads the application bootstrap. */
final class HttpEndpoint
{
    public function handle(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            header('Allow: POST');
            http_response_code(405);
            exit;
        }

        $expectedHost = parse_url(DynamicOAuth::baseUrl(), PHP_URL_HOST);
        $host = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''), 2)[0];
        if (!is_string($expectedHost) || !hash_equals($expectedHost, $host)) {
            http_response_code(403);
            exit;
        }

        // Reject browser cross-origin calls. Server-to-server OAuth requests usually
        // have no Origin header, which is acceptable for authenticated MCP traffic.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !hash_equals(DynamicOAuth::baseUrl(), rtrim($origin, '/'))) {
            http_response_code(403);
            exit;
        }

        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $bearer = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        try {
            $principal = (new DynamicOAuth())->principal($bearer);
        } catch (\Throwable $exception) {
            error_log('MCP token validation failure: ' . $exception->getMessage());
            http_response_code(503);
            exit;
        }

        if (!is_array($principal)) {
            header('WWW-Authenticate: Bearer resource_metadata="' . DynamicOAuth::baseUrl()
                . '/.well-known/oauth-protected-resource/mcp", scope="catalog:read catalog:write app:evolve"');
            http_response_code(401);
            exit;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (!str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
            http_response_code(406);
            exit;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            http_response_code(415);
            exit;
        }

        $version = (string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '2025-03-26');
        if (!in_array($version, ['2025-03-26', '2025-06-18', '2025-11-25'], true)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'unsupported MCP protocol version', 'supported' => ['2025-03-26', '2025-06-18', '2025-11-25']], JSON_THROW_ON_ERROR);
            exit;
        }

        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (!is_string($body) || strlen($body) > 1048576) {
            http_response_code(413);
            exit;
        }

        $mcpPrincipal = $principal;
        $tools = require __DIR__ . '/tools.php';
        $server = new \App\Mcp\Server($tools);
        $response = $server->handle($body);

        if ($response === null) {
            http_response_code(202);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    }
}
