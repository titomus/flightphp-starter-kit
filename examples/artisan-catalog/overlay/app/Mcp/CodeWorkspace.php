<?php

declare(strict_types=1);

namespace App\Mcp;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Restricted, versioned code workspace for the artisan demo.
 *
 * This is a privileged code-deployment capability, NOT a security sandbox.
 * Only explicitly authorized administrators may invoke it. Core bootstrap,
 * OAuth, MCP transport, this class, configuration and database are off limits.
 *
 * Drafts and backups live outside public/ and contain executable PHP.
 */
final class CodeWorkspace
{
    private const MAX_FILES = 5;
    private const MAX_FILE_BYTES = 70000;
    private const MAX_TOTAL_BYTES = 200000;
    private const PATHS = [
        'app/Services/CatalogService.php',
        'app/Services/CatalogCsv.php',
        'app/Controllers/CatalogController.php',
        'app/Views/catalog/index.php',
        'app/Mcp/business_tools.php',
    ];

    private string $root;
    private string $storage;

    public function __construct(?string $root = null)
    {
        $this->root = realpath($root ?? dirname(__DIR__, 2))
            ?: throw new RuntimeException('Application root not found.');
        $this->storage = $this->root . '/app/storage/atelier';
        $this->mkdir($this->storage);
        $this->mkdir($this->storage . '/drafts');
        $this->mkdir($this->storage . '/releases');
    }

