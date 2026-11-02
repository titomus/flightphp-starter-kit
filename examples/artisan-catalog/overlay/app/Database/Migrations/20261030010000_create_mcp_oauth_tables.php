<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateMcpOAuthTables';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_oauth_codes (
            code_hash TEXT PRIMARY KEY,
            admin_id INTEGER NOT NULL,
            client_id TEXT NOT NULL,
            redirect_uri TEXT NOT NULL,
            code_challenge TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used_at INTEGER DEFAULT NULL
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_oauth_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            access_hash TEXT NOT NULL UNIQUE,
            refresh_hash TEXT NOT NULL UNIQUE,
            admin_id INTEGER NOT NULL,
            client_id TEXT NOT NULL,
            scope TEXT NOT NULL,
            access_expires_at INTEGER NOT NULL,
            refresh_expires_at INTEGER NOT NULL,
            revoked_at INTEGER DEFAULT NULL
        )');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_oauth_codes_expiry
            ON mcp_oauth_codes(expires_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_oauth_tokens_expiry
            ON mcp_oauth_tokens(access_expires_at)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mcp_oauth_tokens');
        $pdo->exec('DROP TABLE IF EXISTS mcp_oauth_codes');
    }
};
