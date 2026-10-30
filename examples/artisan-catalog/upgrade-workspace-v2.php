<?php

declare(strict_types=1);

/**
 * Flight Starter Kit v2 — ONE-TIME, CLI-ONLY upgrade of Atelier Catalogue's
 * protected development engine, preserving all business code and SQLite data.
 *
 * Preview: php upgrade-workspace-v2.php /absolute/app/root --plan
 * Apply:   php upgrade-workspace-v2.php /absolute/app/root --apply
 * Recover: php upgrade-workspace-v2.php /absolute/app/root --rollback <id>
 *
 * Installed business screens, inventory, OAuth and MCP tools are NOT replaced.
 * Dedicated storage backups survive even if the MCP endpoint is down.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const UPGRADE_MARKER = 'Flight Starter v2 workspace upgrade';
const TARGETS = [
    'app/Mcp/CodeWorkspace.php',
    'app/Mcp/tools.php',
    'app/bootstrap.php',
    'app/Views/layouts/main.php',
    'app/routes/business.php',
    'public/assets/business.css',
    'public/assets/business.js',
];

function upgradeFail(string $message): never
{
    throw new RuntimeException($message);
}

function replaceOnce(string $source, string $before, string $after, string $description): string
{
    $found = substr_count($source, $before);
    if ($found !== 1) {
        upgradeFail($description . ': expected one exact anchor; found ' . $found);
    }
    return str_replace($before, $after, $source);
}

function buildWorkspace(string $old): string
{
    if (str_contains($old, UPGRADE_MARKER)) {
        upgradeFail('Already upgraded. Do not run --apply twice.');
    }
    $old = replaceOnce($old,
        '    private const MAX_FILES = 5;',
        "    // " . UPGRADE_MARKER . "\n    private const MAX_FILES = 12;",
        'Workspace file count'
    );
    $old = replaceOnce($old,
        '    private const MAX_TOTAL_BYTES = 200000;',
        '    private const MAX_TOTAL_BYTES = 500000;',
        'Workspace patch size'
    );
    $old = replaceOnce($old,
        "        'app/Mcp/business_tools.php',",
        "        'app/Mcp/business_tools.php',\n" .
        "        'app/routes/business.php',\n" .
        "        'public/assets/business.css',\n" .
        "        'public/assets/business.js',",
        'Client business file list'
    );
    $old = replaceOnce($old,
        'Draft requires 1 to 5 files.',
        'Draft requires 1 to 12 files.',
        'Workspace validation message'
    );

    $newDirectories = <<<'PHP'
        // Domain-specific MVC code. The framework core and deployment
        // engine are intentionally not writable via MCP.
        foreach ([
            'app/Controllers/Business',
            'app/Services/Business',
            'app/Models/Business',
            'app/Views/business',
        ] as $relativeDirectory) {
            $directory = $this->root . '/' . $relativeDirectory;
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $entry) {
                $relative = $relativeDirectory . '/' . $entry;
                if ($this->allowed($relative) && is_file($directory . '/' . $entry)
                    && !is_link($directory . '/' . $entry)) {
                    $paths[] = $relative;
                }
            }
        }

PHP;
    $old = replaceOnce($old,
        "        \$files = [];\n        foreach (\$paths as \$path) {",
        $newDirectories . "        \$files = [];\n        foreach (\$paths as \$path) {",
        'Workspace business directory scan'
    );

    $creationStart = strpos($old, 'if ($old === null && !preg_match(');
    if ($creationStart === false) {
        upgradeFail('Existing workspace new-file restriction not found.');
    }
    $creationEnd = strpos($old, ") {\n", $creationStart);
    if ($creationEnd === false || $creationEnd - $creationStart > 250) {
        upgradeFail('Could not safely locate existing creation restriction.');
    }
    $old = substr_replace($old,
        'if ($old === null && !$this->creatable($path)) {',
        $creationStart,
        $creationEnd + 3 - $creationStart
    );

    $allowedStart = strpos($old, "    private function allowed(string \$path): bool\n");
    $allowedEnd = strpos($old, "    private function resolve(string \$path): string\n", $allowedStart);
    if ($allowedStart === false || $allowedEnd === false || $allowedEnd <= $allowedStart) {
        upgradeFail('CodeWorkspace path authorization boundaries changed.');
    }
    $newAllowed = <<<'PHP'
    private function allowed(string $path): bool
    {
        return in_array($path, self::PATHS, true)
            || preg_match('~^app/Services/Extensions/[A-Z][A-Za-z0-9]{1,50}\.php$~D', $path) === 1
            || preg_match('~^app/(?:Controllers|Services|Models)/Business/[A-Z][A-Za-z0-9]{1,60}\.php$~D', $path) === 1
            || preg_match('~^app/Views/business/[a-z][a-z0-9_-]{0,60}\.php$~D', $path) === 1;
    }

    private function creatable(string $path): bool
    {
        // Existing generated entrypoints may be replaced but never invented.
        return !in_array($path, self::PATHS, true) && $this->allowed($path);
    }

PHP;
    $old = substr_replace($old, $newAllowed, $allowedStart, $allowedEnd - $allowedStart);
    $old = replaceOnce($old,
        "    private function checkPhp(string \$path, string \$content): void\n    {\n",
        "    private function checkPhp(string \$path, string \$content): void\n    {\n" .
        "        // Static business assets are text, not PHP; no server-side execution.\n" .
        "        if (str_starts_with(\$path, 'public/assets/')) {\n            return;\n        }\n",
        'Business asset syntax validation'
    );
    return $old;
}

function prepareUpgrade(string $root): array
{
    $workspace = $root . '/app/Mcp/CodeWorkspace.php';
    $registry = $root . '/app/Mcp/tools.php';
    $bootstrap = $root . '/app/bootstrap.php';
    $layout = $root . '/app/Views/layouts/main.php';
    foreach ([$workspace, $registry, $bootstrap, $layout] as $file) {
        if (is_link($file) || !is_file($file)) {
            upgradeFail('Expected regular source file missing or symlinked: ' . $file);
        }
    }

    $oldWorkspace = (string) file_get_contents($workspace);
    $newWorkspace = buildWorkspace($oldWorkspace);
    $oldRegistry = (string) file_get_contents($registry);
    $newRegistry = replaceOnce($oldRegistry,
        "'maxItems' => 5, 'items' => \$fileSchema",
        "'maxItems' => 12, 'items' => \$fileSchema",
        'MCP schema patch count'
    );
    $newRegistry = replaceOnce($newRegistry,
        'for 1-5 allowlisted files',
        'for 1-12 allowlisted files',
        'MCP tool description'
    );
    $oldBootstrap = (string) file_get_contents($bootstrap);
    if (str_contains($oldBootstrap, "require \$basePath . '/app/routes/business.php';")) {
        upgradeFail('Business routes are already registered; review this installation manually.');
    }
    $newBootstrap = replaceOnce($oldBootstrap,
        "    require \$basePath . '/app/routes/admin_generated.php';",
        "    require \$basePath . '/app/routes/admin_generated.php';\n" .
        "    if (is_file(\$basePath . '/app/routes/business.php')) {\n" .
        "        require \$basePath . '/app/routes/business.php';\n" .
        "    }",
        'Flight route registration'
    );
    $oldLayout = (string) file_get_contents($layout);
    if (str_contains($oldLayout, 'assets/business.css')
        || str_contains($oldLayout, 'assets/business.js')) {
        upgradeFail('Business assets are already registered in the layout.');
    }
    $newLayout = replaceOnce($oldLayout,
        "    <?= vite_tags(['resources/js/app.js']) ?>",
        "    <?= vite_tags(['resources/js/app.js']) ?>\n" .
        "    <?php if (is_file(public_path('assets/business.css'))): ?><link rel=\"stylesheet\" href=\"/assets/business.css\"><?php endif; ?>",
        'Business CSS layout integration'
    );
    $newLayout = replaceOnce($newLayout,
        '</body>',
        "<?php if (is_file(public_path('assets/business.js'))): ?>" .
        "<script defer src=\"/assets/business.js\"></script><?php endif; ?>\n</body>",
        'Business JS layout integration'
    );

    // We do not touch the installed catalog views, inventory, tools or database.
    $updates = [
        'app/Mcp/CodeWorkspace.php' => $newWorkspace,
        'app/Mcp/tools.php' => $newRegistry,
        'app/bootstrap.php' => $newBootstrap,
        'app/Views/layouts/main.php' => $newLayout,
    ];
    $newFiles = [
        'app/routes/business.php' => <<<'PHP'
<?php

declare(strict_types=1);

// New client features: FlightPHP routes only. Keep domain rules in services.
// These routes are loaded alongside the original catalog routes.
PHP
,
        'public/assets/business.css' => "/* Client business styles: managed by MCP Atelier v2. */\n",
        'public/assets/business.js' => "// Client business interactions: managed by MCP Atelier v2.\n",
    ];
    foreach ($newFiles as $path => $body) {
        $target = $root . '/' . $path;
        if (is_link($target) || file_exists($target)) {
            upgradeFail('New v2 scaffold already exists, refusing overwrite: ' . $path);
        }
        $updates[$path] = $body;
    }

    foreach ($updates as $path => $body) {
        if (str_ends_with($path, '.php')) {
            try {
                token_get_all($body, TOKEN_PARSE);
            } catch (ParseError $e) {
                upgradeFail('Invalid generated PHP for ' . $path . ': ' . $e->getMessage());
            }
        }
    }
    return $updates;
}

