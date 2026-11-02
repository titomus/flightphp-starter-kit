<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

/**
 * The customer-facing half of AI-assisted software evolution.
 *
 * Creating a request only stores an intent: it NEVER edits server files,
 * opens a shell, deploys code or grants access to GitHub.
 */
final class EvolutionRequestService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function request(int $adminId, string $summary, string $details): array
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('Connexion administrateur requise.');
        }

        $summary = trim($summary);
        $details = trim($details);
        if (mb_strlen($summary) < 8 || mb_strlen($summary) > 140) {
            throw new InvalidArgumentException('Le titre doit comporter entre 8 et 140 caractères.');
        }
        if (mb_strlen($details) < 20 || mb_strlen($details) > 4000) {
            throw new InvalidArgumentException('Décrivez la demande en 20 à 4000 caractères.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO application_change_requests (admin_id, summary, details, status)
             VALUES (:admin, :summary, :details, :status)'
        );
        $statement->execute([
            'admin' => $adminId,
            'summary' => $summary,
            'details' => $details,
            'status' => 'requested',
        ]);

        return $this->status($adminId, (int) $this->db->lastInsertId())
            ?? throw new \RuntimeException('Unable to retrieve change request.');
    }

    public function status(int $adminId, int $id): ?array
    {
        if ($adminId < 1 || $id < 1) {
            throw new InvalidArgumentException('Identifiant invalide.');
        }

        $statement = $this->db->prepare(
            'SELECT id, summary, details, status, review_url, created_at, updated_at
             FROM application_change_requests
             WHERE admin_id = :admin AND id = :id LIMIT 1'
        );
        $statement->execute(['admin' => $adminId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function recent(int $adminId, int $limit = 20): array
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('Connexion administrateur requise.');
        }

        $limit = max(1, min(50, $limit));
        $statement = $this->db->prepare(
            'SELECT id, summary, status, review_url, created_at, updated_at
             FROM application_change_requests WHERE admin_id = :admin
             ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute(['admin' => $adminId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
