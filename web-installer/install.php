<?php

declare(strict_types=1);

/**
 * Flight Starter Kit V3 – self-contained local distribution installer.
 *
 * Requirements: document root = /path/to/project/public, PHP >=8.2, HTTPS,
 * cURL, ZipArchive, PDO SQLite. Secrets are never embedded in this file.
 *
 * The first visit creates a private one-time installer access key in:
 *   ../.flight-install-access
 * Read it with your hosting File Manager, then paste it in the unlock form.
 *
 * The runtime is bundled in the private parent directory. No GitHub credentials.
 */
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
        . '<header><strong>✳ flight<span>starter.</span></strong></header><main><span class="eyebrow">FlightPHP · Assistant d’installation V3</span>'
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
    try {
        foreach (['zip', 'pdo_sqlite', 'mbstring', 'openssl', 'fileinfo'] as $ext) {
            if (!extension_loaded($ext)) problem('Extension PHP requise : ' . $ext);
        }
        $input = $_POST;
        $unpacked = $root . '/.flight-package';
        if (!is_file($unpacked . '/installer/WebInstallEngine.php')
            || !is_file($unpacked . '/runtime/vendor/autoload.php')
            || !is_file($unpacked . '/distribution.json')) {
            problem('Distribution locale incomplète. Décompressez le ZIP V3 complet.');
        }
        @set_time_limit(240);
        require_once $unpacked . '/installer/WebInstallEngine.php';
        $details = WebInstallEngine::install($unpacked, $root, $input);
        if (file_put_contents($root . '/.flight-installed.lock', gmdate('c'), LOCK_EX) === false) {
            problem('Site installé mais verrouillage du bootstrap impossible : supprimez install.php manuellement.');
        }
        chmod($root . '/.flight-installed.lock', 0600);
        @unlink($accessFile);
        // Remove the private distribution only after activation and lock succeed.
        // A failed installation keeps its verified package for a retry.
        WebInstallEngine::removeDirectory($unpacked);
        $_SESSION = [];
        session_destroy();

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
    . '<label><input type="hidden" name="stripe" value="0"><input type="checkbox" name="stripe" value="1"> Préparer Stripe</label>'
    . '</div>'
    . '<details class="surface-card" style="margin-top:18px;padding:18px;border:1px solid #dbe7d8;border-radius:12px">'
    . '<summary style="cursor:pointer;font-weight:800">Personnaliser le thème et les intégrations</summary>'
    . '<fieldset style="margin-top:15px"><label>Thème initial<select name="design_preset">'
    . '<option value="saas">SaaS · Moderne</option><option value="outil_metier">Gestion · Sobre</option><option value="blog">Éditorial · Clair</option>'
    . '</select></label></fieldset>'
    . '<fieldset style="display:flex;gap:18px;flex-wrap:wrap;margin-top:15px">'
    . '<label>Couleur principale <input type="color" name="primary" value="#2563eb"></label>'
    . '<label>Couleur secondaire <input type="color" name="accent" value="#14b8a6"></label></fieldset>'
    . '<div class="checks" style="margin-top:16px">'
    . '<label><input type="hidden" name="google" value="0"><input type="checkbox" name="google" value="1"> Préparer Google Login</label>'
    . '<label><input type="hidden" name="github" value="0"><input type="checkbox" name="github" value="1"> Préparer GitHub Login</label>'
    . '<label><input type="hidden" name="smtp" value="0"><input type="checkbox" name="smtp" value="1"> Préparer SMTP</label>'
    . '</div><p>Ces intégrations restent désactivées jusqu’à la saisie des identifiants dans Administration → Modules & réglages.</p>'
    . '</details>'
    . '<div class="warn">Les versions « blog » et « site vitrine » fournissent pour l’instant la structure et le thème, pas encore un CMS éditorial complet. '
    . 'Les paiements Stripe nécessiteront une configuration ultérieure des clés.</div></section>'
    . '<section style="margin-top:20px"><span class="eyebrow">Distribution locale V3</span>'
    . '<p>Le moteur, les dépendances PHP et les assets sont déjà dans le ZIP. Aucun compte GitHub, téléchargement externe, Composer ou Node.js requis.</p>'
    . '<button type="submit">Installer mon application ↗</button><p><small>PHP 8.2+, SQLite, ZIP, domaine HTTPS et racine /public requis.</small></p></section></form>';

// Convenience only: the server validates all dependencies independently.
$enhancement = <<<'JS'
(() => {
  const type = document.querySelector('select[name="type"]');
  const theme = document.querySelector('select[name="design_preset"]');
  const primary = document.querySelector('input[name="primary"]');
  const accent = document.querySelector('input[name="accent"]');
  let manuallyColored = false;
  [primary,accent].forEach(el=>el?.addEventListener('input',()=>{ manuallyColored=true; }));
  function syncThemeColors(){
    if(manuallyColored || !theme || !primary || !accent) return;
    const palettes = {
      saas:['#2563eb','#14b8a6'],
      outil_metier:['#0f766e','#f59e0b'],
      blog:['#9a3412','#0f766e']
    };
    [primary.value,accent.value] = palettes[theme.value] || palettes.saas;
  }
  theme?.addEventListener('change',syncThemeColors);
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
    if (theme) theme.value = type.value === 'outil_metier' ? 'outil_metier' : business ? 'saas' : 'blog';
    syncThemeColors();
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