function writeAtomically(string $path, string $body): void
{
    $directory = dirname($path);
    if (is_link($directory)) {
        upgradeFail('Symlinked destination directory not allowed: ' . $directory);
    }
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        upgradeFail('Cannot create ' . $directory);
    }
    $temporary = tempnam($directory, '.flight-v2-');
    if ($temporary === false) {
        upgradeFail('Could not stage ' . $path);
    }
    try {
        if (file_put_contents($temporary, $body) !== strlen($body)) {
            upgradeFail('Incomplete write: ' . $path);
        }
        chmod($temporary, is_file($path) ? (fileperms($path) & 0777) : 0644);
        if (!rename($temporary, $path)) {
            upgradeFail('Could not activate ' . $path);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function rollbackUpgrade(string $root, string $id): void
{
    if (!preg_match('/^[a-f0-9]{16}$/D', $id)) {
        upgradeFail('Invalid upgrade snapshot id.');
    }
    $folder = $root . '/app/storage/atelier/upgrades/' . $id;
    if (!is_file($folder . '/manifest.json')) {
        upgradeFail('Snapshot not found.');
    }
    $manifest = json_decode((string) file_get_contents($folder . '/manifest.json'), true, 10, JSON_THROW_ON_ERROR);
    $allowed = array_fill_keys(TARGETS, true);
    foreach ($manifest['files'] ?? [] as $item) {
        $path = (string) ($item['path'] ?? '');
        if (!isset($allowed[$path])) {
            upgradeFail('Unrecognized backup path.');
        }
        $target = $root . '/' . $path;
        if (is_link($target)) {
            upgradeFail('Refusing symbolic link: ' . $path);
        }
        if (!empty($item['existed'])) {
            $backup = $folder . '/' . str_replace('/', '__', $path);
            if (!is_file($backup)) {
                upgradeFail('Snapshot file missing: ' . $path);
            }
            writeAtomically($target, (string) file_get_contents($backup));
        } elseif (is_file($target)) {
            unlink($target);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($target, true);
            }
        }
    }
    echo "Upgrade restored from " . $id . PHP_EOL;
}

try {
    $root = realpath((string) ($argv[1] ?? ''));
    if (!$root || !is_file($root . '/app/Mcp/CodeWorkspace.php')) {
        upgradeFail('Usage: php upgrade-workspace-v2.php /absolute/app/root --plan|--apply|--rollback <id>');
    }
    $action = (string) ($argv[2] ?? '--plan');
    $storage = $root . '/app/storage/atelier/upgrades';
    if (is_link($storage)) {
        upgradeFail('Symlinked upgrades storage refused.');
    }
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        upgradeFail('Cannot create private snapshot directory.');
    }
    $lock = fopen($storage . '/upgrade.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        upgradeFail('Cannot acquire upgrade lock.');
    }
    try {
        if ($action === '--rollback') {
            rollbackUpgrade($root, (string) ($argv[3] ?? ''));
        } elseif (in_array($action, ['--plan', '--apply'], true)) {
            $updates = prepareUpgrade($root);
            echo UPGRADE_MARKER . ' | root: ' . $root . PHP_EOL;
            foreach ($updates as $relative => $body) {
                $existing = is_file($root . '/' . $relative);
                echo ($existing ? 'REPLACE ' : 'CREATE ') . $relative .
                    ' ' . strlen($body) . ' bytes' . PHP_EOL;
            }
            if ($action === '--apply') {
                $id = bin2hex(random_bytes(8));
                $folder = $storage . '/' . $id;
                if (!mkdir($folder, 0700)) {
                    upgradeFail('Cannot create immutable upgrade snapshot');
                }
                $manifest = ['version' => 2, 'id' => $id, 'created_at' => gmdate('c'), 'files' => []];
                foreach ($updates as $relative => $body) {
                    $target = $root . '/' . $relative;
                    $exists = is_file($target);
                    if ($exists) {
                        $copy = $folder . '/' . str_replace('/', '__', $relative);
                        if (!copy($target, $copy)) {
                            upgradeFail('Cannot backup ' . $relative);
                        }
                        chmod($copy, 0600);
                    }
                    $manifest['files'][] = [
                        'path' => $relative, 'existed' => $exists,
                        'before_sha256' => $exists ? hash_file('sha256', $target) : '',
                        'after_sha256' => hash('sha256', $body),
                    ];
                }
                if (file_put_contents(
                    $folder . '/manifest.json',
                    json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
                ) === false) {
                    upgradeFail('Cannot write upgrade manifest.');
                }
                chmod($folder . '/manifest.json', 0600);
                try {
                    foreach ($updates as $relative => $body) {
                        $target = $root . '/' . $relative;
                        $now = is_file($target) ? hash_file('sha256', $target) : '';
                        $entry = current(array_filter($manifest['files'],
                            static fn (array $i): bool => $i['path'] === $relative));
                        if (!is_array($entry) || !hash_equals($entry['before_sha256'], $now)) {
                            upgradeFail('Concurrent file change: ' . $relative);
                        }
                        writeAtomically($target, $body);
                    }
                } catch (Throwable $e) {
                    rollbackUpgrade($root, $id);
                    throw $e;
                }
                echo "SUCCESS | snapshot: " . $id . PHP_EOL;
                echo "Rollback: php upgrade-workspace-v2.php " . $root . " --rollback " . $id . PHP_EOL;
            }
        } else {
            upgradeFail('Unknown option: ' . $action);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
