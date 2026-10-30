<?php

declare(strict_types=1);

/**
 * One-time CLI restoration for Le Comptoir after overwriting demo business
 * files with the generic generated app. The v2 MCP workspace is untouched.
 *
 * Plan:     php restore-comptoir-after-v2.php /absolute/app/root --plan
 * Apply:    php restore-comptoir-after-v2.php /absolute/app/root --apply
 * Undo:     php restore-comptoir-after-v2.php /absolute/app/root --rollback <snapshot_id>
 *
 * Never changes the database, stock, OAuth, CodeWorkspace or deployment history.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const RESTORE_FILES = [
    'app/Controllers/CatalogController.php' => [
        'draft' => 'c29e83aaf611b0eb8b4cf990ef12cab5',
        'current' => '7624291b053ab3384576180f4c86b7e1f58a18d78fa426bfcef5e8a7d737dcbb',
        'restore' => '6f8c11974760a6c24a99993c4886658d3664bafa8bc6d4dcb3ffac53d427771b',
    ],
    'app/Views/catalog/index.php' => [
        'draft' => 'f265b4d789fba96c3246489b0290e277',
        'current' => '0b1345a90f36c1e802918475646075fe921653b79880ee00b659d8f6d32fed76',
        'restore' => '09a14c59e6b1f4f3101e14eb51a57537aafba2c4d2f06ee45eb133a1ce9880ac',
    ],
    'app/Mcp/business_tools.php' => [
        'draft' => 'de588835c011451ce3df2f25b6bb1b48',
        'current' => '7f71cfd09e5e94bc1ab77d5c9bd3c7bf4037b3785c4cd6ba7a7817e1ca0c58a9',
        'restore' => 'cf118fd02262c7defcc14491cf741bc6dd2a128a8e149be3ea5fa64141738b93',
    ],
];

function failRestore(string $message): never
{
    throw new RuntimeException($message);
}

function fileHash(string $file): string
{
    if (is_link($file) || !is_file($file)) {
        failRestore('Missing or symlinked file: ' . $file);
    }
    $hash = hash_file('sha256', $file);
    if ($hash === false) {
        failRestore('Cannot hash ' . $file);
    }
    return $hash;
}

function replaceFile(string $filename, string $bytes): void
{
    $temp = tempnam(dirname($filename), '.comptoir-recovery-');
    if ($temp === false) {
        failRestore('Cannot stage file ' . $filename);
    }
    try {
        if (file_put_contents($temp, $bytes) !== strlen($bytes)) {
            failRestore('Incomplete temporary file: ' . $filename);
        }
        chmod($temp, fileperms($filename) & 0777);
        if (!rename($temp, $filename)) {
            failRestore('Cannot activate file ' . $filename);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($filename, true);
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}

function expectedDraft(string $storage, string $path, string $draftId, string $sha): string
{
    $draftPath = $storage . '/drafts/' . $draftId . '.json';
    if (is_link($draftPath) || !is_file($draftPath)) {
        failRestore('Missing original Atelier draft: ' . $draftId);
    }
    $draft = json_decode((string) file_get_contents($draftPath), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($draft) || ($draft['id'] ?? '') !== $draftId) {
        failRestore('Invalid deployment draft: ' . $draftId);
    }
    foreach ($draft['files'] ?? [] as $entry) {
        if (($entry['path'] ?? '') !== $path) {
            continue;
        }
        $body = $entry['content'] ?? null;
        if (!is_string($body) || !hash_equals($sha, hash('sha256', $body))
            || ($entry['after_sha256'] ?? '') !== $sha) {
            failRestore('Recovery draft corrupted: ' . $path);
        }
        try {
            token_get_all($body, TOKEN_PARSE);
        } catch (ParseError $error) {
            failRestore('Invalid original PHP syntax: ' . $error->getMessage());
        }
        return $body;
    }
    failRestore('Expected file absent from deployment draft: ' . $path);
}

try {
    $root = realpath((string) ($argv[1] ?? ''));
    $action = (string) ($argv[2] ?? '--plan');
    if ($root === false || !is_dir($root . '/app/storage/atelier')) {
        failRestore('Usage: php restore-comptoir-after-v2.php /absolute/app/root --plan|--apply|--rollback <id>');
    }
    $storage = $root . '/app/storage/atelier';
    $recovery = $storage . '/comptoir-recovery';
    if (is_link($recovery)) {
        failRestore('Symlinked recovery directory refused.');
    }
    if (!is_dir($recovery) && !mkdir($recovery, 0700, true) && !is_dir($recovery)) {
        failRestore('Cannot create private recovery directory.');
    }
    $lockPath = $storage . '/write.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        failRestore('Unable to lock code deployments.');
    }
    try {
        if ($action === '--rollback') {
            $id = (string) ($argv[3] ?? '');
            if (!preg_match('/^[a-f0-9]{16}$/D', $id)) {
                failRestore('Invalid recovery snapshot ID.');
            }
            $folder = $recovery . '/' . $id;
            $manifest = json_decode((string) file_get_contents($folder . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['id'] ?? '') !== $id) {
                failRestore('Invalid recovery manifest.');
            }
            // Verify every target before changing anything.
            foreach ($manifest['files'] as $entry) {
                $path = $entry['path'];
                if (!isset(RESTORE_FILES[$path])
                    || !hash_equals((string) $entry['after_sha256'], fileHash($root . '/' . $path))) {
                    failRestore('Recovery rollback conflict: ' . $path);
                }
                $backup = $folder . '/' . str_replace('/', '__', $path);
                if (!is_file($backup) || !hash_equals((string) $entry['before_sha256'], fileHash($backup))) {
                    failRestore('Recovery snapshot corrupted: ' . $path);
                }
            }
            foreach ($manifest['files'] as $entry) {
                replaceFile($root . '/' . $entry['path'],
                    (string) file_get_contents($folder . '/' . str_replace('/', '__', $entry['path'])));
            }
            echo "Restored the three pre-recovery source files from snapshot $id\n";
            exit(0);
        }

        if (!in_array($action, ['--plan', '--apply'], true)) {
            failRestore('Option must be --plan, --apply or --rollback <id>.');
        }

        $updates = [];
        foreach (RESTORE_FILES as $path => $config) {
            $live = $root . '/' . $path;
            $hash = fileHash($live);
            if (hash_equals($hash, $config['restore'])) {
                echo "ALREADY RESTORED $path\n";
                continue;
            }
            if (!hash_equals($hash, $config['current'])) {
                failRestore("Unexpected source changed, refusing overwrite: $path [$hash]");
            }
            $updates[$path] = expectedDraft($storage, $path, $config['draft'], $config['restore']);
            echo "RESTORE $path (" . strlen($updates[$path]) . " bytes)\n";
        }

        if ($action === '--plan' || $updates === []) {
            echo $updates === [] ? "No restoration necessary.\n" : "Plan verified. Run --apply to restore these files.\n";
            exit(0);
        }

        $id = bin2hex(random_bytes(8));
        $folder = $recovery . '/' . $id;
        if (!mkdir($folder, 0700)) {
            failRestore('Unable to create recovery snapshot folder.');
        }
        $manifest = ['id' => $id, 'created_at' => gmdate('c'), 'files' => []];
        foreach ($updates as $path => $content) {
            $backup = $folder . '/' . str_replace('/', '__', $path);
            if (!copy($root . '/' . $path, $backup)) {
                failRestore('Unable to backup current file ' . $path);
            }
            chmod($backup, 0600);
            $manifest['files'][] = [
                'path' => $path,
                'before_sha256' => fileHash($backup),
                'after_sha256' => hash('sha256', $content),
            ];
        }
        file_put_contents($folder . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($folder . '/manifest.json', 0600);
        foreach ($manifest['files'] as $entry) {
            $path = $entry['path'];
            if (!hash_equals($entry['before_sha256'], fileHash($root . '/' . $path))) {
                failRestore('Concurrent file change: ' . $path);
            }
        }
        try {
            foreach ($updates as $path => $bytes) {
                replaceFile($root . '/' . $path, $bytes);
            }
        } catch (Throwable $e) {
            foreach ($manifest['files'] as $entry) {
                $backup = $folder . '/' . str_replace('/', '__', $entry['path']);
                replaceFile($root . '/' . $entry['path'], (string) file_get_contents($backup));
            }
            throw $e;
        }
        echo "SUCCESS | restored Le Comptoir PHP without changing v2 engine or SQLite.\n";
        echo "Snapshot ID: $id\n";
        echo "If necessary, undo: php restore-comptoir-after-v2.php $root --rollback $id\n";
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
