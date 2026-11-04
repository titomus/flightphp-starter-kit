<?php
declare(strict_types=1);

namespace {{namespace}}\Actions;

/**
 * One business action contract -> web JSON endpoint AND MCP tool.
 *
 * Controllers/UI and MCP delegates call the SAME handler and authorizer.
 * Deliberately fail closed: explicit action definitions, per-action ACL, scopes,
 * JSON shape validation, and no automatic reflection of arbitrary PHP methods.
 */
final class BusinessActionRegistry
{
    private array $actions;

    public function __construct(array $definitions)
    {
        $this->actions = [];
        if (count($definitions) > 200) {
            throw new \InvalidArgumentException('Too many business actions.');
        }

        foreach ($definitions as $name => $action) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{2,54}$/D', $name) !== 1
                || !is_array($action)
                || !in_array($action['mode'] ?? null, ['read', 'write'], true)
                || ($action['web'] ?? null) !== true || ($action['mcp'] ?? null) !== true
                || !array_key_exists('tenant_scoped', $action) || !is_bool($action['tenant_scoped'])
                || !is_string($action['description'] ?? null)
                || strlen(trim($action['description'])) < 8
                || strlen($action['description']) > 240
                || !($action['handler'] ?? null) instanceof \Closure
                || !($action['authorize'] ?? null) instanceof \Closure
                || !is_array($action['inputSchema'] ?? null)) {
                throw new \InvalidArgumentException('Invalid web/MCP business action contract: ' . (string) $name);
            }
            $schema = $action['inputSchema'];
            if (($schema['type'] ?? '') !== 'object'
                || !is_array($schema['properties'] ?? null)
                || ($schema['additionalProperties'] ?? null) !== false
                || !is_array($schema['required'] ?? null)
                || array_diff($schema['required'], array_keys($schema['properties'])) !== []
                || count(array_unique($schema['required'])) !== count($schema['required'])) {
                throw new \InvalidArgumentException('Business action requires a strict JSON object schema.');
            }
            $this->validateSchemaDefinition($schema);
            $this->actions[$name] = $action;
        }
    }

    public static function fromApplication(): self
    {
        $file = __DIR__ . '/business.php';
        $definitions = require $file;
        if (!is_array($definitions)) {
            throw new \RuntimeException('Business action registry must return an array.');
        }
        return new self($definitions);
    }

    public function report(): array
    {
        $rows = [];
        foreach ($this->actions as $name => $action) {
            $rows[] = [
                'name' => $name,
                'tool' => 'business_' . $name,
                'description' => $action['description'],
                'mode' => $action['mode'],
                'web' => true,
                'mcp' => true,
                'tenant_scoped' => $action['tenant_scoped'],
                'parity' => true,
            ];
        }
        return ['count' => count($rows), 'paired' => count($rows), 'actions' => $rows];
    }

    /**
     * Called ONLY after authenticating the OAuth Bearer. The tool catalogue
     * includes permitted OAuth scopes, but the action ACL is checked on call.
     */
    public function mcpTools(array $verifiedPrincipal): array
    {
        $actor = ActionContext::fromVerifiedOAuth($verifiedPrincipal);
        $tools = [];
        foreach ($this->actions as $name => $action) {
            $scope = $action['mode'] === 'write' ? 'business:write' : 'business:read';
            if (!$actor->hasScope($scope)) {
                continue;
            }
            $tools['business_' . $name] = [
                'description' => $action['description'],
                'inputSchema' => $action['inputSchema'],
                'handler' => fn (array $arguments): array => $this->invoke($name, $arguments, $actor),
            ];
        }
        return $tools;
    }

    public function invoke(string $name, array $arguments, ActionContext $actor): array
    {
        $action = $this->actions[$name] ?? null;
        if ($action === null) {
            throw new \InvalidArgumentException('Unknown business action.');
        }
        if (count($arguments) > 50 || strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > 65536) {
            throw new \InvalidArgumentException('Business action arguments are too large.');
        }
        $scope = $action['mode'] === 'write' ? 'business:write' : 'business:read';
        if ($actor->channel === 'mcp' && !$actor->hasScope($scope)) {
            throw new \DomainException('OAuth scope denied.');
        }
        // Tenant-scoped actions have no ambient or user-supplied tenant fallback.
        // The application MUST resolve the tenant from the authenticated actor.
        if ($action['tenant_scoped']) {
            $actor->requireTenant();
        }
        // Never pass unvalidated user inputs to an authorization callback.
        $this->validateObject($arguments, $action['inputSchema'], 'arguments');
        if (($action['authorize'])($actor, $arguments) !== true) {
            throw new \DomainException('Business access denied.');
        }
        $result = ($action['handler'])($actor, $arguments);
        if (!is_array($result)) {
            throw new \RuntimeException('Business action handler must return an array.');
        }
        return $result;
    }

    private function validateSchemaDefinition(array $schema, int $depth = 0): void
    {
        if ($depth > 5) throw new \InvalidArgumentException('Business schema is too deep.');
        $type = $schema['type'] ?? null;
        if (!in_array($type, ['object', 'array', 'string', 'integer', 'number', 'boolean'], true)) {
            throw new \InvalidArgumentException('Unsupported action schema type.');
        }
        if ($type === 'object') {
            if (!is_array($schema['properties'] ?? null) || count($schema['properties']) > 50
                || ($schema['additionalProperties'] ?? null) !== false
                || !is_array($schema['required'] ?? null)) {
                throw new \InvalidArgumentException('Objects require strict properties and required arrays.');
            }
            foreach ($schema['properties'] as $key => $child) {
                if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $key) !== 1
                    || !is_array($child)) throw new \InvalidArgumentException('Invalid property.');
                $this->validateSchemaDefinition($child, $depth + 1);
            }
        }
        if ($type === 'array') {
            if (!is_array($schema['items'] ?? null) || !is_int($schema['maxItems'] ?? null)
                || $schema['maxItems'] < 0 || $schema['maxItems'] > 500) {
                throw new \InvalidArgumentException('Arrays require items schema and maxItems <= 500.');
            }
            $this->validateSchemaDefinition($schema['items'], $depth + 1);
        }
        if ($type === 'string' && (!is_int($schema['maxLength'] ?? null)
            || $schema['maxLength'] < 1 || $schema['maxLength'] > 10000)) {
            throw new \InvalidArgumentException('Strings require maxLength <= 10000.');
        }
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems'] as $limit) {
            if (isset($schema[$limit]) && (!is_numeric($schema[$limit]) || $schema[$limit] < 0)) {
                throw new \InvalidArgumentException('Invalid schema limit: ' . $limit);
            }
        }
    }

    private function validateObject(array $args, array $schema, string $path): void
    {
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $args)) {
                throw new \InvalidArgumentException('Missing required field: ' . $path . '.' . $key);
            }
        }
        foreach ($args as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $schema['properties'] ?? [])) {
                throw new \InvalidArgumentException('Unexpected business action field.');
            }
            $this->validateValue($value, $schema['properties'][$key], $path . '.' . $key);
        }
    }

    private function validateValue(mixed $value, array $schema, string $path): void
    {
        $type = $schema['type'];
        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
        };
        if (!$valid) throw new \InvalidArgumentException('Invalid type: ' . $path);
        if ($type === 'object') {
            $this->validateObject($value, $schema, $path);
        } elseif ($type === 'array') {
            if (count($value) > $schema['maxItems'] || count($value) < ($schema['minItems'] ?? 0)) {
                throw new \InvalidArgumentException('Array length invalid: ' . $path);
            }
            foreach ($value as $child) $this->validateValue($child, $schema['items'], $path . '[]');
        } elseif ($type === 'string') {
            $length = mb_strlen($value);
            if ($length > $schema['maxLength'] || $length < ($schema['minLength'] ?? 0)) {
                throw new \InvalidArgumentException('String length invalid: ' . $path);
            }
        } elseif ($type === 'integer' || $type === 'number') {
            if ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF)) {
                throw new \InvalidArgumentException('Number out of bounds: ' . $path);
            }
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new \InvalidArgumentException('Invalid enum: ' . $path);
        }
    }
}
