<?php

declare(strict_types=1);

// Usage: php examples/artisan-catalog/build.php /absolute/path/to/output
// Creates a clean standalone application by running the actual FlightPHP installer.
$repository = dirname(__DIR__, 2);
$output = $argv[1] ?? '';
if ($output === '' || !str_starts_with($output, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Usage: php build.php /absolute/empty/output/directory\n");
    exit(1);
}
if (is_dir($output) && (scandir($output) ?: []) !== ['.', '..']) {
    fwrite(STDERR, "Refusing to overwrite non-empty output directory.\n");
    exit(1);
}
if (!is_dir($output) && !mkdir($output, 0755, true) && !is_dir($output)) {
    throw new RuntimeException('Cannot create output directory');
}

$copy = static function (string $source, string $destination) use (&$copy): void {
    if (is_link($source)) {
        throw new RuntimeException('Symlinks are not copied into the build');
    }
    if (is_dir($source)) {
        if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
            throw new RuntimeException('Cannot create ' . $destination);
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $copy($source . '/' . $entry, $destination . '/' . $entry);
            }
        }
    } elseif (!copy($source, $destination)) {
        throw new RuntimeException('Cannot copy ' . $source);
    }
};

$copy($repository . '/installer', $output . '/installer');
$copy($repository . '/install-flight-starter.php', $output . '/install-flight-starter.php');

$configPath = $output . '/installer/config.php';
$config = require $configPath;
$config['project_name'] = 'Atelier Catalogue';
$config['base_url'] = 'http://localhost:8000';
$config['target_dir'] = $output;
$config['mcp']['enabled'] = true;
$config['composer']['auto_install'] = false;
$config['npm']['auto_build'] = false;
$config['installer']['cleanup_on_success'] = false;
$config['backoffice']['default_email'] = 'admin@example.test';
$config['backoffice']['default_password'] = 'CHANGE_ME_BEFORE_SEED';
file_put_contents($configPath, "<?php\n\nreturn " . var_export($config, true) . ";\n");

$command = escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($output . '/install-flight-starter.php')
    . ' --no-composer --keep-installer';
passthru($command, $exitCode);
if ($exitCode !== 0) {
    throw new RuntimeException('Starter-kit installer failed: ' . $exitCode);
}

$copy(__DIR__ . '/overlay/app', $output . '/app');
$copy(__DIR__ . '/overlay/public', $output . '/public');
$route = file_get_contents(__DIR__ . '/routes.php');
if ($route === false || file_put_contents($output . '/app/routes/web.php', $route, FILE_APPEND) === false) {
    throw new RuntimeException('Cannot register demo web routes');
}
mkdir($output . '/example-data', 0755, true);
$copy(__DIR__ . '/references.csv', $output . '/example-data/references.csv');
$copy(__DIR__ . '/README_ARTISAN.md', $output . '/README_ARTISAN.md');

// Remove only the copied installer; generated app remains fully standalone.
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};
$remove($output . '/installer');
unlink($output . '/install-flight-starter.php');
echo "Artisan SaaS generated at " . $output . PHP_EOL;
