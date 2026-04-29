<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    echo "This installer must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/FlightStarterInstaller.php';

$config = require __DIR__ . '/config.php';

$argv = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$keepInstaller = in_array('--keep-installer', $argv, true);
$showHelp = in_array('--help', $argv, true) || in_array('-h', $argv, true);

$basePath = dirname(__DIR__);
$installerDir = __DIR__;
$entrypoint = $basePath . DIRECTORY_SEPARATOR . 'install-flight-starter.php';

$cleanupEnabled = (bool) ($config['installer']['cleanup_on_success'] ?? true);

$cleanupInstaller = static function () use ($installerDir, $entrypoint): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $installerEscaped = str_replace('"', '""', $installerDir);
        $entryEscaped = str_replace('"', '""', $entrypoint);
        $command = 'cmd /c "ping 127.0.0.1 -n 2 >nul & rmdir /s /q ""' . $installerEscaped . '"" & del /f /q ""' . $entryEscaped . '"""';
        pclose(popen($command, 'r'));
        return;
    }

    $installerEscaped = escapeshellarg($installerDir);
    $entryEscaped = escapeshellarg($entrypoint);
    $command = 'sh -c "sleep 1; rm -rf ' . $installerEscaped . '; rm -f ' . $entryEscaped . '" >/dev/null 2>&1 &';
    pclose(popen($command, 'r'));
};

try {
    $installer = new FlightStarterInstaller($config, $argv);
    $exitCode = $installer->run();

    $shouldCleanup = $exitCode === 0
        && !$dryRun
        && !$showHelp
        && !$keepInstaller
        && $cleanupEnabled;

    if ($shouldCleanup) {
        echo PHP_EOL . 'Installer cleanup scheduled.' . PHP_EOL;
        $cleanupInstaller();
    }

    exit($exitCode);
} catch (Throwable $exception) {
    fwrite(STDERR, '[error] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