    public static function enabled(): bool
    {
        return filter_var((string) env('ATELIER_CODE_UPDATES_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function listFiles(): array
    {
        $paths = self::PATHS;
        $extensionDir = $this->root . '/app/Services/Extensions';
        if (is_dir($extensionDir) && !is_link($extensionDir)) {
            foreach (scandir($extensionDir) ?: [] as $name) {
                $relative = 'app/Services/Extensions/' . $name;
                if ($this->allowed($relative) && is_file($this->root . '/' . $relative)) {
                    $paths[] = $relative;
                }
            }
        }
        $files = [];
        foreach ($paths as $path) {
            $file = $this->resolve($path);
            $existing = is_file($file);
            $files[] = [
                'path' => $path,
                'exists' => $existing,
                'sha256' => $existing ? hash_file('sha256', $file) : '',
                'bytes' => $existing ? filesize($file) : 0,
            ];
        }
        return $files;
    }

    public function readFile(string $path): array
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw new InvalidArgumentException('File not found.');
        }
        if (filesize($file) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('File too large for this workspace.');
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('File could not be read.');
        }
        return ['path' => $path, 'sha256' => hash('sha256', $content), 'content' => $content];
    }

    /**
     * files: [{path, expected_sha256, content}]
     * expected_sha256 is '' for new files, otherwise SHA-256 from readFile.
     */
    public function prepare(int $adminId, string $summary, array $files): array
    {
        $this->assertAdmin($adminId);
        $summary = trim($summary);
        if (mb_strlen($summary) < 8 || mb_strlen($summary) > 140) {
            throw new InvalidArgumentException('Summary must be between 8 and 140 characters.');
        }
        if (count($files) < 1 || count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('Draft requires 1 to 5 files.');
        }
        $seen = [];
        $draftFiles = [];
        $total = 0;
        foreach ($files as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)
                || !is_string($entry['expected_sha256'] ?? null)
                || !is_string($entry['content'] ?? null)) {
                throw new InvalidArgumentException('Each file needs path, expected_sha256 and content.');
            }
            $path = $entry['path'];
            if (isset($seen[$path])) {
                throw new InvalidArgumentException('Duplicate file path.');
            }
            $seen[$path] = true;
            $originalPath = $this->resolve($path);
            $old = is_file($originalPath) ? file_get_contents($originalPath) : null;
            if ($old === false) {
                throw new RuntimeException('Cannot read original file.');
            }
            if ($old === null && !preg_match('~^app/Services/Extensions/[A-Z][A-Za-z0-9]{1,50}\.php$~D', $path)) {
                throw new InvalidArgumentException('Only business extension classes may be created.');
            }
            $before = $old === null ? '' : hash('sha256', $old);
            if (!hash_equals($before, $entry['expected_sha256'])) {
                throw new InvalidArgumentException('Stale file hash: ' . $path);
            }
            $content = $entry['content'];
            $size = strlen($content);
            $total += $size;
            if ($size === 0 || $size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES
                || str_contains($content, "\0")) {
                throw new InvalidArgumentException('Code size or content constraint violated.');
            }
            $this->checkPhp($path, $content);
            if ($old !== null && hash_equals($before, hash('sha256', $content))) {
                throw new InvalidArgumentException('No changes to ' . $path);
            }
            $draftFiles[] = [
                'path' => $path,
                'before_sha256' => $before,
                'after_sha256' => hash('sha256', $content),
                'content' => $content,
            ];
        }
        $id = bin2hex(random_bytes(16));
        $draft = [
            'id' => $id,
            'admin_id' => $adminId,
            'summary' => $summary,
            'status' => 'prepared',
            'created_at' => gmdate('c'),
            'files' => $draftFiles,
        ];
        $this->writeJson($this->storage . '/drafts/' . $id . '.json', $draft);
        return $this->summary($draft);
    }

    public function validate(int $adminId, string $id): array
    {
        $this->assertAdmin($adminId);
        $draft = $this->load('drafts', $id, $adminId);
        if ($draft['status'] !== 'prepared') {
            throw new InvalidArgumentException('Draft is not pending.');
        }
        $checks = [];
        $valid = true;
        foreach ($draft['files'] as $entry) {
            $path = $entry['path'];
            try {
                $file = $this->resolve($path);
                $now = is_file($file) ? hash_file('sha256', $file) : '';
                if (!hash_equals($entry['before_sha256'], $now)) {
                    throw new RuntimeException('Original changed since draft was prepared.');
                }
                $this->checkPhp($path, $entry['content']);
                $checks[] = ['path' => $path, 'valid' => true, 'syntax' => 'TOKEN_PARSE', 'sha256' => $entry['after_sha256']];
            } catch (Throwable $e) {
                $valid = false;
                $checks[] = ['path' => $path, 'valid' => false, 'error' => $e->getMessage()];
            }
        }
        return ['id' => $id, 'valid' => $valid, 'checks' => $checks,
            'warning' => 'Syntax validation is not a functional test. Deployment can execute privileged PHP.'];
    }

    public function deploy(int $adminId, string $id, bool $confirmed): array
    {
        $this->assertAdmin($adminId);
        if (!$confirmed) {
            throw new InvalidArgumentException('Explicit deploy confirmation is required.');
        }
        return $this->locked(function () use ($adminId, $id): array {
            $draft = $this->load('drafts', $id, $adminId);
            if ($draft['status'] !== 'prepared') {
                throw new InvalidArgumentException('Draft already processed.');
            }
            $backups = [];
            foreach ($draft['files'] as $entry) {
                $file = $this->resolve($entry['path']);
                $original = is_file($file) ? file_get_contents($file) : null;
                if ($original === false) {
                    throw new RuntimeException('Unable to read original.');
                }
                $hash = $original === null ? '' : hash('sha256', $original);
                if (!hash_equals($entry['before_sha256'], $hash)) {
                    throw new RuntimeException('Conflict: file changed since preparation: ' . $entry['path']);
                }
                $this->checkPhp($entry['path'], $entry['content']);
                $backups[] = [
                    'path' => $entry['path'],
                    'existed' => $original !== null,
                    'before_b64' => $original === null ? null : base64_encode($original),
                    'after_sha256' => $entry['after_sha256'],
                ];
            }
            // Snapshot is persisted before any active file is changed.
            $release = [
                'id' => $id, 'admin_id' => $adminId, 'summary' => $draft['summary'],
                'status' => 'deploying', 'created_at' => gmdate('c'), 'files' => $backups,
            ];
            $releaseFile = $this->storage . '/releases/' . $id . '.json';
            $this->writeJson($releaseFile, $release);
            try {
                foreach ($draft['files'] as $entry) {
                    $this->replace($entry['path'], $entry['content']);
                }
            } catch (Throwable $e) {
                foreach (array_reverse($backups) as $entry) {
                    $this->restore($entry);
                }
                $release['status'] = 'failed_restored';
                $this->writeJson($releaseFile, $release);
                throw new RuntimeException('Deployment failed and rollback attempted: ' . $e->getMessage(), 0, $e);
            }
            $release['status'] = 'deployed';
            $this->writeJson($releaseFile, $release);
            $draft['status'] = 'deployed';
            // A failed status update must not mask a deployed release.
            try {
                $this->writeJson($this->storage . '/drafts/' . $id . '.json', $draft);
            } catch (Throwable $ignored) {
                error_log('Atelier: draft status not updated after deployment ' . $id);
            }
            return ['id' => $id, 'status' => 'deployed', 'paths' => array_column($backups, 'path'),
                'rollback_available' => true];
        });
    }

    public function rollback(int $adminId, string $id, bool $confirmed): array
    {
        $this->assertAdmin($adminId);
        if (!$confirmed) {
            throw new InvalidArgumentException('Explicit rollback confirmation is required.');
        }
        return $this->locked(function () use ($adminId, $id): array {
            $release = $this->load('releases', $id, $adminId);
            if ($release['status'] !== 'deployed') {
                throw new InvalidArgumentException('Release is not currently deployed.');
            }
            // Never overwrite subsequent independent changes.
            foreach ($release['files'] as $entry) {
                $file = $this->resolve($entry['path']);
                $current = is_file($file) ? hash_file('sha256', $file) : '';
                if (!hash_equals($entry['after_sha256'], $current)) {
                    throw new RuntimeException('Rollback conflict: ' . $entry['path']);
                }
            }
            foreach (array_reverse($release['files']) as $entry) {
                $this->restore($entry);
            }
            $release['status'] = 'rolled_back';
            $release['rolled_back_at'] = gmdate('c');
            $this->writeJson($this->storage . '/releases/' . $id . '.json', $release);
            return ['id' => $id, 'status' => 'rolled_back',
                'paths' => array_column($release['files'], 'path')];
        });
    }

    /**
     * Emergency SSH/cPanel recovery if a PHP process died during a multi-file
     * deployment. Intentionally NOT available from MCP or web requests.
     * Restores every original file even when only some target files changed.
     */
    public function emergencyRecover(int $adminId, string $id): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Emergency recovery is CLI-only.');
        }
        $this->assertAdmin($adminId);
        return $this->locked(function () use ($adminId, $id): array {
            $release = $this->load('releases', $id, $adminId);
            if (!in_array($release['status'], ['deploying', 'deployed', 'failed_restored'], true)) {
                throw new InvalidArgumentException('Release is not eligible for emergency recovery.');
            }
            foreach (array_reverse($release['files']) as $entry) {
                $this->restore($entry);
            }
            $release['status'] = 'emergency_rolled_back';
            $release['rolled_back_at'] = gmdate('c');
            $this->writeJson($this->storage . '/releases/' . $id . '.json', $release);
            return ['id' => $id, 'status' => 'emergency_rolled_back',
                'paths' => array_column($release['files'], 'path')];
        });
    }

    public function history(int $adminId, int $limit = 20): array
    {
        $this->assertAdmin($adminId);
        $limit = max(1, min(50, $limit));
        $found = [];
        foreach (glob($this->storage . '/releases/*.json') ?: [] as $file) {
            $release = $this->readJson($file);
            if ((int) ($release['admin_id'] ?? 0) === $adminId) {
                $found[] = ['id' => $release['id'], 'summary' => $release['summary'],
                    'status' => $release['status'], 'created_at' => $release['created_at'],
                    'paths' => array_column($release['files'], 'path')];
            }
        }
        usort($found, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return array_slice($found, 0, $limit);
    }

    private function summary(array $draft): array
    {
        return [
            'id' => $draft['id'], 'summary' => $draft['summary'],
            'status' => $draft['status'], 'created_at' => $draft['created_at'],
            'files' => array_map(static fn (array $f): array =>
                ['path' => $f['path'], 'before_sha256' => $f['before_sha256'],
                    'after_sha256' => $f['after_sha256'], 'bytes' => strlen($f['content'])], $draft['files']),
        ];
    }

    private function assertAdmin(int $adminId): void
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('Admin permission required.');
        }
    }

    private function allowed(string $path): bool
    {
        return in_array($path, self::PATHS, true)
            || preg_match('~^app/Services/Extensions/[A-Z][A-Za-z0-9]{1,50}\.php$~D', $path) === 1;
    }

    private function resolve(string $path): string
    {
        if (!$this->allowed($path)) {
            throw new InvalidArgumentException('Protected or unsupported path.');
        }
        $current = $this->root;
        foreach (explode('/', $path) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new InvalidArgumentException('Symbolic links are forbidden.');
            }
        }
        if (file_exists($current) && !is_file($current)) {
            throw new InvalidArgumentException('Target is not a regular file.');
        }
        return $current;
    }

    private function checkPhp(string $path, string $content): void
    {
        if (!str_starts_with(ltrim($content), '<?php')
            && !str_starts_with($path, 'app/Views/')) {
            throw new InvalidArgumentException('PHP source must start with <?php.');
        }
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new InvalidArgumentException('PHP parse error: ' . $e->getMessage());
        }
        // TOKEN_PARSE validates syntax but is not a substitute for unit tests.
    }

    private function replace(string $path, string $content): void
    {
        $target = $this->resolve($path);
        $directory = dirname($target);
        $this->mkdir($directory);
        $tmp = tempnam($directory, '.atelier-');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create staging file.');
        }
        try {
            if (file_put_contents($tmp, $content) !== strlen($content)) {
                throw new RuntimeException('Incomplete staging write.');
            }
            chmod($tmp, is_file($target) ? (fileperms($target) & 0777) : 0644);
            if (!rename($tmp, $target)) {
                throw new RuntimeException('Unable to activate staged file.');
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($target, true);
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function restore(array $entry): void
    {
        $file = $this->resolve($entry['path']);
        if (!$entry['existed']) {
            if (is_file($file)) {
                unlink($file);
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            return;
        }
        $original = base64_decode((string) $entry['before_b64'], true);
        if (!is_string($original)) {
            throw new RuntimeException('Snapshot damaged.');
        }
        $this->replace($entry['path'], $original);
    }

    private function locked(callable $callback): array
    {
        $lock = fopen($this->storage . '/write.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open deployment lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire deployment lock.');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(string $kind, string $id, int $adminId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Invalid version ID.');
        }
        $file = $this->storage . '/' . $kind . '/' . $id . '.json';
        if (!is_file($file)) {
            throw new InvalidArgumentException('Version not found.');
        }
        $data = $this->readJson($file);
        if ((int) ($data['admin_id'] ?? 0) !== $adminId) {
            throw new InvalidArgumentException('Version does not belong to this administrator.');
        }
        return $data;
    }

    private function readJson(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid version record.');
        }
        return $data;
    }

    private function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = tempnam(dirname($file), '.atelier-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot stage record.');
        }
        try {
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Cannot write version record.');
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $file)) {
                throw new RuntimeException('Cannot commit version record.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function mkdir(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Storage directory must not be a symlink.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create private directory.');
        }
    }
}
