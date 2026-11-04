<?php
declare(strict_types=1);

namespace CodeGraft;

use InvalidArgumentException;
use Throwable;

/**
 * Stateless MCP Streamable HTTP JSON-RPC endpoint.
 *
 * CRITICAL: tools/list is CONSTANT. Admin policy toggles are checked at each
 * tools/call; changing allowlists and permissions never requires refreshing
 * the ChatGPT connector or reinstalling a plugin.
 */
final class Mcp
{
    public const VERSION = '0.1.0';
    public const PROTOCOL = '2025-03-26';

    public function __construct(
        private readonly Vault $vault,
        private readonly Workspace $workspace
    ) {}

    public static function definitions(): array
    {
        $string = static fn (string $description): array => ['type' => 'string', 'description' => $description];
        $int = static fn (int $min = 1): array => ['type' => 'integer', 'minimum' => $min];
        $bool = ['type' => 'boolean'];
        $path = $string('Chemin relatif autorisé par l’administration CodeGraft.');
        $file = ['type' => 'object', 'properties' => [
            'path' => $path,
            'expected_sha256' => ['type' => 'string', 'description' => 'SHA-256 vu lors de la lecture, vide pour nouveau fichier.'],
            'content' => $string('Nouveau contenu complet du fichier.'),
        ], 'required' => ['path','expected_sha256','content'], 'additionalProperties' => false];
        $def = static function (string $name, string $description, array $properties = [], array $required = []): array {
            return ['name' => $name, 'description' => $description,
                'inputSchema' => ['type' => 'object',
                    'properties' => $properties === [] ? new \stdClass() : $properties,
                    'required' => $required, 'additionalProperties' => false]];
        };
        return [
            $def('cg_capabilities', 'Informations et droits activés actuellement. La liste des outils MCP ne change jamais.'),
            $def('cg_list_files', 'Liste les fichiers autorisés et leurs empreintes SHA-256.', ['limit' => $int()]),
            $def('cg_search', 'Recherche du texte dans les fichiers autorisés.', ['query' => $string('Texte recherché.'), 'limit' => $int()], ['query']),
            $def('cg_read_files', 'Lit jusqu’à 20 fichiers autorisés avec contenu et SHA-256.', [
                'paths' => ['type' => 'array', 'items' => $path, 'minItems' => 1, 'maxItems' => 20],
            ], ['paths']),
            $def('cg_prepare_patch', 'Prépare un changement sur 1 à 12 fichiers. Ne déploie rien.', [
                'summary' => $string('Résumé en français, 8 à 160 caractères.'),
                'files' => ['type' => 'array', 'items' => $file, 'minItems' => 1, 'maxItems' => 12],
            ], ['summary','files']),
            $def('cg_validate_patch', 'Vérifie conflits SHA-256 et syntaxe avant déploiement.', [
                'id' => $string('Identifiant de brouillon.'),
            ], ['id']),
            $def('cg_deploy_patch', 'Déploie un patch validé après confirmation humaine.', [
                'id' => $string('Identifiant de brouillon.'),
                'confirmed' => $bool,
            ], ['id','confirmed']),
            $def('cg_rollback_patch', 'Restaure une version, si les fichiers n’ont pas été modifiés entretemps.', [
                'id' => $string('Identifiant du déploiement.'),
                'confirmed' => $bool,
            ], ['id','confirmed']),
            $def('cg_history', 'Historique des versions et rollbacks.', ['limit' => $int()]),
        ];
    }

