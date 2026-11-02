<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CatalogCsv;
use App\Services\CatalogService;
use App\Services\Database;

final class CatalogController extends BaseController
{
    public function index(): void
    {
        $service = new CatalogService(Database::connection());
        $staged = $_SESSION['catalog.staged'] ?? null;
        $preview = is_array($staged) && $staged !== []
            ? $service->previewImport($staged)
            : null;

        echo $this->view('catalog.index', [
            'items' => $service->listItems(),
            'preview' => $preview,
            'message' => flash_get('catalog.message'),
            'error' => flash_get('catalog.error'),
            'seo' => ['title' => 'Catalogue artisan', 'robots' => 'noindex,nofollow'],
        ]);
    }

    public function preview(): void
    {
        $this->guardCsrf();

        try {
            $upload = $_FILES['catalog_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || (int) ($upload['size'] ?? 0) > 2_000_000
                || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
                throw new \InvalidArgumentException('Veuillez choisir un fichier CSV de moins de 2 Mo');
            }

            $rows = (new CatalogCsv())->read((string) $upload['tmp_name']);
            $preview = (new CatalogService(Database::connection()))->previewImport($rows);
            $_SESSION['catalog.staged'] = $rows;
            flash_set('catalog.message', $preview['valid']
                ? 'Import analysé. Vérifiez le tableau avant de confirmer.'
                : 'Certaines lignes contiennent des erreurs à corriger.');
        } catch (\Throwable $exception) {
            unset($_SESSION['catalog.staged']);
            flash_set('catalog.error', $exception->getMessage());
        }

        redirect('/catalog', 303);
    }

    public function commit(): void
    {
        $this->guardCsrf();
        $rows = $_SESSION['catalog.staged'] ?? [];
        if (!is_array($rows) || $rows === []) {
            flash_set('catalog.error', 'Aucun import en attente.');
            redirect('/catalog', 303);
        }

        try {
            $result = (new CatalogService(Database::connection()))->commitImport($rows);
            unset($_SESSION['catalog.staged']);
            flash_set('catalog.message', $result['imported'] . ' références enregistrées.');
        } catch (\Throwable $exception) {
            flash_set('catalog.error', $exception->getMessage());
        }

        redirect('/catalog', 303);
    }

    public function cancel(): void
    {
        $this->guardCsrf();
        unset($_SESSION['catalog.staged']);
        flash_set('catalog.message', 'Import annulé.');
        redirect('/catalog', 303);
    }
}
