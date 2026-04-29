<?php

declare(strict_types=1);

final class InstallerReport
{
    private array $created = [];

    private array $updated = [];

    private array $skipped = [];

    private int $directoriesPrepared = 0;

    public function recordDirectory(): void
    {
        $this->directoriesPrepared++;
    }

    public function recordFile(string $status, string $label): void
    {
        match ($status) {
            'created' => $this->created[] = $label,
            'updated' => $this->updated[] = $label,
            default => $this->skipped[] = $label,
        };
    }

    public function printSummary(): void
    {
        echo PHP_EOL . 'Summary' . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo 'Directories prepared: ' . $this->directoriesPrepared . PHP_EOL;
        echo 'Files created: ' . count($this->created) . PHP_EOL;
        echo 'Files updated: ' . count($this->updated) . PHP_EOL;
        echo 'Files skipped: ' . count($this->skipped) . PHP_EOL;

        if ($this->created !== []) {
            echo PHP_EOL . 'Created:' . PHP_EOL;
            foreach ($this->created as $file) {
                echo '  + ' . $file . PHP_EOL;
            }
        }

        if ($this->updated !== []) {
            echo PHP_EOL . 'Updated:' . PHP_EOL;
            foreach ($this->updated as $file) {
                echo '  ~ ' . $file . PHP_EOL;
            }
        }

        if ($this->skipped !== []) {
            echo PHP_EOL . 'Skipped:' . PHP_EOL;
            foreach ($this->skipped as $file) {
                echo '  - ' . $file . PHP_EOL;
            }
        }

        echo PHP_EOL . 'Next commands:' . PHP_EOL;
        echo '  php -l install-flight-starter.php' . PHP_EOL;
        echo '  php install-flight-starter.php --dry-run' . PHP_EOL;
        echo '  php install-flight-starter.php --force' . PHP_EOL;
        echo '  npm install' . PHP_EOL;
        echo '  npm run build' . PHP_EOL;
        echo '  php -S localhost:8000 -t public' . PHP_EOL;
        echo '  php app/Console/console.php migrate' . PHP_EOL;
        echo '  php app/Console/console.php queue:dispatch App\\Jobs\\ExampleJob {"message":"hello"}' . PHP_EOL;
        echo '  php app/Console/console.php queue:dispatch App\\Jobs\\SendMailJob {"to":"hello@example.com","subject":"Welcome","html":"<h1>Hello</h1>"}' . PHP_EOL;
        echo '  php app/Console/console.php queue:work --once' . PHP_EOL;
    }
}
