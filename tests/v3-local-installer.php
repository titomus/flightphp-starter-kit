<?php
declare(strict_types=1);

// V3 regression: the installer must be standalone and use the private local bundle.
$source = file_get_contents(dirname(__DIR__) . '/web-installer/install.php');
if (!is_string($source)) {
    throw new RuntimeException('Installer source unavailable');
}
$required = [
    '$unpacked = $root . \'/.flight-package\';',
    "WebInstallEngine::install(\$unpacked, \$root, \$input)",
    "is_file(\$unpacked . '/distribution.json')",
    "is_file(\$root . '/.flight-installed.lock')",
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        throw new RuntimeException('V3 missing required behavior: ' . $snippet);
    }
}
foreach (['github_token', 'release_tag', 'archive_sha256', 'obtainPackage('] as $old) {
    if (str_contains($source, $old)) {
        throw new RuntimeException('V2 runtime download flow still present: ' . $old);
    }
}
define('FLIGHT_INSTALLER_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/web-installer/install.php';
if (!function_exists('expandSafeZip')) {
    throw new RuntimeException('Safe extraction helper missing');
}
echo "[OK] V3 installer uses local private runtime, no GitHub credentials.\n";
