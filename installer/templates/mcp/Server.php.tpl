<?php

declare(strict_types=1);

namespace {{namespace}}\Mcp;

/**
 * Small JSON-RPC MCP dispatcher. Tools delegate to application services.
 * Transport and authentication are handled by public/mcp.php.
 */
final class Server
{
    public function __construct(private readonly array $tools)
    {
    }

    public function handle(string $body): ?array
    {
        try {
            $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(null, -32700, 'Parse error');
        }

        if (!is_array($request) || array_is_list($request)
            || ($request['jsonrpc'] ?? null) !== '2.0'
            || !is_string($request['method'] ?? null)) {
            return $this->error($request['id'] ?? null, -32600, 'Invalid Request');
        }

        // JSON-RPC notifications have no response.
        if (!array_key_exists('id', $request)) {
            return null;
        }

        $id = $request['id'];
        if (!is_string($id) && !is_int($id) && $id !== null) {
            return $this->error(null, -32600, 'Invalid request id');
        }

        return match ($request['method']) {
            'initialize' => $this->success($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'flightphp-starter-mcp', 'version' => '0.1.0'],
            ]),
            'ping' => $this->success($id, new \stdClass()),
            'tools/list' => $this->success($id, ['tools' => $this->definitions()]),
            'tools/call' => $this->callTool($id, $request['params'] ?? null),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    private function definitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $name => $tool) {
            $definitions[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }
        return $definitions;
    }

    private function callTool(string|int|null $id, mixed $params): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null)
            || !isset($this->tools[$params['name']])) {
            return $this->error($id, -32602, 'Unknown tool or invalid parameters');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->error($id, -32602, 'Arguments must be an object');
        }

        try {
            $handler = $this->tools[$params['name']]['handler'];
            $data = $handler($arguments);
            return $this->success($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]],
            ]);
        } catch (\Throwable $exception) {
            error_log('MCP tool error: ' . $exception->getMessage());
            return $this->success($id, [
                'content' => [['type' => 'text', 'text' => 'Tool execution failed']],
                'isError' => true,
            ]);
        }
    }

    private function success(string|int|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
