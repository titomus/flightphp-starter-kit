<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateMcpDynamicOAuthTables';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_dcr_clients (
            client_id TEXT PRIMARY KEY,
            redirect_uri TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_dcr_codes (
            code_hash TEXT PRIMARY KEY,
            client_id TEXT NOT NULL,
            admin_id INTEGER NOT NULL,
            redirect_uri TEXT NOT NULL,
            code_challenge TEXT NOT NULL,
            scopes TEXT NOT NULL,
            resource TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used_at INTEGER DEFAULT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_dcr_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id TEXT NOT NULL,
            admin_id INTEGER NOT NULL,
            access_hash TEXT NOT NULL UNIQUE,
            refresh_hash TEXT NOT NULL UNIQUE,
            scopes TEXT NOT NULL,
            resource TEXT NOT NULL,
            access_expires_at INTEGER NOT NULL,
            refresh_expires_at INTEGER NOT NULL,
            revoked_at INTEGER DEFAULT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_dcr_token_access
            ON mcp_dcr_tokens(access_hash, access_expires_at)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mcp_dcr_tokens');
        $pdo->exec('DROP TABLE IF EXISTS mcp_dcr_codes');
        $pdo->exec('DROP TABLE IF EXISTS mcp_dcr_clients');
    }
};
