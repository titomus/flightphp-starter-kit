<?php

declare(strict_types=1);

/**
 * Flight Starter Kit 2.1 – drop this ONE file in the site's /public directory.
 *
 * Requirements: document root = /path/to/project/public, PHP >=8.2, HTTPS,
 * cURL, ZipArchive, PDO SQLite. Secrets are never embedded in this file.
 *
 * The first visit creates a private one-time installer access key in:
 *   ../.flight-install-access
 * Read it with your hosting File Manager, then paste it in the unlock form.
 *
 * The private GitHub repository is accessed with a short-lived, read-only
 * token supplied for the installation request only, never saved to disk.
 */
const FLIGHT_REPO = 'titomus/flightphp-starter-kit';
const FLIGHT_ASSET = 'flight-starter-runtime.zip';
const FLIGHT_MAX_ZIP_BYTES = 188743680;  // 180 MiB
const FLIGHT_MAX_UNPACKED = 420000000;
const FLIGHT_MAX_ENTRIES = 40000;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function problem(string $message): never
{
    throw new RuntimeException($message);
}

function githubRequest(string $url, string $token, ?string $destination = null): string
{
    if (!str_starts_with($url, 'https://api.github.com/repos/' . FLIGHT_REPO . '/releases/')) {
        problem('Destination GitHub non autorisée.');
    }
    $curl = curl_init($url);
    if ($curl === false) problem('Impossible de contacter GitHub.');
    $bytes = 0;
    $response = '';
    $file = $destination === null ? null : fopen($destination, 'wb');
    if ($destination !== null && !$file) problem('Impossible de créer l’archive temporaire.');
    try {
        curl_setopt_array($curl, [
            CURLOPT_USERAGENT => 'FlightStarterWebInstaller/2.1',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 220,
            CURLOPT_HTTPHEADER => [
                'Accept: ' . ($destination === null ? 'application/vnd.github+json' : 'application/octet-stream'),
                'Authorization: Bearer ' . $token,
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes, &$response, $file): int {
                $len = strlen($chunk);
                $bytes += $len;
                if ($bytes > ($file ? FLIGHT_MAX_ZIP_BYTES : 1024 * 1024)) {
                    return 0;
                }
                if ($file) {
                    $written = fwrite($file, $chunk);
                    return $written === false ? 0 : $written;
                }
                $response .= $chunk;
                return $len;
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($ok === false || $status !== 200) {
            problem('Téléchargement GitHub impossible (HTTP ' . $status . '). Vérifiez le tag et les droits du jeton.');
        }
    } finally {
        curl_close($curl);
        if ($file) fclose($file);
    }
    return $response;
}

function obtainPackage(string $tag, string $token, string $expectedSha, string $zipPath): void
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D', $tag)) {
        problem('Nom de version GitHub invalide.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', strtolower($expectedSha))) {
        problem('Empreinte SHA-256 de la distribution obligatoire.');
    }
    $json = githubRequest('https://api.github.com/repos/' . FLIGHT_REPO . '/releases/tags/' . rawurlencode($tag), $token);
    $release = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    $asset = null;
    foreach ($release['assets'] ?? [] as $candidate) {
        if (($candidate['name'] ?? '') === FLIGHT_ASSET) {
            $asset = $candidate;
            break;
        }
    }
    if (!is_array($asset) || !is_numeric($asset['id'] ?? null)) {
        problem('Cette version ne propose pas encore la distribution ' . FLIGHT_ASSET . '.');
    }
    $digest = (string) ($asset['digest'] ?? '');
    if (str_starts_with($digest, 'sha256:')
        && !hash_equals(strtolower(substr($digest, 7)), strtolower($expectedSha))) {
        problem('La somme de contrôle attendue diffère de celle publiée par GitHub.');
    }
    githubRequest('https://api.github.com/repos/' . FLIGHT_REPO . '/releases/assets/' . (int) $asset['id'],
        $token, $zipPath);
    $actual = hash_file('sha256', $zipPath);
    if ($actual === false || !hash_equals(strtolower($expectedSha), strtolower($actual))) {
        @unlink($zipPath);
        problem('Empreinte SHA-256 incorrecte. Installation interrompue.');
    }
}

function expandSafeZip(string $zipFile, string $folder): void
{
    $archive = new ZipArchive();
    if ($archive->open($zipFile) !== true) {
        problem('La distribution téléchargée n’est pas une archive ZIP valide.');
    }
    $size = 0;
    try {
        if ($archive->numFiles > FLIGHT_MAX_ENTRIES) problem('Distribution contenant trop de fichiers.');
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);
            if (!$stat) problem('Index ZIP illisible.');
            $name = (string) $stat['name'];
            // Composer bundles may legitimately contain parentheses, tildes
            // and paths longer than 260 bytes. Validate path *structure*,
            // not a brittle filename alphabet.
            if (strlen($name) > 1024
                || str_contains($name, "\\")
                || preg_match('~[\x00-\x1f\x7f]~', $name)
                || str_starts_with($name, '/')
                || preg_match('~(^|/)\.{1,2}(/|$)~', $name)
                || ($name !== 'distribution.json'
                    && !preg_match('~^(?:installer|runtime)/(?:[^/].*)?$~D', $name))
                || preg_match('~(?:^|/)[^/]*[:*?"<>|][^/]*(?:/|$)~', $name)) {
                problem('Chemin dangereux dans la distribution.');
            }
            $size += (int) $stat['size'];
            if ($size > FLIGHT_MAX_UNPACKED) problem('Distribution décompressée trop volumineuse.');
            $opsys = 0;
            $attributes = 0;
            if ($archive->getExternalAttributesIndex($i, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0170000) === 0120000) {
                problem('Archive contenant un lien symbolique.');
            }
            $dest = $folder . '/' . $name;
            if (str_ends_with($name, '/')) {
                if (!is_dir($dest) && !mkdir($dest, 0700, true) && !is_dir($dest)) {
                    problem('Impossible de créer le répertoire temporaire.');
                }
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                problem('Impossible de préparer les fichiers.');
            }
            if (file_exists($dest) || is_link($dest)) problem('Fichier ZIP répété.');
            $read = $archive->getStream($name);
            if (!$read) problem('Impossible de lire une entrée ZIP.');
            $write = fopen($dest, 'xb');
            if (!$write) { fclose($read); problem('Impossible d’extraire le fichier.'); }
            try {
                $copied = stream_copy_to_stream($read, $write, (int)$stat['size'] + 1);
                if ($copied === false || $copied !== (int)$stat['size']) problem('Extraction incomplète.');
            } finally {
                fclose($read);
                fclose($write);
            }
            chmod($dest, 0600);
        }
    } finally {
        $archive->close();
    }
}

