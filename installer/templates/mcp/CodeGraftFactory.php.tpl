<?php
declare(strict_types=1);

namespace {{namespace}}\Mcp;

/**
 * Single Flight -> real CodeGraft bridge.
 * Flight OAuth is the ONLY external identity provider on /mcp.
 * The CodeGraft vault lives outside the entire generated project, not in public/.
 */
final class CodeGraftFactory
{
    private static ?\CodeGraft\Vault $vault = null;

    private static function boot(): void
    {
        foreach (['Policy', 'Vault', 'Workspace', 'Mcp', 'Admin'] as $class) {
            require_once dirname(__DIR__) . '/CodeGraft/' . $class . '.php';
        }
    }

    public static function vault(): \CodeGraft\Vault
    {
        self::boot();
        if (self::$vault !== null) {
            return self::$vault;
        }
        $root = realpath(dirname(__DIR__, 2));
        if (!is_string($root)) {
            throw new \RuntimeException('CodeGraft project root missing.');
        }
        // On a standard o2switch layout project/ and its private vault share a
        // parent, while only project/public/ is reachable by the web server.
        $configured = (string) env('CODEGRAFT_VAULT_DIR', '');
        $vaultDir = $configured !== ''
            ? $configured
            : dirname($root) . '/.codegraft-vault-' . substr(hash('sha256', $root), 0, 12);
        // A sibling vault beneath public_html/ or www/ could be exposed by
        // another virtual host. Never guess in that layout.
        if ($configured === '' && preg_match('~/(?:public_html|www|htdocs|httpdocs|web)$~iD', rtrim(dirname($root), '/'))) {
            throw new \RuntimeException('Set CODEGRAFT_VAULT_DIR to a private path outside public_html.');
        }
        if (!str_starts_with($vaultDir, '/') || is_link($vaultDir)) {
            throw new \RuntimeException('CODEGRAFT_VAULT_DIR must be an absolute private directory.');
        }
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== false && ($vaultDir === $documentRoot
            || str_starts_with(rtrim($vaultDir, '/') . '/', rtrim($documentRoot, '/') . '/'))) {
            throw new \RuntimeException('CodeGraft vault may not be inside HTTP document root.');
        }
        return self::$vault = new \CodeGraft\Vault($root, $vaultDir, 'flight');
    }

    public static function workspace(): \CodeGraft\Workspace
    {
        return new \CodeGraft\Workspace(self::vault());
    }

    public static function mcp(): \CodeGraft\Mcp
    {
        $vault = self::vault();
        return new \CodeGraft\Mcp($vault, new \CodeGraft\Workspace($vault));
    }

    public static function deploymentAllowed(): bool
    {
        return CodeWorkspace::deploymentAllowed();
    }

    /** Store strictly metadata, never MCP arguments, source contents or tokens. */
    public static function audit(string $tool, int $adminId, string $outcome, int $milliseconds): void
    {
        try {
            $file = self::vault()->directory . '/audit.jsonl';
            if (is_link($file)) {
                return;
            }
            if (is_file($file) && filesize($file) > 2097152) {
                @rename($file, self::vault()->directory . '/audit-previous.jsonl');
            }
            $line = json_encode([
                'at' => gmdate('c'),
                'tool' => substr($tool, 0, 64),
                'admin_id' => $adminId,
                'outcome' => $outcome === 'ok' ? 'ok' : 'error',
                'ms' => max(0, $milliseconds),
            ], JSON_THROW_ON_ERROR);
            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            @chmod($file, 0600);
        } catch (\Throwable $e) {
            error_log('CodeGraft audit storage unavailable: ' . $e->getMessage());
        }
    }

    public static function recentAudit(int $limit = 50): array
    {
        $file = self::vault()->directory . '/audit.jsonl';
        if (!is_file($file) || is_link($file)) {
            return [];
        }
        // Rotate at 2 MB; cap how much the admin page loads into memory.
        $rows = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach (array_reverse(array_slice($rows, -max(1, min(100, $limit)))) as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $result[] = $event;
            }
        }
        return $result;
    }
}
