<?php

declare(strict_types=1);

require_once __DIR__ . '/WebInstallRecipe.php';
require_once __DIR__ . '/FlightStarterInstaller.php';

/**
 * Build the configured application from an independently SHA-256 verified
 * GitHub distribution. Never executes Composer/npm in shared hosting.
 *
 * Bundle layout:
 *   installer/*.php
 *   runtime/vendor/**
 *   runtime/build/{saas,outil_metier,blog}/**
 */
final class WebInstallEngine
{
    public static function requirements(): array
    {
        $items = [
            'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
            'ZipArchive' => class_exists(ZipArchive::class),
            'OpenSSL' => extension_loaded('openssl'),
            'PDO SQLite' => extension_loaded('pdo_sqlite'),
            'mbstring' => extension_loaded('mbstring'),
            'JSON' => extension_loaded('json'),
            'FileInfo' => extension_loaded('fileinfo'),
        ];
        return $items;
    }

    public static function install(string $bundle, string $projectRoot, array $input): array
    {
        $validated = WebInstallRecipe::validate($input);
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root . '/public')) {
            throw new RuntimeException('Projet invalide : le domaine doit pointer sur /public.');
        }
        if (is_link($root . '/public') || realpath($root . '/public') !== $root . '/public') {
            throw new RuntimeException('Racine publique non conforme.');
        }
        foreach (['app', 'vendor', 'resources', '.env', 'composer.json', 'public/index.php'] as $existing) {
            if (file_exists($root . '/' . $existing) || is_link($root . '/' . $existing)) {
                throw new RuntimeException('Installation existante détectée : ' . $existing . '. Aucun écrasement permis.');
            }
        }
        foreach (self::requirements() as $name => $ok) {
            if (!$ok) {
                throw new RuntimeException('Extension ou version manquante : ' . $name);
            }
        }

        $package = realpath($bundle);
        if (!$package || !is_file($package . '/installer/config.php')
            || !is_file($package . '/installer/WebInstallRecipe.php')
            || !is_file($package . '/runtime/vendor/autoload.php')) {
            throw new RuntimeException('Distribution Flight Starter incomplète.');
        }
        $preset = $validated['preset'];
        $built = $package . '/runtime/build/' . $preset;
        if (!is_file($built . '/manifest.json')) {
            throw new RuntimeException('Assets compilés absents pour le thème ' . $preset . '.');
        }

        $stage = $root . '/.flight-stage-' . bin2hex(random_bytes(9));
        if (!mkdir($stage, 0700) || !mkdir($stage . '/public', 0700)) {
            throw new RuntimeException('Impossible de préparer une installation isolée.');
        }
        $completed = false;
        try {
            $config = require $package . '/installer/config.php';
            if (!is_array($config)) {
                throw new RuntimeException('Configuration du générateur invalide.');
            }
            $config = WebInstallRecipe::apply($config, $validated, $stage);
            ob_start();
            try {
                $status = (new FlightStarterInstaller($config, [
                    'install-flight-starter.php', '--no-composer', '--keep-installer',
                ]))->run();
            } finally {
                ob_end_clean();
            }
            if ($status !== 0) {
                throw new RuntimeException('Échec de la génération FlightPHP.');
            }

            self::copyDirectory($package . '/runtime/vendor', $stage . '/vendor');
            self::copyDirectory($built, $stage . '/public/build');

            if (!is_file($stage . '/public/build/manifest.json')
                || !is_file($stage . '/vendor/autoload.php')
                || !is_file($stage . '/app/bootstrap.php')) {
                throw new RuntimeException('Dépendances ou fichiers manquants après génération.');
            }

            $env = (string) file_get_contents($stage . '/.env.example');
            $env = str_replace('APP_ENV=local', 'APP_ENV=production', $env);
            $env = str_replace('APP_DEBUG=true', 'APP_DEBUG=false', $env);
            $env = str_replace('ADMIN_PASSWORD=' . $validated['password'], 'ADMIN_PASSWORD=' . $validated['password'], $env);
            if (file_put_contents($stage . '/.env', $env, LOCK_EX) === false) {
                throw new RuntimeException('Impossible de configurer le fichier .env.');
            }
            chmod($stage . '/.env', 0600);

            // Appearance is an immediate runtime override: the prebuilt Vite
            // theme never needs recompilation on the shared hosting server.
            $initial = [
                'design' => [],
                'stripe' => ['enabled' => false],
                'google' => ['enabled' => false],
                'github' => ['enabled' => false],
            ];
            foreach (['primary', 'accent'] as $color) {
                if ($validated[$color] !== '') {
                    $initial['design'][$color] = $validated[$color];
                }
            }
            $privateConfigDir = $stage . '/app/storage/config';
            if (!is_dir($privateConfigDir) && !mkdir($privateConfigDir, 0700, true)) {
                throw new RuntimeException('Impossible de créer le dossier des réglages.');
            }
            $initialPath = $privateConfigDir . '/runtime.json';
            if (file_put_contents($initialPath,
                json_encode($initial, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new RuntimeException('Impossible d’enregistrer les choix de départ.');
            }
            chmod($initialPath, 0600);

            // Boot application in its isolated staging directory; the generated
            // console migrator runs inside the same PHP request.
            require $stage . '/app/bootstrap.php';
            $db = \App\Services\Database::connection();
            // Migrations and seeders print CLI progress. Do not leak that
            // output into the installer's HTML or break HTTP headers.
            ob_start();
            try {
                (new \App\Services\Migrator($db))->migrate();
                $seed = require $stage . '/app/Database/Seeds/DatabaseSeeder.php';
                if (!is_callable($seed)) {
                    throw new RuntimeException('Seeder applicatif manquant.');
                }
                $seed($db);
            } finally {
                ob_end_clean();
            }
            if (!(int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
                throw new RuntimeException('Le propriétaire n’a pas été créé.');
            }

            // Erase all occurrences of the initial cleartext password from
            // generated project files. The admin account stores a password_hash.
            self::redactSeedPassword($stage, $validated['password']);
            $validated['password'] = null;
            unset($config['backoffice']['default_password']);

            $meta = [
                'type' => $validated['type'],
                'mcp' => $validated['mcp'],
                'members' => $validated['members'],
                'stripe_prepared' => $validated['stripe'],
                'google_prepared' => $validated['google'],
                'github_prepared' => $validated['github'],
                'smtp_prepared' => $validated['smtp'],
                'primary' => $validated['primary'],
                'accent' => $validated['accent'],
                'design' => $preset,
                'created_at' => gmdate('c'),
            ];
            file_put_contents($stage . '/.flight-project.json',
                json_encode($meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            self::activate($stage, $root);
            $completed = true;
            return [
                'url' => $validated['url'],
                'admin' => $validated['url'] . '/admin/login',
                'mcp' => $validated['mcp'] ? $validated['url'] . '/mcp' : null,
                'features' => $meta,
            ];
        } finally {
            // Never retain a failed stage: it can contain the temporary
            // cleartext password before the seed-redaction phase.
            self::removeDirectory($stage);
        }
    }

    private static function redactSeedPassword(string $stage, string $password): void
    {
        $replacement = 'DELETED_SEED_' . bin2hex(random_bytes(12));
        foreach (['app/config/backoffice.php', '.env', '.env.example', 'README.md'] as $path) {
            $file = $stage . '/' . $path;
            if (!is_file($file)) {
                continue;
            }
            $data = (string) file_get_contents($file);
            if (str_contains($data, $password)) {
                if (file_put_contents($file, str_replace($password, $replacement, $data), LOCK_EX) === false) {
                    throw new RuntimeException('Impossible de supprimer le mot de passe initial : ' . $path);
                }
            }
        }
    }

    private static function activate(string $stage, string $root): void
    {
        // Activate private files and dependencies first, then the public entry
        // point last. The root was checked empty before generation.
        $moved = [];
        $copied = [];
        try {
            foreach (scandir($stage) ?: [] as $entry) {
                if (in_array($entry, ['.', '..', 'public'], true)) {
                    continue;
                }
                $src = $stage . '/' . $entry;
                $dest = $root . '/' . $entry;
                if (file_exists($dest) || is_link($dest)) {
                    throw new RuntimeException('Activation refusée, déjà présent : ' . $entry);
                }
                if (!rename($src, $dest)) {
                    throw new RuntimeException('Activation impossible : ' . $entry);
                }
                $moved[] = $entry;
            }
            foreach (scandir($stage . '/public') ?: [] as $entry) {
                if (in_array($entry, ['.', '..', 'index.php'], true)) {
                    continue;
                }
                $source = $stage . '/public/' . $entry;
                $target = $root . '/public/' . $entry;
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('Conflit dans le dossier public : ' . $entry);
                }
                if (!rename($source, $target)) {
                    throw new RuntimeException('Impossible de publier ' . $entry);
                }
                $copied[] = $entry;
            }
            if (!rename($stage . '/public/index.php', $root . '/public/index.php')) {
                throw new RuntimeException('Impossible d’activer le point d’entrée.');
            }
        } catch (Throwable $e) {
            foreach (array_reverse($copied) as $entry) {
                @rename($root . '/public/' . $entry, $stage . '/public/' . $entry);
            }
            foreach (array_reverse($moved) as $entry) {
                @rename($root . '/' . $entry, $stage . '/' . $entry);
            }
            throw $e;
        }
    }

    public static function copyDirectory(string $src, string $dest): void
    {
        if (is_link($src) || !is_dir($src)) {
            throw new RuntimeException('Source de distribution non valide.');
        }
        if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
            throw new RuntimeException('Création impossible : ' . $dest);
        }
        foreach (scandir($src) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $a = $src . '/' . $child;
            $b = $dest . '/' . $child;
            if (is_link($a) || is_link($b)) {
                throw new RuntimeException('Liens symboliques refusés dans la distribution.');
            }
            if (is_dir($a)) {
                self::copyDirectory($a, $b);
            } elseif (is_file($a)) {
                if (file_exists($b) || !copy($a, $b)) {
                    throw new RuntimeException('Copie impossible : ' . $b);
                }
            } else {
                throw new RuntimeException('Entrée de distribution inconnue.');
            }
        }
    }

    public static function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) continue;
            $full = $path . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                self::removeDirectory($full);
            } elseif (is_file($full) || is_link($full)) {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
