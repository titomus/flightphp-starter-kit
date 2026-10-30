<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Services\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Single-application OAuth 2 authorization-code + PKCE store.
 * Codes and tokens are hashed at rest; redirect URIs are pinned by configuration.
 * Intentionally read-only: the only supported scope is catalog:read.
 */
final class OAuthStore
{
    public const SCOPE = 'catalog:read';

    public static function enabled(): bool
    {
        return self::clientId() !== ''
            && strlen(self::clientSecret()) >= 32
            && self::redirectUri() !== '';
    }

    public static function clientId(): string
    {
        return trim((string) env('MCP_OAUTH_CLIENT_ID', ''));
    }

    private static function clientSecret(): string
    {
        return (string) env('MCP_OAUTH_CLIENT_SECRET', '');
    }

    public static function redirectUri(): string
    {
        return trim((string) env('MCP_OAUTH_REDIRECT_URI', ''));
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/');
    }

    public static function isValidRequest(array $query): bool
    {
        $uri = self::redirectUri();
        return self::enabled()
            && str_starts_with($uri, 'https://')
            && ($query['response_type'] ?? '') === 'code'
            && ($query['client_id'] ?? '') === self::clientId()
            && ($query['redirect_uri'] ?? '') === $uri
            && ($query['code_challenge_method'] ?? '') === 'S256'
            && is_string($query['code_challenge'] ?? null)
            && (bool) preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $query['code_challenge'])
            && ($query['scope'] ?? self::SCOPE) === self::SCOPE
            && is_string($query['state'] ?? '')
            && strlen((string) ($query['state'] ?? '')) <= 2048;
    }

    public static function authenticateClient(string $clientId, string $secret): bool
    {
        return self::enabled()
            && hash_equals(self::clientId(), $clientId)
            && hash_equals(self::clientSecret(), $secret);
    }

    public function authorize(int $adminId, string $clientId, string $redirectUri, string $challenge): string
    {
        if ($adminId < 1 || !self::enabled()
            || !hash_equals(self::clientId(), $clientId)
            || !hash_equals(self::redirectUri(), $redirectUri)
            || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $challenge)) {
            throw new InvalidArgumentException('Invalid authorization request');
        }

        $code = self::randomToken();
        $statement = $this->db()->prepare(
            'INSERT INTO mcp_oauth_codes
            (code_hash, admin_id, client_id, redirect_uri, code_challenge, expires_at)
            VALUES (:hash, :admin_id, :client_id, :redirect_uri, :challenge, :expires)'
        );
        $statement->execute([
            'hash' => self::digest($code),
            'admin_id' => $adminId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'challenge' => $challenge,
            'expires' => time() + 300,
        ]);
        return $code;
    }

    public function exchange(string $code, string $clientId, string $redirectUri, string $verifier): array
    {
        if (!self::enabled() || !hash_equals(self::clientId(), $clientId)
            || !hash_equals(self::redirectUri(), $redirectUri)
            || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            throw new InvalidArgumentException('invalid_grant');
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $db = $this->db();
        $db->beginTransaction();
        try {
            $get = $db->prepare('SELECT * FROM mcp_oauth_codes WHERE code_hash = :hash');
            $get->execute(['hash' => self::digest($code)]);
            $row = $get->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['client_id'] !== $clientId || $row['redirect_uri'] !== $redirectUri
                || !hash_equals((string) $row['code_challenge'], $challenge)
                || (int) $row['expires_at'] < time() || $row['used_at'] !== null) {
                throw new InvalidArgumentException('invalid_grant');
            }

            $mark = $db->prepare('UPDATE mcp_oauth_codes SET used_at = :used
                WHERE code_hash = :hash AND used_at IS NULL AND expires_at >= :now');
            $mark->execute(['used' => time(), 'hash' => self::digest($code), 'now' => time()]);
            if ($mark->rowCount() !== 1) {
                throw new InvalidArgumentException('invalid_grant');
            }

            $issued = $this->issueTokens($db, (int) $row['admin_id'], $clientId);
            $db->commit();
            return $issued;
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function refresh(string $refreshToken, string $clientId): array
    {
        if (!self::enabled() || !hash_equals(self::clientId(), $clientId)) {
            throw new InvalidArgumentException('invalid_grant');
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $find = $db->prepare('SELECT * FROM mcp_oauth_tokens
                WHERE refresh_hash = :hash AND revoked_at IS NULL AND refresh_expires_at >= :now');
            $find->execute(['hash' => self::digest($refreshToken), 'now' => time()]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!$old || $old['client_id'] !== $clientId) {
                throw new InvalidArgumentException('invalid_grant');
            }

            $revoke = $db->prepare('UPDATE mcp_oauth_tokens SET revoked_at = :now
                WHERE id = :id AND revoked_at IS NULL');
            $revoke->execute(['now' => time(), 'id' => $old['id']]);
            if ($revoke->rowCount() !== 1) {
                throw new InvalidArgumentException('invalid_grant');
            }

            $issued = $this->issueTokens($db, (int) $old['admin_id'], $clientId);
            $db->commit();
            return $issued;
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function principal(string $bearer): ?array
    {
        if (!self::enabled() || strlen($bearer) < 40 || strlen($bearer) > 200) {
            return null;
        }

        $query = $this->db()->prepare('SELECT admin_id, scope FROM mcp_oauth_tokens
            WHERE access_hash = :hash AND revoked_at IS NULL AND access_expires_at > :now LIMIT 1');
        $query->execute(['hash' => self::digest($bearer), 'now' => time()]);
        $result = $query->fetch(PDO::FETCH_ASSOC);
        return $result ? ['admin_id' => (int) $result['admin_id'], 'scope' => (string) $result['scope']] : null;
    }

    private function issueTokens(PDO $db, int $adminId, string $clientId): array
    {
        $access = self::randomToken();
        $refresh = self::randomToken();
        $now = time();
        $insert = $db->prepare('INSERT INTO mcp_oauth_tokens
            (access_hash, refresh_hash, admin_id, client_id, scope, access_expires_at, refresh_expires_at)
            VALUES (:access, :refresh, :admin_id, :client_id, :scope, :access_expires, :refresh_expires)');
        $insert->execute([
            'access' => self::digest($access),
            'refresh' => self::digest($refresh),
            'admin_id' => $adminId,
            'client_id' => $clientId,
            'scope' => self::SCOPE,
            'access_expires' => $now + 3600,
            'refresh_expires' => $now + 30 * 86400,
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    private function db(): PDO
    {
        return Database::connection();
    }
}
