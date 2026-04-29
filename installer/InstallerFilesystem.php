<?php

declare(strict_types=1);

final class InstallerFilesystem
{
    public function __construct(
        private readonly string $targetDir,
        private readonly bool $force,
        private readonly bool $dryRun,
    ) {
    }

    public function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return false;
        }

        if ($this->dryRun) {
            return true;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }

        return true;
    }

    public function writeFile(string $path, string $content): array
    {
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", rtrim($content) . "\n");
        $relativePath = $this->relativePath($path);

        if (is_file($path)) {
            $existingContent = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));

            if ($existingContent === $normalizedContent) {
                return ['status' => 'skipped', 'label' => $relativePath . ' (unchanged)'];
            }

            if (!$this->force) {
                return ['status' => 'skipped', 'label' => $relativePath . ' (exists)'];
            }

            $status = 'updated';
            $label = $relativePath;
        } else {
            $status = 'created';
            $label = $relativePath;
        }

        if ($this->dryRun) {
            return ['status' => $status, 'label' => $label];
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create parent directory for file: ' . $directory);
        }

        if (file_put_contents($path, $normalizedContent) === false) {
            throw new RuntimeException('Unable to write file: ' . $path);
        }

        return ['status' => $status, 'label' => $label];
    }

    public function ensureDatabaseFile(string $relativePath): void
    {
        $databasePath = $this->joinPath($this->targetDir, $relativePath);
        $directory = dirname($databasePath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create database directory: ' . $directory);
        }

        if (!is_file($databasePath) && file_put_contents($databasePath, '') === false) {
            throw new RuntimeException('Unable to create SQLite database file: ' . $databasePath);
        }
    }

    public function relativePath(string $absolutePath): string
    {
        $base = rtrim($this->targetDir, '\\/') . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $base)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($base)));
        }

        return $absolutePath;
    }

    private function joinPath(string ...$segments): string
    {
        $trimmed = [];
        foreach ($segments as $index => $segment) {
            $trimmed[] = $index === 0
                ? rtrim($segment, '\\/')
                : trim($segment, '\\/');
        }

        return implode(DIRECTORY_SEPARATOR, $trimmed);
    }
}