function displayPage(string $title, string $body): never
{
    $styles = <<<'CSS'
:root{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#163b2b;background:#f7f8f3;font-size:15px}
*{box-sizing:border-box}body{margin:0;min-height:100vh}a{color:#24734a}
header{padding:26px 24px;border-bottom:1px solid #e1e8dc;background:#fff}
header strong{font-size:25px;letter-spacing:-1.5px}header span{color:#7d9e77}
main{max-width:1000px;margin:60px auto;padding:0 25px 55px}
.eyebrow{font-size:11px;letter-spacing:.14em;font-weight:800;color:#6a9876;text-transform:uppercase}
h1{font:500 clamp(37px,5vw,55px) Georgia,serif;letter-spacing:-.05em;margin:13px 0}
p{color:#697f70;line-height:1.75}section{background:#fff;padding:35px;border-radius:18px;box-shadow:0 12px 50px #1035220b;border:1px solid #e2e9dc}
fieldset{border:0;margin:20px 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:17px}
label{display:block;font-size:12px;font-weight:750;color:#355e42}
input,select{display:block;width:100%;height:45px;margin-top:8px;border:1px solid #d6e1d5;background:#fafcf8;border-radius:9px;padding:10px 12px;font:inherit;color:#234331}
input:focus,select:focus{outline:3px solid #c6e2bb}
input[type=checkbox]{width:18px;height:18px;margin:0}
.checks{display:flex;flex-wrap:wrap;gap:18px;margin:20px 0}.checks label{display:flex;align-items:center;gap:8px}
button,.button{padding:15px 21px;border:0;border-radius:10px;background:#153b2a;color:white;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;margin-top:10px;text-decoration:none}
button:hover,.button:hover{background:#287349;color:#fff}
small{color:#829a85;font-size:11px}.warn{padding:15px 18px;background:#fff3e2;color:#906537;border-radius:9px;margin:20px 0}
.error{padding:15px 18px;background:#ffefeb;color:#9c423b;border:1px solid #efc9c3;border-radius:9px;margin:18px 0}
.ok{padding:15px 18px;background:#e8f4e5;color:#2d7446;border-radius:9px;margin:18px 0}
pre{white-space:pre-wrap;word-break:break-word;padding:15px;background:#edf3ec;border-radius:9px}
footer{max-width:1000px;margin:0 auto;padding:30px 25px;color:#94a693;font-size:11px}
@media(max-width:650px){main{margin:32px auto}section{padding:23px}fieldset{grid-template-columns:1fr}h1{font-size:40px}}
CSS;
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' | Flight Starter Kit</title><style>' . $styles . '</style></head><body>'
        . '<header><strong>✳ flight<span>starter.</span></strong></header><main><span class="eyebrow">FlightPHP · Assistant d’installation v2.1</span>'
        . '<h1>' . h($title) . '</h1>' . $body . '</main><footer>Un site autonome, avec ou sans MCP.</footer></body></html>';
    exit;
}

// Expose pure archive/extraction helpers to the installation smoke tests.
// This cannot be toggled by an HTTP request: the constant is PHP-process-local.
if (defined('FLIGHT_INSTALLER_LIBRARY_ONLY') && FLIGHT_INSTALLER_LIBRARY_ONLY === true) {
    return;
}

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$flightNonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . $flightNonce . "'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Place install.php inside the site's /public document root, then open it over HTTPS.\n");
    exit(1);
}
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (!$secure && !$local) {
    http_response_code(403);
    displayPage('HTTPS obligatoire', '<p>Configurez HTTPS sur votre domaine avant de lancer l’installation.</p>');
}
if (basename(__DIR__) !== 'public') {
    http_response_code(400);
    displayPage('Mauvais emplacement', '<p>Déposez ce fichier dans le dossier <code>public/</code> du projet et pointez le domaine sur ce dossier.</p>');
}
$root = dirname(__DIR__);
if (is_file($root . '/.flight-installed.lock') || is_file($root . '/app/bootstrap.php')
    || is_file($root . '/public/index.php')) {
    http_response_code(403);
    displayPage('Déjà installé', '<p>Une application est déjà présente. L’installateur refuse tout écrasement.</p>');
}
session_name('flight_setup');
session_set_cookie_params([
    'httponly' => true, 'secure' => $secure, 'samesite' => 'Strict',
    'path' => '/',
]);
session_start();
if (empty($_SESSION['flight_csrf'])) {
    $_SESSION['flight_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['flight_csrf'];
$accessFile = $root . '/.flight-install-access';
if (is_link($accessFile)) problem('Secret d’installation invalide.');
if (!is_file($accessFile)) {
    // Exclusive creation prevents concurrent first visits overwriting
    // one another's one-time access key.
    $handle = @fopen($accessFile, 'x');
    if ($handle !== false) {
        $secret = bin2hex(random_bytes(24));
        try {
            if (fwrite($handle, $secret) !== strlen($secret)) {
                problem('Impossible de créer le secret d’installation.');
            }
        } finally {
            fclose($handle);
            unset($secret);
        }
        chmod($accessFile, 0600);
    } elseif (!is_file($accessFile)) {
        problem('Impossible de créer le secret d’installation.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['_csrf'] ?? null;
    if (!is_string($submittedCsrf) || !hash_equals($csrf, $submittedCsrf)) {
        http_response_code(419);
        displayPage('Session expirée', '<p>Rechargez la page pour obtenir un nouveau formulaire.</p>');
    }
    if (!empty($_POST['_unlock'])) {
        $supplied = $_POST['access_key'] ?? '';
        if (!is_string($supplied) || !hash_equals(trim((string)file_get_contents($accessFile)), trim($supplied))) {
            sleep(2);
            http_response_code(403);
            displayPage('Code incorrect', '<p>Le code ne correspond pas au fichier privé. <a href="install.php">Réessayer</a>.</p>');
        }
        $_SESSION['flight_unlocked'] = true;
        header('Location: install.php', true, 303);
        exit;
    }
}
if (empty($_SESSION['flight_unlocked'])) {
    displayPage('Déverrouiller l’installateur',
        '<p>Un code temporaire a été créé hors du dossier public. Ouvrez votre gestionnaire de fichiers o2switch et lisez <code>'
        . h($accessFile) . '</code>. Copiez son contenu pour autoriser l’installation.</p>'
        . '<form method="post"><input type="hidden" name="_csrf" value="' . h($csrf) . '"><input type="hidden" name="_unlock" value="1">'
        . '<section><label>Code d’installation<input type="password" name="access_key" required autocomplete="off" autofocus></label>'
        . '<button type="submit">Accéder à la configuration ↗</button></section></form>'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_install'] ?? '') === '1') {
    $scratch = null;
    try {
        foreach (['curl', 'zip', 'pdo_sqlite', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) problem('Extension PHP requise : ' . $ext);
        }
        $input = $_POST;
        $tag = (string) ($input['release_tag'] ?? 'v2.1.0');
        $token = (string) ($input['github_token'] ?? '');
        $sha = (string) ($input['archive_sha256'] ?? '');
        if (strlen($token) < 20 || strlen($token) > 500) problem('Jeton GitHub temporaire manquant.');
        // Unlock long-running hosting request when PHP supports it.
        @set_time_limit(240);
        $scratch = $root . '/.flight-download-' . bin2hex(random_bytes(9));
        if (!mkdir($scratch, 0700)) problem('Dossier de téléchargement non accessible.');
        $zip = $scratch . '/distribution.zip';
        obtainPackage($tag, $token, $sha, $zip);
        unset($token, $input['github_token']);
        $unpacked = $scratch . '/bundle';
        if (!mkdir($unpacked, 0700)) problem('Impossible d’extraire le paquet.');
        expandSafeZip($zip, $unpacked);
        require_once $unpacked . '/installer/WebInstallEngine.php';
        $details = WebInstallEngine::install($unpacked, $root, $input);
        if (file_put_contents($root . '/.flight-installed.lock', gmdate('c'), LOCK_EX) === false) {
            problem('Site installé mais verrouillage du bootstrap impossible : supprimez install.php manuellement.');
        }
        chmod($root . '/.flight-installed.lock', 0600);
        @unlink($accessFile);
        $_SESSION = [];
        session_destroy();
        WebInstallEngine::removeDirectory($scratch);
        $scratch = null;
        @unlink(__FILE__);
        $body = '<p class="ok">Votre application est installée. Les comptes et les migrations sont initialisés.</p>'
            . '<section><p><strong>' . h($details['features']['type']) . '</strong> · ' . h($details['features']['design']) . '</p>'
            . '<p><a class="button" href="' . h($details['url']) . '">Ouvrir le site ↗</a> '
            . '<a class="button" href="' . h($details['admin']) . '">Administration ↗</a></p>';
        if ($details['mcp'] !== null) {
            $body .= '<p>Point d’entrée ChatGPT (OAuth) : <code>' . h($details['mcp']) . '</code></p>'
                . '<p><small>Les droits de déploiement de code restent désactivés par défaut.</small></p>';
        }
        $body .= '</section>';
        displayPage('Installation terminée', $body);
    } catch (Throwable $e) {
        if ($scratch !== null && is_dir($scratch)) {
            // The engine can leave a private staging directory for diagnostics,
            // but the temporary release contains no credentials and is removed.
            if (class_exists('WebInstallEngine')) {
                WebInstallEngine::removeDirectory($scratch);
            }
        }
        http_response_code(400);
        displayPage('Installation interrompue',
            '<p class="error">' . h($e->getMessage()) . '</p>'
            . '<p>Aucun site existant n’a été écrasé. Vérifiez les informations, puis <a href="install.php">recommencez</a>.</p>');
    }
}

$url = $secure ? 'https://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) : 'https://example.com';
$form = '<p>Choisissez une base de départ. Les fonctionnalités métier pourront ensuite être développées avec ChatGPT, sans modifier les fichiers manuellement.</p>'
    . '<form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="' . h($csrf) . '">'
    . '<input type="hidden" name="_install" value="1"><section>'
    . '<fieldset><label>Type de projet<select name="type">'
    . '<option value="saas">SaaS (espace membres)</option><option value="outil_metier">Outil métier / CRM</option>'
    . '<option value="blog">Blog / média (structure + thème)</option><option value="site_vitrine">Site vitrine</option>'
    . '</select></label><label>Nom du site<input name="project_name" required maxlength="80" value="Mon application"></label></fieldset>'
    . '<fieldset><label>Adresse HTTPS du site<input type="url" name="base_url" required value="' . h($url) . '"></label>'
    . '<label>Email propriétaire<input type="email" name="admin_email" required autocomplete="off" placeholder="moi@domaine.fr"></label></fieldset>'
    . '<fieldset><label>Mot de passe propriétaire (16+ caractères)<input type="password" name="admin_password" required minlength="16" maxlength="256" autocomplete="new-password"></label>'
    . '<label>Confirmation<input type="password" name="password_confirm" required minlength="16" autocomplete="new-password"></label></fieldset>'
    . '<div class="checks">'
    . '<label><input type="hidden" name="members" value="0"><input type="checkbox" name="members" value="1" checked> Comptes membres</label>'
    . '<label><input type="hidden" name="mcp" value="0"><input type="checkbox" name="mcp" value="1" checked> Connecteur ChatGPT MCP</label>'
    . '<label><input type="hidden" name="stripe" value="0"><input type="checkbox" name="stripe" value="1"> Module Stripe</label>'
    . '</div><div class="warn">Les versions « blog » et « site vitrine » fournissent pour l’instant la structure et le thème, pas encore un CMS éditorial complet. '
    . 'Les paiements Stripe nécessiteront une configuration ultérieure des clés.</div></section>'
    . '<section style="margin-top:20px"><span class="eyebrow">Distribution GitHub vérifiée</span>'
    . '<p>Le dépôt étant privé, entrez un jeton GitHub en lecture seule, la version publiée et son empreinte SHA-256. Le jeton ne sera jamais enregistré.</p>'
    . '<fieldset><label>Version GitHub<input name="release_tag" value="v2.1.0" required pattern="[A-Za-z0-9_.-]+"></label>'
    . '<label>SHA-256 de l’archive<input name="archive_sha256" required pattern="[A-Fa-f0-9]{64}" placeholder="64 caractères hexadécimaux"></label></fieldset>'
    . '<label>Jeton GitHub temporaire<input type="password" name="github_token" required autocomplete="new-password"></label>'
    . '<button type="submit">Installer mon application ↗</button><p><small>PHP 8.2+, SQLite, cURL, ZIP, domaine HTTPS et racine /public requis. '
    . 'Aucun Node.js ni Composer sur cet hébergement.</small></p></section></form>';

// Convenience only: the server validates all dependencies independently.
$enhancement = <<<'JS'
(() => {
  const type = document.querySelector('select[name="type"]');
  const members = document.querySelector('input[type="checkbox"][name="members"]');
  const mcp = document.querySelector('input[type="checkbox"][name="mcp"]');
  const stripe = document.querySelector('input[type="checkbox"][name="stripe"]');
  if (!type || !members || !mcp || !stripe) return;
  function syncBilling() {
    if (!members.checked) stripe.checked = false;
    stripe.disabled = !members.checked;
    stripe.closest('label').style.opacity = members.checked ? '1' : '.52';
  }
  type.addEventListener('change', () => {
    const business = type.value === 'saas' || type.value === 'outil_metier';
    members.checked = business;
    mcp.checked = business;
    stripe.checked = false;
    syncBilling();
  });
  members.addEventListener('change', syncBilling);
  syncBilling();
})();
JS;
$form .= '<script nonce="' . h($flightNonce) . '">' . $enhancement . '</script>';

displayPage('Créons votre application.', $form);
