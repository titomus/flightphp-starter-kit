<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class CatalogCsv
{
    /**
     * Accepts a UTF-8 CSV with columns: reference, designation, prix_ht, stock.
     * Handles comma and semicolon delimiters and rejects unexpected columns.
     */
    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > 2_000_000) {
            throw new InvalidArgumentException('Fichier absent ou supérieur à 2 Mo');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Impossible de lire le fichier');
        }

        try {
            $first = fgets($handle);
            if (!is_string($first)) {
                throw new InvalidArgumentException('CSV vide');
            }

            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
            if (!is_array($headers)) {
                throw new InvalidArgumentException('En-têtes CSV manquants');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);
            if (count($headers) !== 4 || count(array_unique($headers)) !== 4
                || array_diff(['reference', 'designation', 'prix_ht', 'stock'], $headers) !== []) {
                throw new InvalidArgumentException('Colonnes attendues : reference, designation, prix_ht, stock');
            }

            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($headers)) {
                    throw new InvalidArgumentException('Nombre de colonnes incohérent à la ligne ' . (count($rows) + 2));
                }
                $rows[] = array_combine($headers, $row);
                if (count($rows) > 500) {
                    throw new InvalidArgumentException('Limite : 500 lignes par import');
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
