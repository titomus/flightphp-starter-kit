<?php
declare(strict_types=1);

namespace CodeGraft;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Multi-file, SHA-locked code workspace. No framework dependencies.
 * Safe defaults are READ/WRITE DENIED; only site owner can modify policy.
 *
 * This is not a transactional filesystem: on process death, recover() via CLI
 * is required. Backups are saved BEFORE the first live file is replaced.
 */
final class Workspace
{
    public function __construct(private readonly Vault $vault)
    {
    }

    public function policy(): array
    {
        return $this->vault->policy();
    }

    public function listFiles(int $limit = 80): array
    {
        $policy = $this->checked('list');
        $result = [];
        foreach ($this->walk($policy) as $path) {
            $full = $this->resolve($path, $policy);
            $result[] = [
                'path' => $path,
                'bytes' => filesize($full),
                'sha256' => hash_file('sha256', $full),
            ];
            if (count($result) >= max(1, min(200, $limit))) break;
        }
        return ['files' => $result, 'limit' => min(200, max(1, $limit))];
    }

    public function search(string $needle, int $limit = 30): array
    {
        $policy = $this->checked('search');
        if (strlen($needle) < 2 || strlen($needle) > 120 || str_contains($needle, "\0")) {
            throw new InvalidArgumentException('Search requires 2 to 120 text characters.');
        }
        $found = [];
        foreach ($this->walk($policy) as $path) {
            $full = $this->resolve($path, $policy);
            if (filesize($full) > (int) $policy['max_file_bytes']) continue;
            $data = file_get_contents($full);
            if (!is_string($data) || str_contains($data, "\0")) continue;
            $lines = explode("\n", $data);
            foreach ($lines as $idx => $line) {
                if (stripos($line, $needle) === false) continue;
                $found[] = ['path' => $path, 'line' => $idx + 1,
                    'excerpt' => mb_substr(trim($line), 0, 240)];
                if (count($found) >= max(1, min(100, $limit))) break 2;
            }
        }
        return ['matches' => $found, 'query' => $needle];
    }

    public function readFiles(array $paths): array
    {
        $policy = $this->checked('read');
        if (count($paths) < 1 || count($paths) > 20) {
            throw new InvalidArgumentException('Read between 1 and 20 files.');
        }
        $result = [];
        foreach (array_unique($paths) as $path) {
            if (!is_string($path)) throw new InvalidArgumentException('Invalid path.');
            $full = $this->resolve($path, $policy);
            if (!is_file($full)) throw new InvalidArgumentException('Missing file: ' . $path);
            if (filesize($full) > (int) $policy['max_file_bytes']) {
                throw new InvalidArgumentException('File exceeds allowed size: ' . $path);
            }
            $bytes = file_get_contents($full);
            if (!is_string($bytes) || str_contains($bytes, "\0")) {
                throw new RuntimeException('Non-text file cannot be read.');
            }
            $result[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'content' => $bytes];
        }
        return ['files' => $result];
    }

    public function prepare(string $summary, array $files): array
    {
        $policy = $this->checked('prepare');
        if (mb_strlen(trim($summary)) < 8 || mb_strlen($summary) > 160
            || count($files) < 1 || count($files) > (int) $policy['max_files_per_patch']) {
            throw new InvalidArgumentException('Invalid patch summary or file count.');
        }
        $seen = [];
        $items = [];
        $total = 0;
        foreach ($files as $input) {
            if (!is_array($input) || !is_string($input['path'] ?? null)
                || !is_string($input['expected_sha256'] ?? null)
                || !is_string($input['content'] ?? null)) {
                throw new InvalidArgumentException('Each file needs path, expected_sha256, content.');
            }
            $path = $input['path'];
            if (isset($seen[$path])) throw new InvalidArgumentException('Repeated patch target.');
            $seen[$path] = true;
            $full = $this->resolve($path, $policy, true);
            $previous = is_file($full) ? file_get_contents($full) : null;
            if ($previous === false) throw new RuntimeException('Cannot read previous file.');
            $expected = $previous === null ? '' : hash('sha256', $previous);
            if (!hash_equals($expected, $input['expected_sha256'])) {
                throw new RuntimeException('Stale file SHA-256: ' . $path);
            }
            $body = $input['content'];
            $length = strlen($body);
            $total += $length;
            if ($length === 0 || $length > (int)$policy['max_file_bytes']
                || $total > (int)$policy['max_patch_bytes'] || str_contains($body, "\0")) {
                throw new InvalidArgumentException('Patch body too large or binary.');
            }
            $this->syntax($path, $body);
            if ($previous !== null && hash_equals($expected, hash('sha256', $body))) {
                throw new InvalidArgumentException('No changes in ' . $path);
            }
            $items[] = [
                'path' => $path,
                'before_sha256' => $expected,
                'after_sha256' => hash('sha256', $body),
                'content' => $body,
            ];
        }
        $id = bin2hex(random_bytes(16));
        $draft = ['id' => $id, 'status' => 'prepared', 'summary' => trim($summary),
            'created_at' => gmdate('c'), 'expires_at' => time() + 86400, 'files' => $items];
        $this->vault->put('drafts/' . $id . '.json', $draft);
        return ['id' => $id, 'status' => 'prepared', 'files' => array_map(
            static fn (array $f): array => [
                'path' => $f['path'], 'before_sha256' => $f['before_sha256'],
                'after_sha256' => $f['after_sha256'],
            ], $items),
            'warning' => 'Live PHP modifications can execute code. Validate and confirm deployment explicitly.',
        ];
    }

