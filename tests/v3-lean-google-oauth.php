<?php
declare(strict_types=1);

require dirname(__DIR__) . '/installer/FlightStarterInstaller.php';

$config = require dirname(__DIR__) . '/installer/config.php';
$config['social_auth']['enabled'] = true;
$config['social_auth']['google']['enabled'] = true;
$config['social_auth']['github']['enabled'] = true;
$root = sys_get_temp_dir() . '/flight-google-lean-' . bin2hex(random_bytes(8));
$config['target_dir'] = $root;
$config['project_name'] = 'Lean Google OAuth';
$config['base_url'] = 'https://example.test';
try {
    $features = InstallerFeatures::fromConfig($config, InstallerOptions::fromArgv(['installer.php', '--no-composer']));
    $files = (new ProjectBlueprint($config, $root, $features, new TemplateCatalog($features)))->files();
    $composer = json_decode((string) ($files[$root . '/composer.json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $requirements = $composer['require'] ?? [];
    if (($requirements['league/oauth2-google'] ?? '') !== '^5.0'
        || isset($requirements['google/apiclient'], $requirements['google/apiclient-services'])) {
        throw new RuntimeException('Google OAuth includes unexpected heavyweight Composer dependencies.');
    }
    $service = (string) ($files[$root . '/app/Services/GoogleOAuth.php'] ?? '');
    foreach (['League\\OAuth2\\Client\\Provider\\Google', 'getAccessToken(', 'getResourceOwner(', "['email_verified']"] as $needle) {
        if (!str_contains($service, $needle)) {
            throw new RuntimeException('Google OAuth template missing: ' . $needle);
        }
    }
    if (str_contains($service, 'Google\\Service\\Oauth2')) {
        throw new RuntimeException('Heavy Google API client still used in OAuth.');
    }
    echo "[OK] Google login uses lightweight League OAuth2 provider.\n";
} finally {
    // ProjectBlueprint::files is pure: no filesystem cleanup necessary.
}
