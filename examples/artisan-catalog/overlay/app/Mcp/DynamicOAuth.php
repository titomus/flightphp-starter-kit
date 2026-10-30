<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Services\Database;
use InvalidArgumentException;
use PDO;

/**
 * Demo-only OAuth 2.1 + PKCE provider with Dynamic Client Registration.
 *
 * Public clients never require a shared secret; the administrator must
 * authenticate and consent before any token can access the private catalog.
 * Redirect URIs are restricted to ChatGPT's HTTPS callback endpoints.
 */
final class DynamicOAuth
{
    public const READ = 'catalog:read';
    public const WRITE = 'catalog:write';
    public const EVOLVE = 'app:evolve';

    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/');
    }

    public static function resource(): string
    {
        return self::baseUrl() . '/mcp.php';
    }

    public function register(array $metadata): array
    {
        $redirects = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirects) || count($redirects) !== 1
            || !is_string($redirects[0]) || !self::validChatGptRedirect($redirects[0])
            || !in_array($metadata['token_endpoint_auth_method'] ?? 'none', ['none'], true)
            || !in_array('authorization_code', $metadata['grant_types'] ?? ['authorization_code'], true)
            || !in_array('code', $metadata['response_types'] ?? ['code'], true)) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }

        $pdo = $this->db();
        // Prevent unbounded anonymous registration. A production deployment
        // must add per-IP throttling and provisioning controls.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM mcp_dcr_clients')->fetchColumn();
        if ($count >= 30) {
            throw new InvalidArgumentException('registration_limit_reached');
        }

        $clientId = 'mcp_' . self::newToken();
        $now = time();
        $insert = $pdo->prepare('INSERT INTO mcp_dcr_clients
            (client_id, redirect_uri, created_at) VALUES (:client_id, :redirect_uri, :created_at)');
        $insert->execute(['client_id' => $clientId, 'redirect_uri' => $redirects[0], 'created_at' => $now]);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $now,
            'redirect_uris' => $redirects,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];
    }

    public static function validChatGptRedirect(string $redirect): bool
    {
        $uri = parse_url($redirect);
        if (!is_array($uri) || strtolower((string) ($uri['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($uri['host'] ?? '')) !== 'chatgpt.com'
            || isset($uri['user'], $uri['pass']) || isset($uri['query']) || isset($uri['fragment'])
            || isset($uri['port']) || isset($uri['user']) || isset($uri['pass'])) {
            return false;
        }

        $path = (string) ($uri['path'] ?? '');
        return $path === '/connector_platform_oauth_redirect'
            || preg_match('~^/connector/oauth/[a-zA-Z0-9_-]{1,160}$~D', $path) === 1;
    }

    private function clientRedirect(string $clientId): ?string
    {
        if ($clientId === '' || strlen($clientId) > 150) {
            return null;
        }
        $get = $this->db()->prepare('SELECT redirect_uri FROM mcp_dcr_clients WHERE client_id = :id');
        $get->execute(['id' => $clientId]);
        $redirect = $get->fetchColumn();
        return is_string($redirect) ? $redirect : null;
    }

    public function verifyAuthorizationRequest(array $request): ?array
    {
        $clientId = $request['client_id'] ?? null;
        $redirect = $request['redirect_uri'] ?? null;
        $challenge = $request['code_challenge'] ?? null;
        if (!is_string($clientId) || !is_string($redirect) || !is_string($challenge)
            || $this->clientRedirect($clientId) !== $redirect
            || ($request['response_type'] ?? null) !== 'code'
            || ($request['code_challenge_method'] ?? null) !== 'S256'
            || preg_match('/^[a-zA-Z0-9_-]{43,128}$/D', $challenge) !== 1
            || !is_string($request['state'] ?? null)
            || strlen($request['state']) > 2048) {
            return null;
        }

        $scope = trim((string) ($request['scope'] ?? self::READ));
        $scopes = preg_split('/\s+/', $scope);
        if (!$scopes || $scopes === [] || count($scopes) !== count(array_unique($scopes))
            || array_diff($scopes, [self::READ, self::WRITE, self::EVOLVE]) !== []) {
            return null;
        }

        // Resource indicators prevent tokens minted for another service
        // from being replayed against our MCP endpoint.
        $resource = (string) ($request['resource'] ?? self::resource());
        if (!hash_equals(self::resource(), $resource)) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'scope' => implode(' ', $scopes),
            'state' => $request['state'],
            'resource' => $resource,
        ];
    }

    public function authorize(int $adminId, array $validated): string
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('admin_required');
        }
        $code = self::newToken();
        $insert = $this->db()->prepare('INSERT INTO mcp_dcr_codes
            (code_hash, client_id, admin_id, redirect_uri, code_challenge, scopes, resource, expires_at)
            VALUES (:hash, :client, :admin, :redirect, :challenge, :scopes, :resource, :expires)');
        $insert->execute([
            'hash' => self::hashToken($code),
            'client' => $validated['client_id'],
            'admin' => $adminId,
            'redirect' => $validated['redirect_uri'],
            'challenge' => $validated['code_challenge'],
            'scopes' => $validated['scope'],
            'resource' => $validated['resource'],
            'expires' => time() + 300,
        ]);
        return $code;
    }

    public function exchange(array $input): array
    {
        $clientId = (string) ($input['client_id'] ?? '');
        $redirect = (string) ($input['redirect_uri'] ?? '');
        $code = (string) ($input['code'] ?? '');
        $verifier = (string) ($input['code_verifier'] ?? '');
        if ($this->clientRedirect($clientId) !== $redirect
            || $code === '' || strlen($code) > 200
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            throw new InvalidArgumentException('invalid_grant');
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT * FROM mcp_dcr_codes WHERE code_hash = :hash');
            $find->execute(['hash' => self::hashToken($code)]);
            $record = $find->fetch(PDO::FETCH_ASSOC);

            if (!$record || $record['client_id'] !== $clientId
                || $record['redirect_uri'] !== $redirect
                || !hash_equals((string) $record['code_challenge'], $challenge)
                || (int) $record['expires_at'] < time() || $record['used_at'] !== null
                || !hash_equals((string) $record['resource'], (string) ($input['resource'] ?? self::resource()))) {
                throw new InvalidArgumentException('invalid_grant');
            }

            $used = $pdo->prepare('UPDATE mcp_dcr_codes SET used_at = :now
                WHERE code_hash = :hash AND used_at IS NULL AND expires_at >= :now');
            $used->execute(['now' => time(), 'hash' => self::hashToken($code)]);
            if ($used->rowCount() !== 1) {
                throw new InvalidArgumentException('invalid_grant');
            }

            $token = $this->issue($pdo, $clientId, (int) $record['admin_id'], (string) $record['scopes'], (string) $record['resource']);
            $pdo->commit();
            return $token;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public function refresh(array $input): array
    {
        $clientId = (string) ($input['client_id'] ?? '');
        $token = (string) ($input['refresh_token'] ?? '');
        if ($this->clientRedirect($clientId) === null || strlen($token) < 40 || strlen($token) > 200) {
            throw new InvalidArgumentException('invalid_grant');
        }

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT * FROM mcp_dcr_tokens WHERE refresh_hash = :hash
                AND revoked_at IS NULL AND refresh_expires_at >= :now');
            $find->execute(['hash' => self::hashToken($token), 'now' => time()]);
            $record = $find->fetch(PDO::FETCH_ASSOC);
            if (!$record || $record['client_id'] !== $clientId) {
                throw new InvalidArgumentException('invalid_grant');
            }
            $revoke = $pdo->prepare('UPDATE mcp_dcr_tokens SET revoked_at = :now
                WHERE id = :id AND revoked_at IS NULL');
            $revoke->execute(['now' => time(), 'id' => $record['id']]);
            if ($revoke->rowCount() !== 1) {
                throw new InvalidArgumentException('invalid_grant');
            }
            $issued = $this->issue($pdo, $clientId, (int) $record['admin_id'], (string) $record['scopes'], (string) $record['resource']);
            $pdo->commit();
            return $issued;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public function principal(string $bearer): ?array
    {
        if (strlen($bearer) < 40 || strlen($bearer) > 200) {
            return null;
        }
        $find = $this->db()->prepare('SELECT admin_id, scopes, resource FROM mcp_dcr_tokens
            WHERE access_hash = :hash AND revoked_at IS NULL AND access_expires_at > :now LIMIT 1');
        $find->execute(['hash' => self::hashToken($bearer), 'now' => time()]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals(self::resource(), (string) $row['resource'])) {
            return null;
        }

        // Remove rights when the associated administrator no longer exists.
        $admin = $this->db()->prepare('SELECT id FROM admin_users WHERE id = :id LIMIT 1');
        $admin->execute(['id' => (int) $row['admin_id']]);
        if ($admin->fetchColumn() === false) {
            return null;
        }

        return ['admin_id' => (int) $row['admin_id'], 'scopes' => explode(' ', $row['scopes'])];
    }

    private function issue(PDO $pdo, string $client, int $admin, string $scopes, string $resource): array
    {
        $access = self::newToken();
        $refresh = self::newToken();
        $now = time();
        $insert = $pdo->prepare('INSERT INTO mcp_dcr_tokens
            (client_id, admin_id, access_hash, refresh_hash, scopes, resource, access_expires_at, refresh_expires_at)
            VALUES (:client, :admin, :access, :refresh, :scopes, :resource, :access_expires, :refresh_expires)');
        $insert->execute([
            'client' => $client,
            'admin' => $admin,
            'access' => self::hashToken($access),
            'refresh' => self::hashToken($refresh),
            'scopes' => $scopes,
            'resource' => $resource,
            'access_expires' => $now + 3600,
            'refresh_expires' => $now + 30 * 86400,
        ]);
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh,
            'scope' => $scopes,
        ];
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function db(): PDO
    {
        return Database::connection();
    }
}