    public function validate(string $id): array
    {
        $policy = $this->checked('validate');
        $draft = $this->draft($id);
        $checks = [];
        foreach ($draft['files'] as $entry) {
            try {
                $full = $this->resolve((string)$entry['path'], $policy, true);
                $now = is_file($full) ? hash_file('sha256', $full) : '';
                if (!hash_equals((string)$entry['before_sha256'], (string)$now)) {
                    throw new RuntimeException('File changed since preparation.');
                }
                $this->syntax((string)$entry['path'], (string)$entry['content']);
                $checks[] = ['path' => $entry['path'], 'valid' => true];
            } catch (Throwable $e) {
                $checks[] = ['path' => $entry['path'], 'valid' => false, 'reason' => $e->getMessage()];
            }
        }
        return ['id' => $id, 'valid' => !in_array(false, array_column($checks, 'valid'), true),
            'checks' => $checks, 'warning' => 'PHP syntax validation is not an HTTP or functional test.'];
    }

    public function deploy(string $id, bool $confirmed): array
    {
        if (!$confirmed) throw new InvalidArgumentException('Explicit confirmation required.');
        return $this->vault->locked(function () use ($id): array {
            $policy = $this->checked('deploy');
            $draft = $this->draft($id);
            $backup = [];
            foreach ($draft['files'] as $entry) {
                $full = $this->resolve((string)$entry['path'], $policy, true);
                $original = is_file($full) ? file_get_contents($full) : null;
                if ($original === false) throw new RuntimeException('Cannot snapshot original.');
                $hash = $original === null ? '' : hash('sha256', $original);
                if (!hash_equals((string)$entry['before_sha256'], $hash)) {
                    throw new RuntimeException('SHA-256 conflict at deployment: ' . $entry['path']);
                }
                $this->syntax((string)$entry['path'], (string)$entry['content']);
                $backup[] = [
                    'path' => $entry['path'],
                    'existed' => $original !== null,
                    'before_sha256' => $hash,
                    'before_base64' => $original === null ? null : base64_encode($original),
                    'after_sha256' => $entry['after_sha256'],
                ];
            }
            $release = ['id' => $id, 'status' => 'deploying',
                'summary' => $draft['summary'], 'created_at' => gmdate('c'), 'files' => $backup];
            $this->vault->put('releases/' . $id . '.json', $release);
            try {
                foreach ($draft['files'] as $entry) {
                    $this->replace((string)$entry['path'], (string)$entry['content'], $policy);
                }
            } catch (Throwable $e) {
                $failed = false;
                foreach (array_reverse($backup) as $item) {
                    try {
                        $this->restore($item, $policy);
                    } catch (Throwable) {
                        $failed = true;
                    }
                }
                $release['status'] = $failed ? 'emergency_recovery_required' : 'failed_restored';
                $this->vault->put('releases/' . $id . '.json', $release);
                throw new RuntimeException('Deployment failed; recovery status: ' . $release['status'], 0, $e);
            }
            $release['status'] = 'deployed';
            $release['deployed_at'] = gmdate('c');
            $this->vault->put('releases/' . $id . '.json', $release);
            $draft['status'] = 'deployed';
            $draft['files'] = []; // Do not retain redundant new source after release.
            $this->vault->put('drafts/' . $id . '.json', $draft);
            return ['id' => $id, 'status' => 'deployed',
                'paths' => array_column($backup, 'path'), 'rollback_available' => true];
        });
    }

    public function rollback(string $id, bool $confirmed): array
    {
        if (!$confirmed) throw new InvalidArgumentException('Explicit rollback confirmation required.');
        return $this->vault->locked(function () use ($id): array {
            $policy = $this->checked('rollback');
            $this->assertId($id);
            $release = $this->vault->json('releases/' . $id . '.json');
            if (($release['status'] ?? null) !== 'deployed') {
                throw new InvalidArgumentException('Release not eligible for rollback.');
            }
            foreach ($release['files'] as $item) {
                $full = $this->resolve((string)$item['path'], $policy, true);
                $current = is_file($full) ? hash_file('sha256', $full) : '';
                if (!hash_equals((string)$item['after_sha256'], (string)$current)) {
                    throw new RuntimeException('Rollback refused: newer changes on ' . $item['path']);
                }
            }
            foreach (array_reverse($release['files']) as $item) {
                $this->restore($item, $policy);
            }
            $release['status'] = 'rolled_back';
            $release['rolled_back_at'] = gmdate('c');
            $this->vault->put('releases/' . $id . '.json', $release);
            return ['id' => $id, 'status' => 'rolled_back',
                'paths' => array_column($release['files'], 'path')];
        });
    }

