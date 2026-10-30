<?php

declare(strict_types=1);

// Separate public entry point; never grant repository or filesystem permissions
// to business tools. Configure a unique token for this application.
define('APP_CONTEXT', 'mcp');
require dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$token = (string) env('MCP_BEARER_TOKEN', '');
if (strlen($token) < 32) {
    http_response_code(503);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!is_string($auth) || !str_starts_with($auth, 'Bearer ')
    || !hash_equals($token, substr($auth, 7))) {
    header('WWW-Authenticate: Bearer');
    http_response_code(401);
    exit;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$expected = parse_url((string) env('APP_URL', ''), PHP_URL_SCHEME)
    . '://' . parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
$port = parse_url((string) env('APP_URL', ''), PHP_URL_PORT);
if ($port !== null) {
    $expected .= ':' . $port;
}
if ($origin !== '' && !hash_equals($expected, (string) $origin)) {
    http_response_code(403);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
    http_response_code(415);
    exit;
}

$body = file_get_contents('php://input', false, null, 0, 1048577);
if (!is_string($body) || strlen($body) > 1048576) {
    http_response_code(413);
    exit;
}

$tools = require dirname(__DIR__) . '/app/Mcp/tools.php';
$server = new \{{namespace}}\Mcp\Server($tools);
$result = $server->handle($body);
if ($result === null) {
    http_response_code(202);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
