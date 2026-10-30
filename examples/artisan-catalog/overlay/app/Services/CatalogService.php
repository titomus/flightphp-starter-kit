<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

/**
 * Single-tenant demonstration catalog. The web UI and MCP tools use this
 * exact same service. Do not use as-is for multi-tenant production data.
 */
final class CatalogService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function listItems(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->database->query('SELECT sku, name, price_cents, stock, updated_at FROM catalog_items ORDER BY name ASC LIMIT ' . $limit);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function previewImport(array $rows): array
    {
        if ($rows === [] || count($rows) > 500) {
            throw new InvalidArgumentException('Le lot doit contenir entre 1 et 500 références.');
        }

        $known = [];
        $result = [];
        $errors = [];
        $counts = ['create' => 0, 'update' => 0];

        $find = $this->database->prepare('SELECT id FROM catalog_items WHERE sku = :sku LIMIT 1');
        foreach ($rows as $index => $row) {
            $line = $index + 1;
            if (!is_array($row)) {
                $errors[] = ['line' => $line, 'message' => 'Ligne invalide'];
                continue;
            }
            try {
                $item = $this->normalize($row);
                if (isset($known[$item['sku']])) {
                    throw new InvalidArgumentException('Référence répétée dans ce lot');
                }
                $known[$item['sku']] = true;
                $find->execute(['sku' => $item['sku']]);
                $action = $find->fetchColumn() === false ? 'create' : 'update';
                $counts[$action]++;
                $result[] = ['line' => $line, 'action' => $action] + $item;
            } catch (InvalidArgumentException $exception) {
                $errors[] = ['line' => $line, 'message' => $exception->getMessage()];
            }
        }

        return ['rows' => $result, 'errors' => $errors, 'counts' => $counts, 'valid' => $errors === []];
    }

    public function commitImport(array $rows): array
    {
        // Validation is repeated at commit time. This operation is idempotent
        // for identical SKU + field values thanks to SQLite UPSERT.
        $preview = $this->previewImport($rows);
        if (!$preview['valid']) {
            throw new InvalidArgumentException('Des erreurs empêchent l’import. Vérifier la prévisualisation.');
        }

        $upsert = $this->database->prepare(
            'INSERT INTO catalog_items (sku, name, price_cents, stock, updated_at)
             VALUES (:sku, :name, :price_cents, :stock, CURRENT_TIMESTAMP)
             ON CONFLICT(sku) DO UPDATE SET
             name = excluded.name, price_cents = excluded.price_cents,
             stock = excluded.stock, updated_at = CURRENT_TIMESTAMP'
        );

        $this->database->beginTransaction();
        try {
            foreach ($preview['rows'] as $item) {
                $upsert->execute([
                    'sku' => $item['sku'],
                    'name' => $item['name'],
                    'price_cents' => $item['price_cents'],
                    'stock' => $item['stock'],
                ]);
            }
            $this->database->commit();
        } catch (\Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }

        return ['imported' => count($preview['rows']), 'counts' => $preview['counts']];
    }

    private function normalize(array $row): array
    {
        $sku = strtoupper(trim((string) ($row['reference'] ?? $row['sku'] ?? '')));
        $name = trim((string) ($row['designation'] ?? $row['name'] ?? ''));
        $price = trim((string) ($row['prix_ht'] ?? $row['price'] ?? ''));
        $stock = trim((string) ($row['stock'] ?? ''));

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $sku)) {
            throw new InvalidArgumentException('Référence invalide (lettres, chiffres, point, tiret)');
        }
        if ($name === '' || mb_strlen($name) > 200) {
            throw new InvalidArgumentException('Désignation manquante ou trop longue');
        }
        if (!preg_match('/^\d{1,7}(?:[.,]\d{1,2})?$/', $price)) {
            throw new InvalidArgumentException('Prix HT invalide');
        }
        if (!preg_match('/^\d{1,7}$/', $stock)) {
            throw new InvalidArgumentException('Stock invalide');
        }

        $parts = preg_split('/[.,]/', $price);
        $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        return [
            'sku' => $sku,
            'name' => $name,
            'price_cents' => $cents,
            'stock' => (int) $stock,
        ];
    }
}