    public function dispatch(array $request, ?array $oauthPrincipal = null): ?array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        if (!is_string($method) || ($request['jsonrpc'] ?? null) !== '2.0') {
            return self::error($id, -32600, 'JSON-RPC 2.0 request required.');
        }
        if (str_starts_with($method, 'notifications/')) {
            return null;
        }
        if ($method === 'initialize') {
            return self::result($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'codegraft', 'version' => self::VERSION],
                'instructions' => 'Definitions are stable. Tool calls check live policy at execution time. Never assume code deployment is permitted.',
            ]);
        }
        if ($method === 'ping') return self::result($id, new \stdClass());
        if ($method === 'tools/list') return self::result($id, ['tools' => self::definitions()]);
        if ($method !== 'tools/call') return self::error($id, -32601, 'Unknown MCP method.');
        $params = $request['params'] ?? [];
        if (!is_array($params) || !is_string($params['name'] ?? null)) {
            return self::error($id, -32602, 'Invalid tool call.');
        }
        $name = $params['name'];
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) return self::error($id, -32602, 'Arguments must be an object.');
        $names = array_column(self::definitions(), 'name');
        if (!in_array($name, $names, true)) return self::error($id, -32602, 'Unknown tool.');
        if ($oauthPrincipal !== null && $name !== 'cg_capabilities') {
            $write = in_array($name, ['cg_prepare_patch', 'cg_validate_patch',
                'cg_deploy_patch', 'cg_rollback_patch'], true);
            $required = $write ? DynamicOAuth::WRITE : DynamicOAuth::READ;
            if (!in_array($required, $oauthPrincipal['scopes'] ?? [], true)) {
                return self::error($id, -32003, 'OAuth scope does not authorize this tool.');
            }
        }
        try {
            $payload = $this->invoke($name, $args);
            return self::result($id, [
                'content' => [['type' => 'text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
                'structuredContent' => $payload,
                'isError' => false,
            ]);
        } catch (Throwable $e) {
            return self::result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    private function invoke(string $tool, array $args): array
    {
        return match ($tool) {
            'cg_capabilities' => [
                'version' => self::VERSION,
                'connected' => true,
                'policy' => $this->publicPolicy(),
                'static_tools' => true,
                'reconnect_needed_for_policy_changes' => false,
            ],
            'cg_list_files' => $this->workspace->listFiles((int) ($args['limit'] ?? 80)),
            'cg_search' => $this->workspace->search($this->requiredString($args, 'query'), (int) ($args['limit'] ?? 30)),
            'cg_read_files' => $this->workspace->readFiles($this->requiredArray($args, 'paths')),
            'cg_prepare_patch' => $this->workspace->prepare(
                $this->requiredString($args, 'summary'), $this->requiredArray($args, 'files')),
            'cg_validate_patch' => $this->workspace->validate($this->requiredString($args, 'id')),
            'cg_deploy_patch' => $this->workspace->deploy(
                $this->requiredString($args, 'id'), ($args['confirmed'] ?? false) === true),
            'cg_rollback_patch' => $this->workspace->rollback(
                $this->requiredString($args, 'id'), ($args['confirmed'] ?? false) === true),
            'cg_history' => $this->workspace->history((int) ($args['limit'] ?? 20)),
            default => throw new InvalidArgumentException('Unknown CodeGraft action.'),
        };
    }

    private function publicPolicy(): array
    {
        $policy = $this->vault->policy();
        return [
            'enabled' => $policy['enabled'],
            'permissions' => $policy['permissions'],
            'read_dirs' => $policy['read_dirs'],
            'write_dirs' => $policy['write_dirs'],
            'updated_at' => $policy['updated_at'] ?? null,
        ];
    }

    private function requiredString(array $args, string $key): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value)) throw new InvalidArgumentException('Missing string: ' . $key);
        return $value;
    }

    private function requiredArray(array $args, string $key): array
    {
        $value = $args[$key] ?? null;
        if (!is_array($value)) throw new InvalidArgumentException('Missing array: ' . $key);
        return $value;
    }

    private static function result(mixed $id, array|\stdClass $value): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $value];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * Framework-neutral HTTP handler; no cookies, no session, no CORS.
     * Bearer token is held by the AI client, only SHA-256 stored server-side.
     */
    public function serve(?string $authorization = null): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/json; charset=utf-8');
        $authorization ??= $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $legacy = $this->vault->authenticate($authorization);
        $oauthPrincipal = null;
        $endpoint = $this->vault->json('endpoint.json', []);
        $resource = $endpoint['oauth_resource'] ?? null;
        $issuer = $endpoint['oauth_issuer'] ?? null;
        $actualPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $resourcePath = is_string($resource) ? parse_url($resource, PHP_URL_PATH) : null;
        if (!$legacy && is_string($resource) && is_string($issuer)
            && is_string($actualPath) && $actualPath === $resourcePath
            && is_string($authorization) && preg_match('/^Bearer (\S+)$/D', $authorization, $match)) {
            try {
                $oauthPrincipal = (new DynamicOAuth($this->vault))->principal($match[1]);
            } catch (\Throwable $error) {
                error_log('CodeGraft OAuth verification: ' . $error->getMessage());
                http_response_code(503);
                echo json_encode(['error' => 'oauth_temporarily_unavailable']);
                return;
            }
        }
        if (!$legacy && $oauthPrincipal === null) {
            http_response_code(401);
            if (is_string($issuer) && str_starts_with($issuer, 'https://')) {
                header('WWW-Authenticate: Bearer resource_metadata="' . rtrim($issuer, '/')
                    . '/.well-known/oauth-protected-resource/", scope="codegraft:read codegraft:write"');
            } else {
                header('WWW-Authenticate: Bearer realm="CodeGraft"');
            }
            echo json_encode(['error' => 'unauthorized']);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['error' => 'method_not_allowed']);
            return;
        }
        $input = file_get_contents('php://input');
        if (!is_string($input) || strlen($input) > 1800000) {
            http_response_code(413);
            echo json_encode(['error' => 'request_too_large']);
            return;
        }
        try {
            $decoded = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new InvalidArgumentException('Invalid request.');
            $output = $this->dispatch($decoded, $oauthPrincipal);
            if ($output === null) {
                http_response_code(202);
                echo '{}';
                return;
            }
            echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            http_response_code(400);
            echo json_encode(self::error(null, -32700, 'Invalid JSON-RPC request.'));
        }
    }
}
