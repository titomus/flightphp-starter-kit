<?php

declare(strict_types=1);

require_once __DIR__ . '/InstallerOptions.php';
require_once __DIR__ . '/InstallerFeatures.php';
require_once __DIR__ . '/InstallerFilesystem.php';
require_once __DIR__ . '/InstallerReport.php';
require_once __DIR__ . '/ProjectBlueprint.php';
require_once __DIR__ . '/TemplateCatalog.php';

final class FlightStarterInstaller
{
    private InstallerOptions $options;

    private InstallerFeatures $features;

    private ProjectBlueprint $blueprint;

    private InstallerFilesystem $filesystem;

    private InstallerReport $report;

    private string $targetDir;

    public function __construct(private readonly array $config, array $argv)
    {
        $this->options = InstallerOptions::fromArgv($argv);
        $this->features = InstallerFeatures::fromConfig($config, $this->options);
        $this->targetDir = $this->normalizePath($config['target_dir'] ?? __DIR__);
        $this->filesystem = new InstallerFilesystem(
            $this->targetDir,
            $this->options->force,
            $this->options->dryRun
        );
        $this->report = new InstallerReport();
        $this->blueprint = new ProjectBlueprint(
            $this->config,
            $this->targetDir,
            $this->features,
            new TemplateCatalog($this->features)
        );
    }

    public function run(): int
    {
        if ($this->options->showHelp) {
            echo $this->options->helpText() . PHP_EOL;
            return 0;
        }

        $this->printHeader();
        $this->guardTargetDirectory();

        foreach ($this->blueprint->directories() as $directory) {
            if ($this->filesystem->ensureDirectory($directory)) {
                $this->report->recordDirectory();
                if ($this->options->dryRun) {
                    echo '[dir] ' . $directory . PHP_EOL;
                }
            }
        }

        foreach ($this->blueprint->files() as $path => $content) {
            $result = $this->filesystem->writeFile($path, $content);
            $this->report->recordFile($result['status'], $result['label']);

            if ($this->options->dryRun && $result['status'] !== 'skipped') {
                echo '[file] ' . $result['label'] . PHP_EOL;
            }
        }

        if (!$this->options->dryRun) {
            $this->filesystem->ensureDatabaseFile((string) $this->config['database']['database']);
            $this->maybeRunComposerInstall();
            $this->maybeRunNpmBuild();
        }

        $this->report->printSummary();

        return 0;
    }

    private function printHeader(): void
    {
        echo str_repeat('=', 72) . PHP_EOL;
        echo $this->config['project_name'] . ' Installer' . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'Target directory: ' . $this->targetDir . PHP_EOL;
        echo 'Namespace: ' . $this->config['namespace'] . PHP_EOL;
        echo 'BackOffice: ' . ($this->features->backoffice ? 'enabled' : 'disabled') . PHP_EOL;
        echo 'Frontend: ' . ($this->features->frontend ? 'enabled' : 'disabled') . PHP_EOL;
        echo 'Mode: ' . ($this->options->dryRun ? 'dry-run' : 'write') . PHP_EOL;
        echo PHP_EOL;
    }

    private function guardTargetDirectory(): void
    {
        if (!is_dir($this->targetDir)) {
            throw new RuntimeException('Target directory does not exist: ' . $this->targetDir);
        }
    }

    private function maybeRunComposerInstall(): void
    {
        $autoInstall = (bool) ($this->config['composer']['auto_install'] ?? true);
        if ($this->options->skipComposer || !$autoInstall) {
            echo PHP_EOL . 'Composer install skipped.' . PHP_EOL;
            return;
        }

        // Resolve composer binary: system composer, then local composer.phar
        $composerBin = null;
        if ($this->commandExists('composer')) {
            $composerBin = 'composer';
        } elseif (is_file($this->targetDir . DIRECTORY_SEPARATOR . 'composer.phar')) {
            $composerBin = PHP_BINARY . ' composer.phar';
        } elseif (is_file(dirname($this->targetDir) . DIRECTORY_SEPARATOR . 'composer.phar')) {
            // Copy from parent directory
            $src = dirname($this->targetDir) . DIRECTORY_SEPARATOR . 'composer.phar';
            $dst = $this->targetDir . DIRECTORY_SEPARATOR . 'composer.phar';
            @copy($src, $dst);
            $composerBin = PHP_BINARY . ' composer.phar';
        }

        if ($composerBin === null) {
            echo PHP_EOL . 'Composer not found. Run `composer install` manually.' . PHP_EOL;
            return;
        }

        echo PHP_EOL . 'Running composer install...' . PHP_EOL;
        $cwd = getcwd();

        chdir($this->targetDir);
        passthru($composerBin . ' install --no-interaction', $exitCode);
        chdir($cwd === false ? $this->targetDir : $cwd);

        if ($exitCode !== 0) {
            echo 'Composer install exited with code ' . $exitCode . '. Continue manually if needed.' . PHP_EOL;
        }
    }

    private function maybeRunNpmBuild(): void
    {
        $autoNpm = (bool) ($this->config['npm']['auto_build'] ?? true);
        if (!$autoNpm) {
            echo PHP_EOL . 'npm build skipped.' . PHP_EOL;
            return;
        }

        if (!$this->commandExists('npm')) {
            echo PHP_EOL . 'npm not found in PATH. Run `npm install && npm run build` manually.' . PHP_EOL;
            return;
        }

        if (!is_file($this->targetDir . DIRECTORY_SEPARATOR . 'package.json')) {
            echo PHP_EOL . 'No package.json found. npm build skipped.' . PHP_EOL;
            return;
        }

        $cwd = getcwd();
        chdir($this->targetDir);

        echo PHP_EOL . 'Running npm install...' . PHP_EOL;
        passthru('npm install', $exitCode);

        if ($exitCode !== 0) {
            echo 'npm install exited with code ' . $exitCode . '. Continue manually if needed.' . PHP_EOL;
            chdir($cwd === false ? $this->targetDir : $cwd);
            return;
        }

        echo PHP_EOL . 'Running npm run build...' . PHP_EOL;
        passthru('npm run build', $exitCode);

        chdir($cwd === false ? $this->targetDir : $cwd);

        if ($exitCode !== 0) {
            echo 'npm run build exited with code ' . $exitCode . '. Continue manually if needed.' . PHP_EOL;
        } else {
            echo PHP_EOL . 'Frontend assets built successfully.' . PHP_EOL;
        }
    }

    private function commandExists(string $command): bool
    {
        $check = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($command)
            : 'command -v ' . escapeshellarg($command);

        exec($check . ' 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        return $realPath !== false ? $realPath : rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
