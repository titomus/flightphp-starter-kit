<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateApplicationChangeRequests';
    }

    public function up(\PDO $pdo): void
    {
        // Each request belongs to the authenticated owner. Nothing in this
        // schema grants the user write access to the source repository.
        $pdo->exec('CREATE TABLE IF NOT EXISTS application_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            summary TEXT NOT NULL,
            details TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'requested\',
            developer_branch TEXT DEFAULT NULL,
            review_url TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_application_change_requests_owner
            ON application_change_requests(admin_id, id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS application_change_requests');
    }
};
