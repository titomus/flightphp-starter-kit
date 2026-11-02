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
                'inputSchema' => $this->normalizeSchema($tool['inputSchema']),
            ];
        }
        return $definitions;
    }

    /**
     * JSON Schema objects must stay objects on the wire, even when empty.
     * PHP's json_encode([]) becomes [] rather than {}, which some MCP
     * clients reject during tool discovery. Preserve array-valued keywords
     * (required, enum, allOf/anyOf/oneOf) as JSON arrays.
     */
    private function normalizeSchema(array|object $schema): object
    {
        $fields = (array) $schema;
        if (isset($fields['properties'])) {
            $properties = (array) $fields['properties'];
            foreach ($properties as $name => $child) {
                if (is_array($child) || is_object($child)) {
                    $properties[$name] = $this->normalizeSchema($child);
                }
            }
            $fields['properties'] = (object) $properties;
        }
        if (isset($fields['$defs'])) {
            $definitions = (array) $fields['$defs'];
            foreach ($definitions as $name => $child) {
                if (is_array($child) || is_object($child)) {
                    $definitions[$name] = $this->normalizeSchema($child);
                }
            }
            $fields['$defs'] = (object) $definitions;
        }
        foreach (['items', 'additionalProperties', 'not', 'if', 'then', 'else'] as $key) {
            if (isset($fields[$key]) && (is_array($fields[$key]) || is_object($fields[$key]))) {
                $fields[$key] = $this->normalizeSchema($fields[$key]);
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            if (isset($fields[$key]) && is_array($fields[$key])) {
                $fields[$key] = array_map(
                    fn ($child) => is_array($child) || is_object($child)
                        ? $this->normalizeSchema($child) : $child,
                    $fields[$key]
                );
            }
        }
        return (object) $fields;
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
            // Privileged development tools need actionable validation failures.
            // Do not expose raw unexpected exceptions or internal paths.
            $message = 'Tool execution failed';
            if (str_starts_with((string) $params['name'], 'dev_')
                && ($exception instanceof \InvalidArgumentException
                    || $exception instanceof \RuntimeException)) {
                $message = substr($exception->getMessage(), 0, 300);
            }
            return $this->success($id, [
                'content' => [['type' => 'text', 'text' => $message]],
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