    public function history(int $limit = 20): array
    {
        $this->checked('history');
        $all = glob($this->vault->directory . '/releases/*.json') ?: [];
        rsort($all);
        $rows = [];
        foreach ($all as $name) {
            if (is_link($name)) continue;
            $data = $this->vault->json('releases/' . basename($name));
            $rows[] = [
                'id' => $data['id'] ?? '',
                'status' => $data['status'] ?? '',
                'summary' => $data['summary'] ?? '',
                'created_at' => $data['created_at'] ?? '',
                'paths' => array_column($data['files'] ?? [], 'path'),
            ];
        }
        usort($rows, static fn (array $a, array $b): int =>
            strcmp((string)$b['created_at'], (string)$a['created_at']));
        return ['releases' => array_slice($rows, 0, max(1, min(100, $limit)))];
    }

    /** Last-resort CLI recovery after process termination; not exposed over MCP. */
    public function emergencyRecover(string $id): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
        $this->assertId($id);
        return $this->vault->locked(function () use ($id): array {
            $release = $this->vault->json('releases/' . $id . '.json');
            if (!in_array($release['status'] ?? null, ['deploying','deployed','emergency_recovery_required'], true)) {
                throw new InvalidArgumentException('Not recoverable.');
            }
            $policy = $this->vault->policy();
            foreach (array_reverse($release['files']) as $entry) {
                $this->restore($entry, $policy);
            }
            $release['status'] = 'emergency_rolled_back';
            $this->vault->put('releases/' . $id . '.json', $release);
            return ['id' => $id, 'status' => 'emergency_rolled_back'];
        });
    }

    private function checked(string $permission): array
    {
        $policy = $this->vault->policy();
        Policy::check($policy, $permission);
        return $policy;
    }

    private function draft(string $id): array
    {
        $this->assertId($id);
        $draft = $this->vault->json('drafts/' . $id . '.json');
        if (($draft['status'] ?? null) !== 'prepared' || time() > (int)($draft['expires_at'] ?? 0)) {
            throw new InvalidArgumentException('Draft unavailable or expired.');
        }
        return $draft;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('Invalid ID.');
    }

    private function resolve(string $path, array $policy, bool $write = false): string
    {
        Policy::path($policy, $path, $write);
        $current = $this->vault->root;
        foreach (explode('/', $path) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) throw new InvalidArgumentException('Symlinks cannot be traversed.');
        }
        if (file_exists($current) && !is_file($current)) {
            throw new InvalidArgumentException('Not a regular file.');
        }
        return $current;
    }

    private function syntax(string $path, string $body): void
    {
        if (str_ends_with(strtolower($path), '.php')) {
            try {
                token_get_all($body, TOKEN_PARSE);
            } catch (\ParseError $e) {
                throw new InvalidArgumentException('PHP parse error: ' . $e->getMessage());
            }
        }
    }

    private function replace(string $path, string $body, array $policy): void
    {
        $file = $this->resolve($path, $policy, true);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create target directory.');
        }
        $temp = tempnam($dir, '.cg-stage-');
        if ($temp === false) throw new RuntimeException('Cannot stage file.');
        try {
            if (file_put_contents($temp, $body) !== strlen($body)) {
                throw new RuntimeException('Incomplete staged file.');
            }
            chmod($temp, is_file($file) ? fileperms($file) & 0777 : 0644);
            if (!rename($temp, $file)) throw new RuntimeException('Cannot activate file.');
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }

    private function restore(array $entry, array $policy): void
    {
        $file = $this->resolve((string)$entry['path'], $policy, true);
        if (($entry['existed'] ?? false) === false) {
            if (is_file($file) && !unlink($file)) throw new RuntimeException('Cannot remove new file.');
            return;
        }
        $old = base64_decode((string)($entry['before_base64'] ?? ''), true);
        if ($old === false || !hash_equals((string)$entry['before_sha256'], hash('sha256', $old))) {
            throw new RuntimeException('Backup corrupted.');
        }
        $this->replace((string)$entry['path'], $old, $policy);
    }

    private function walk(array $policy): \Generator
    {
        $visited = 0;
        foreach ($policy['read_dirs'] as $relative) {
            $start = $this->vault->root . '/' . $relative;
            if (!is_dir($start) || is_link($start)) continue;
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $info) {
                if (++$visited > 3000) return;
                if (!$info->isFile() || $info->isLink()) continue;
                $path = substr($info->getPathname(), strlen($this->vault->root) + 1);
                try {
                    Policy::path($policy, $path);
                    // Check symlink parents, e.g. ../plugins/external symlink directory.
                    $this->resolve($path, $policy);
                } catch (Throwable) {
                    continue;
                }
                yield $path;
            }
        }
    }
}
