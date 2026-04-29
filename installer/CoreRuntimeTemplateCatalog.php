<?php

declare(strict_types=1);

final class CoreRuntimeTemplateCatalog
{
    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    public function files(array $vars): array
    {
        return [
            'app/bootstrap.php' => $this->renderer->render($this->templateBootstrap(), $vars),
            'app/Support/helpers.php' => $this->renderer->render($this->templateHelpers(), $vars),
            'app/Support/Env.php' => $this->renderer->render($this->templateEnvLoader(), $vars),
            'app/Services/Config.php' => $this->renderer->render($this->templateConfigService(), $vars),
            'app/Services/Database.php' => $this->renderer->render($this->templateDatabaseService(), $vars),
            'app/Services/Auth.php' => $this->renderer->render($this->templateAuthService(), $vars),
            'app/Services/GithubOAuth.php' => $this->renderer->render($this->templateGithubOAuthService(), $vars),
            'app/Services/GoogleOAuth.php' => $this->renderer->render($this->templateGoogleOAuthService(), $vars),
            'app/Services/Mailer.php' => $this->renderer->render($this->templateMailerService(), $vars),
            'app/Services/MiddlewarePipeline.php' => $this->renderer->render($this->templateMiddlewarePipeline(), $vars),
            'app/Services/SeoManager.php' => $this->renderer->render($this->templateSeoManager(), $vars),
            'app/Services/StripeBilling.php' => $this->renderer->render($this->templateStripeBillingService(), $vars),
            'app/Services/View.php' => $this->renderer->render($this->templateViewService(), $vars),
        ];
    }

    private function templateBootstrap(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use {{namespace}}\Services\Config;
use {{namespace}}\Services\MiddlewarePipeline;
use {{namespace}}\Support\Env;

$basePath = dirname(__DIR__);

if (is_file($basePath . '/vendor/autoload.php')) {
    require $basePath . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($basePath): void {
    $prefix = '{{namespace}}\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $basePath . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/Support/helpers.php';

Env::load($basePath . '/.env');
Config::bootstrap($basePath . '/app/config');

date_default_timezone_set((string) config('app.timezone', 'UTC'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!class_exists(Flight::class)) {
    throw new RuntimeException('Flight is not installed. Run composer install.');
}

if ((bool) config('app.debug', true)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

\Flight::map('config', static fn (?string $key = null, mixed $default = null) => config($key, $default));

if (defined('APP_CONTEXT') && APP_CONTEXT === 'web') {
    (new {{namespace}}\Middleware\SecurityHeadersMiddleware())->handle(
        MiddlewarePipeline::fromGlobals(),
        static fn (array $request): null => null
    );

    require $basePath . '/app/routes/web.php';
    require $basePath . '/app/routes/api.php';
    require $basePath . '/app/routes/admin_generated.php';
}
PHP;
    }

    private function templateHelpers(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use {{namespace}}\Services\Auth;
use {{namespace}}\Services\Config;
use {{namespace}}\Services\MiddlewarePipeline;
use {{namespace}}\Services\SeoManager;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2);

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('auth')) {
    function auth(string $guard = 'admin'): Auth
    {
        static $guards = [];

        if (!isset($guards[$guard]) || !$guards[$guard] instanceof Auth) {
            $guards[$guard] = new Auth($guard);
        }

        return $guards[$guard];
    }
}

if (!function_exists('seo')) {
    function seo(): SeoManager
    {
        static $manager = null;

        if (!$manager instanceof SeoManager) {
            $manager = new SeoManager();
        }

        return $manager;
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        $base = base_path('app');

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', env('APP_URL', 'http://localhost:8000')), '/');

        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path, int $status = 302): never
    {
        header('Location: ' . $path, true, $status);
        exit;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $base = app_path('storage');

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}

if (!function_exists('view_path')) {
    function view_path(string $path = ''): string
    {
        $base = app_path('Views');

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        $base = base_path('public');

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}

if (!function_exists('vite_tags')) {
    function vite_tags(array $entries = ['resources/js/app.js']): string
    {
        $devServer = trim((string) env('VITE_DEV_SERVER', (string) config('frontend.dev_server', '')));

        if ($devServer !== '') {
            $tags = ['<script type="module" src="' . htmlspecialchars(rtrim($devServer, '/'), ENT_QUOTES, 'UTF-8') . '/@vite/client"></script>'];
            foreach ($entries as $entry) {
                $href = htmlspecialchars(rtrim($devServer, '/') . '/' . ltrim($entry, '/'), ENT_QUOTES, 'UTF-8');
                $tags[] = str_ends_with($entry, '.css')
                    ? '<link rel="stylesheet" href="' . $href . '">'
                    : '<script type="module" src="' . $href . '"></script>';
            }

            return implode(PHP_EOL, $tags);
        }

        $buildDir = trim((string) env('VITE_BUILD_DIR', (string) config('frontend.build_dir', '{{frontend_build_dir}}')), '\\/');
        $manifestPath = public_path($buildDir . '/manifest.json');
        if (!is_file($manifestPath)) {
            return '';
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return '';
        }

        $tags = [];
        foreach ($entries as $entry) {
            $key = ltrim($entry, '/');
            if (!isset($manifest[$key])) {
                continue;
            }

            $asset = $manifest[$key];
            foreach ($asset['css'] ?? [] as $cssFile) {
                $tags[] = '<link rel="stylesheet" href="/' . $buildDir . '/' . ltrim((string) $cssFile, '/') . '">';
            }
            if (isset($asset['file'])) {
                $tags[] = '<script type="module" src="/' . $buildDir . '/' . ltrim((string) $asset['file'], '/') . '"></script>';
            }
        }

        return implode(PHP_EOL, array_unique($tags));
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }
}

if (!function_exists('flash_get')) {
    function flash_get(string $key, mixed $default = null): mixed
    {
        if (!isset($_SESSION['_flash']) || !array_key_exists($key, $_SESSION['_flash'])) {
            return $default;
        }

        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        if ($_SESSION['_flash'] === []) {
            unset($_SESSION['_flash']);
        }

        return $value;
    }
}

if (!function_exists('old_input')) {
    function old_input(?string $key = null, mixed $default = null): mixed
    {
        static $old = null;
        if ($old === null) {
            $old = flash_get('_old_input', []);
        }

        if ($key === null) {
            return $old;
        }

        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('with_old_input')) {
    function with_old_input(array $input): void
    {
        flash_set('_old_input', $input);
    }
}

if (!function_exists('middleware')) {
    function middleware(array $stack, callable|array $handler): \Closure
    {
        return static function (...$args) use ($stack, $handler): mixed {
            $pipeline = new MiddlewarePipeline();
            $request = MiddlewarePipeline::fromGlobals();

            return $pipeline->handle($request, $stack, static function (array $request) use ($handler, $args): mixed {
                return is_array($handler)
                    ? $handler(...$args)
                    : $handler(...$args);
            });
        };
    }
}
PHP;
    }

    private function templateEnvLoader(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Support;

final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"");

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
PHP;
    }

    private function templateConfigService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

final class Config
{
    private static array $items = [];

    public static function bootstrap(string $configPath): void
    {
        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
        }
    }

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$items;
        }

        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
PHP;
    }

    private function templateDatabaseService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = config('database');
        $driver = $config['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $database = base_path($config['database']);
            $dsn = 'sqlite:' . $database;
            self::$connection = new PDO($dsn);
        } else {
            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $driver,
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '3306',
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );

            self::$connection = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '');
        }

        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$connection;
    }
}
PHP;
    }

    private function templateAuthService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use PDOException;

final class Auth
{
    public function __construct(private readonly string $guard = 'admin')
    {
    }

    private ?array $resolvedUser = null;

    public function attempt(string $email, string $password): bool
    {
        try {
            $statement = Database::connection()->prepare('SELECT * FROM ' . $this->table() . ' WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();
        } catch (PDOException) {
            return false;
        }

        if (!is_array($user) || !password_verify($password, (string) ($user['password'] ?? ''))) {
            return false;
        }

        $this->login($user);

        return true;
    }

    public function login(array $user): void
    {
        $_SESSION[$this->sessionKey()] = (int) ($user['id'] ?? 0);
        $this->resolvedUser = $user;
        session_regenerate_id(true);
    }

    public function logout(): void
    {
        unset($_SESSION[$this->sessionKey()]);
        $this->resolvedUser = null;
        session_regenerate_id(true);
    }

    public function user(): ?array
    {
        if ($this->resolvedUser !== null) {
            return $this->resolvedUser;
        }

        $id = $this->id();
        if ($id === null) {
            return null;
        }

        try {
            $statement = Database::connection()->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $id]);
            $user = $statement->fetch();
        } catch (PDOException) {
            return null;
        }

        $this->resolvedUser = is_array($user) ? $user : null;

        return $this->resolvedUser;
    }

    public function id(): ?int
    {
        $id = $_SESSION[$this->sessionKey()] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    private function sessionKey(): string
    {
        return (string) $this->config('session_key', $this->guard . '.user_id');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config('auth.guards.' . $this->guard . '.' . $key, $default);
    }

    private function table(): string
    {
        return (string) $this->config('table', $this->guard === 'frontend' ? 'users' : 'admin_users');
    }
}
PHP;
    }

    private function templateGoogleOAuthService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use Google\Client;
use Google\Service\Oauth2;
use RuntimeException;

final class GoogleOAuth
{
    public function isEnabled(): bool
    {
        return (bool) config('social_auth.enabled', true)
            && (bool) config('social_auth.providers.google.enabled', false);
    }

    public function issueState(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth.google_state'] = $state;

        return $state;
    }

    public function validateState(?string $state): bool
    {
        $expected = $_SESSION['oauth.google_state'] ?? null;
        unset($_SESSION['oauth.google_state']);

        return is_string($expected) && is_string($state) && hash_equals($expected, $state);
    }

    public function authorizationUrl(string $state): string
    {
        $client = $this->client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function fetchIdentity(string $code): array
    {
        $client = $this->client();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google OAuth token exchange failed.');
        }

        $client->setAccessToken($token);
        $oauth = new Oauth2($client);
        $profile = $oauth->userinfo->get();

        return [
            'google_id' => (string) ($profile->id ?? ''),
            'email' => (string) ($profile->email ?? ''),
            'name' => trim((string) ($profile->name ?? $profile->givenName ?? 'Google user')),
            'avatar' => (string) ($profile->picture ?? ''),
            'verified_email' => (bool) ($profile->verifiedEmail ?? false),
        ];
    }

    private function client(): Client
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('Google login is disabled.');
        }

        $clientId = trim((string) config('social_auth.providers.google.client_id', ''));
        $clientSecret = trim((string) config('social_auth.providers.google.client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Google OAuth credentials are not configured.');
        }

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri(app_url((string) config('social_auth.providers.google.callback_path', '/auth/google/callback')));
        $client->setScopes($this->scopes());
        $client->setAccessType('online');
        $client->setPrompt('select_account');

        $hostedDomain = trim((string) config('social_auth.providers.google.hosted_domain', ''));
        if ($hostedDomain !== '') {
            $client->setHostedDomain($hostedDomain);
        }

        return $client;
    }

    private function scopes(): array
    {
        $configured = config('social_auth.providers.google.scopes', ['openid', 'email', 'profile']);

        if (is_array($configured)) {
            return array_values(array_filter(array_map(static fn (mixed $scope): string => trim((string) $scope), $configured)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $configured))));
    }
}
PHP;
    }

    private function templateGithubOAuthService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use RuntimeException;

final class GithubOAuth
{
    public function isEnabled(): bool
    {
        return (bool) config('social_auth.enabled', true)
            && (bool) config('social_auth.providers.github.enabled', false);
    }

    public function authorizationUrl(): string
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl([
            'scope' => $this->scopes(),
        ]);

        $_SESSION['oauth.github_state'] = $provider->getState();

        return $url;
    }

    public function validateState(?string $state): bool
    {
        $expected = $_SESSION['oauth.github_state'] ?? null;
        unset($_SESSION['oauth.github_state']);

        return is_string($expected) && is_string($state) && hash_equals($expected, $state);
    }

    public function fetchIdentity(string $code): array
    {
        $provider = $this->provider();
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $code,
        ]);

        /** @var GithubResourceOwner $owner */
        $owner = $provider->getResourceOwner($token);
        $data = $owner->toArray();

        return [
            'github_id' => (string) ($data['id'] ?? ''),
            'email' => $this->resolveEmail($provider, $token, $data),
            'name' => trim((string) ($data['name'] ?? $data['login'] ?? 'GitHub user')),
            'avatar' => (string) ($data['avatar_url'] ?? ''),
            'username' => (string) ($data['login'] ?? ''),
        ];
    }

    private function resolveEmail(Github $provider, mixed $token, array $data): string
    {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        $request = $provider->getAuthenticatedRequest('GET', 'https://api.github.com/user/emails', $token, [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
            ],
        ]);
        $emails = $provider->getParsedResponse($request);

        if (!is_array($emails)) {
            throw new RuntimeException('GitHub email could not be resolved.');
        }

        foreach ($emails as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (!empty($candidate['primary']) && !empty($candidate['verified']) && is_string($candidate['email'])) {
                return trim($candidate['email']);
            }
        }

        throw new RuntimeException('GitHub account requires a verified primary email.');
    }

    private function provider(): Github
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('GitHub login is disabled.');
        }

        $clientId = trim((string) config('social_auth.providers.github.client_id', ''));
        $clientSecret = trim((string) config('social_auth.providers.github.client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GitHub OAuth credentials are not configured.');
        }

        return new Github([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => app_url((string) config('social_auth.providers.github.callback_path', '/auth/github/callback')),
        ]);
    }

    private function scopes(): array
    {
        $configured = config('social_auth.providers.github.scopes', ['user:email']);

        if (is_array($configured)) {
            return array_values(array_filter(array_map(static fn (mixed $scope): string => trim((string) $scope), $configured)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $configured))));
    }
}
PHP;
    }

    private function templateMailerService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class Mailer
{
    public function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        $transport = (string) config('mail.default', 'log');

        if ($transport === 'smtp') {
            return $this->sendSmtp($to, $subject, $html, $text);
        }

        if ($transport === 'native') {
            return $this->sendNative($to, $subject, $html, $text);
        }

        return $this->sendToLog($to, $subject, $html, $text, $transport);
    }

    private function sendNative(string $to, string $subject, string $html, string $text): bool
    {
        $fromAddress = (string) config('mail.from.address', 'noreply@example.com');
        $fromName = (string) config('mail.from.name', 'Flight Starter');

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . sprintf('%s <%s>', $fromName, $fromAddress),
        ];

        $message = $html !== '' ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        return mail($to, $subject, $message, implode("\r\n", $headers));
    }

    private function sendSmtp(string $to, string $subject, string $html, string $text): bool
    {
        if (!class_exists(Transport::class) || !class_exists(Email::class)) {
            throw new \RuntimeException('Symfony Mailer is required for SMTP transport. Run composer install.');
        }

        $smtp = (array) config('mail.mailers.smtp', []);
        $scheme = (string) ($smtp['scheme'] ?? 'smtp');
        $host = (string) ($smtp['host'] ?? 'localhost');
        $port = (int) ($smtp['port'] ?? 587);
        $username = rawurlencode((string) ($smtp['username'] ?? ''));
        $password = rawurlencode((string) ($smtp['password'] ?? ''));
        $query = [];

        if (($smtp['encryption'] ?? '') !== '') {
            $query['encryption'] = (string) $smtp['encryption'];
        }

        if (($smtp['timeout'] ?? '') !== '') {
            $query['timeout'] = (string) $smtp['timeout'];
        }

        $auth = $username !== '' ? $username . ($password !== '' ? ':' . $password : '') . '@' : '';
        $dsn = sprintf('%s://%s%s:%d', $scheme, $auth, $host, $port);
        if ($query !== []) {
            $dsn .= '?' . http_build_query($query);
        }

        $mailer = new SymfonyMailer(Transport::fromDsn($dsn));
        $email = (new Email())
            ->from(new Address((string) config('mail.from.address', 'noreply@example.com'), (string) config('mail.from.name', 'Flight Starter')))
            ->to($to)
            ->subject($subject);

        if ($html !== '') {
            $email->html($html);
        }

        if ($text !== '') {
            $email->text($text);
        }

        if ($html === '' && $text === '') {
            $email->text($subject);
        }

        $mailer->send($email);

        return true;
    }

    private function sendToLog(string $to, string $subject, string $html, string $text, string $transport): bool
    {
        $path = base_path((string) config('mail.log_path', 'app/storage/logs/mail.log'));
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = [
            'timestamp' => date(DATE_ATOM),
            'transport' => $transport,
            'from' => [
                'address' => config('mail.from.address', 'noreply@example.com'),
                'name' => config('mail.from.name', 'Flight Starter'),
            ],
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];

        return file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND) !== false;
    }
}
PHP;
    }

    private function templateStripeBillingService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use RuntimeException;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeBilling
{
    private ?StripeClient $client = null;

    public function isEnabled(): bool
    {
        return (bool) config('billing.providers.stripe.enabled', false);
    }

    public function checkoutUrl(array $user, ?string $planKey = null): string
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('Stripe billing is disabled.');
        }

        if (!isset($user['id'])) {
            throw new RuntimeException('Authenticated user required for checkout.');
        }

        $plan = $this->resolvePlan($planKey);
        $resolvedPriceId = trim((string) ($plan['price_id'] ?? config('billing.providers.stripe.default_price_id', '')));
        if ($resolvedPriceId === '') {
            throw new RuntimeException('Stripe default price is not configured.');
        }

        $resolvedMode = trim((string) ($plan['mode'] ?? config('billing.providers.stripe.default_mode', 'subscription')));
        $customerId = $this->ensureCustomer($user);

        $session = $this->client()->checkout->sessions->create([
            'mode' => $resolvedMode === 'payment' ? 'payment' : 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $user['id'],
            'line_items' => [[
                'price' => $resolvedPriceId,
                'quantity' => 1,
            ]],
            'success_url' => app_url((string) config('billing.providers.stripe.success_path', '/billing/success')) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => app_url((string) config('billing.providers.stripe.cancel_path', '/billing/cancel')),
            'allow_promotion_codes' => true,
            'metadata' => [
                'user_id' => (string) $user['id'],
                'email' => (string) ($user['email'] ?? ''),
                'plan_key' => (string) ($plan['key'] ?? config('billing.providers.stripe.default_plan', 'starter_monthly')),
                'price_id' => $resolvedPriceId,
            ],
        ]);

        Database::connection()->prepare('UPDATE users SET stripe_price_id = :price_id, billing_provider = :provider WHERE id = :id')->execute([
            'price_id' => $resolvedPriceId,
            'provider' => 'stripe',
            'id' => (int) $user['id'],
        ]);

        $url = (string) ($session->url ?? '');
        if ($url === '') {
            throw new RuntimeException('Stripe checkout session could not be created.');
        }

        return $url;
    }

    public function handleWebhookEvent(Event $event): void
    {
        $object = is_object($event->data->object ?? null) ? $event->data->object : null;
        $customerId = $this->stringValue($object, 'customer');
        $subscriptionId = $this->extractSubscriptionId($object);
        $priceId = $this->extractPriceId($object);
        $planKey = $this->extractMetadata($object, 'plan_key');
        $userId = $this->resolveUserId($object, $customerId);

        $this->recordBillingEvent($event, $userId, $customerId, $subscriptionId, $priceId, $planKey);

        if ($userId === null) {
            return;
        }

        $status = $this->mapEventStatus((string) $event->type, $object);
        if ($status !== null) {
            $this->syncSubscription($userId, $customerId, $subscriptionId, $planKey, $priceId, $status, $object);
            Database::connection()->prepare('UPDATE users SET billing_status = :status, billing_provider = :provider, billing_customer_id = COALESCE(NULLIF(:customer_id, \'' . '\'), billing_customer_id), stripe_price_id = COALESCE(NULLIF(:price_id, \'' . '\'), stripe_price_id) WHERE id = :id')->execute([
                'status' => $status,
                'provider' => 'stripe',
                'customer_id' => $customerId,
                'price_id' => $priceId,
                'id' => $userId,
            ]);
        }
    }

    public function constructEvent(string $payload, string $signature): Event
    {
        $secret = trim((string) config('billing.providers.stripe.webhook_secret', ''));
        if ($secret === '') {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    private function ensureCustomer(array $user): string
    {
        $customerId = trim((string) ($user['billing_customer_id'] ?? ''));
        if ($customerId !== '') {
            return $customerId;
        }

        $customer = $this->client()->customers->create([
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'metadata' => [
                'user_id' => (string) ($user['id'] ?? ''),
            ],
        ]);

        $customerId = (string) ($customer->id ?? '');
        if ($customerId === '') {
            throw new RuntimeException('Stripe customer could not be created.');
        }

        Database::connection()->prepare('UPDATE users SET billing_customer_id = :customer_id, billing_provider = :provider WHERE id = :id')->execute([
            'customer_id' => $customerId,
            'provider' => 'stripe',
            'id' => (int) ($user['id'] ?? 0),
        ]);

        return $customerId;
    }

    private function resolvePlan(?string $planKey): array
    {
        $plans = (array) config('billing.providers.stripe.plans', []);
        $defaultPlan = (string) config('billing.providers.stripe.default_plan', 'starter_monthly');
        $resolvedKey = trim((string) ($planKey ?? $defaultPlan));

        if ($resolvedKey !== '' && isset($plans[$resolvedKey]) && is_array($plans[$resolvedKey])) {
            return ['key' => $resolvedKey] + $plans[$resolvedKey];
        }

        if ($defaultPlan !== '' && isset($plans[$defaultPlan]) && is_array($plans[$defaultPlan])) {
            return ['key' => $defaultPlan] + $plans[$defaultPlan];
        }

        return [
            'key' => $defaultPlan,
            'price_id' => (string) config('billing.providers.stripe.default_price_id', ''),
            'mode' => (string) config('billing.providers.stripe.default_mode', 'subscription'),
        ];
    }

    private function resolveUserId(object|null $object, string $customerId): ?int
    {
        $metadataUserId = $this->extractMetadata($object, 'user_id');
        if ($metadataUserId !== '' && ctype_digit($metadataUserId)) {
            return (int) $metadataUserId;
        }

        if ($customerId === '') {
            return null;
        }

        $statement = Database::connection()->prepare('SELECT id FROM users WHERE billing_customer_id = :customer_id LIMIT 1');
        $statement->execute(['customer_id' => $customerId]);
        $result = $statement->fetchColumn();

        return is_numeric($result) ? (int) $result : null;
    }

    private function syncSubscription(int $userId, string $customerId, string $subscriptionId, string $planKey, string $priceId, string $status, object|null $object): void
    {
        $periodEnd = $this->extractPeriodEnd($object);
        $cancelAtPeriodEnd = $this->extractCancelAtPeriodEnd($object);
        $payload = json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $statement = Database::connection()->prepare('SELECT id FROM subscriptions WHERE provider = :provider AND provider_subscription_id = :subscription_id LIMIT 1');
        $statement->execute([
            'provider' => 'stripe',
            'subscription_id' => $subscriptionId,
        ]);
        $existingId = $statement->fetchColumn();

        if ($subscriptionId !== '' && is_numeric($existingId)) {
            Database::connection()->prepare('UPDATE subscriptions SET user_id = :user_id, provider_customer_id = :customer_id, plan_key = :plan_key, price_id = :price_id, status = :status, current_period_end = :current_period_end, cancel_at_period_end = :cancel_at_period_end, raw_payload = :raw_payload, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
                'user_id' => $userId,
                'customer_id' => $customerId,
                'plan_key' => $planKey,
                'price_id' => $priceId,
                'status' => $status,
                'current_period_end' => $periodEnd,
                'cancel_at_period_end' => $cancelAtPeriodEnd ? 1 : 0,
                'raw_payload' => $payload,
                'id' => (int) $existingId,
            ]);

            return;
        }

        Database::connection()->prepare('INSERT INTO subscriptions (user_id, provider, provider_customer_id, provider_subscription_id, plan_key, price_id, status, current_period_end, cancel_at_period_end, raw_payload) VALUES (:user_id, :provider, :customer_id, :subscription_id, :plan_key, :price_id, :status, :current_period_end, :cancel_at_period_end, :raw_payload)')->execute([
            'user_id' => $userId,
            'provider' => 'stripe',
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'plan_key' => $planKey,
            'price_id' => $priceId,
            'status' => $status,
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => $cancelAtPeriodEnd ? 1 : 0,
            'raw_payload' => $payload,
        ]);
    }

    private function recordBillingEvent(Event $event, ?int $userId, string $customerId, string $subscriptionId, string $priceId, string $planKey): void
    {
        $payload = json_encode($event->data->object ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $eventId = (string) ($event->id ?? '');

        if ($eventId === '') {
            return;
        }

        $statement = Database::connection()->prepare('SELECT id FROM billing_events WHERE provider = :provider AND provider_event_id = :event_id LIMIT 1');
        $statement->execute([
            'provider' => 'stripe',
            'event_id' => $eventId,
        ]);
        $existingId = $statement->fetchColumn();

        if (is_numeric($existingId)) {
            Database::connection()->prepare('UPDATE billing_events SET user_id = :user_id, provider_customer_id = :customer_id, provider_subscription_id = :subscription_id, price_id = :price_id, plan_key = :plan_key, type = :type, payload = :payload, processed_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
                'user_id' => $userId,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'price_id' => $priceId,
                'plan_key' => $planKey,
                'type' => (string) $event->type,
                'payload' => $payload,
                'id' => (int) $existingId,
            ]);

            return;
        }

        Database::connection()->prepare('INSERT INTO billing_events (user_id, provider, provider_event_id, provider_customer_id, provider_subscription_id, price_id, plan_key, type, payload) VALUES (:user_id, :provider, :event_id, :customer_id, :subscription_id, :price_id, :plan_key, :type, :payload)')->execute([
            'user_id' => $userId,
            'provider' => 'stripe',
            'event_id' => $eventId,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'price_id' => $priceId,
            'plan_key' => $planKey,
            'type' => (string) $event->type,
            'payload' => $payload,
        ]);
    }

    private function mapEventStatus(string $eventType, object|null $object): ?string
    {
        return match ($eventType) {
            'checkout.session.completed', 'invoice.payment_succeeded' => 'active',
            'invoice.payment_failed' => 'past_due',
            'customer.subscription.deleted' => 'canceled',
            'customer.subscription.updated' => $this->stringValue($object, 'status') !== '' ? $this->stringValue($object, 'status') : 'active',
            default => null,
        };
    }

    private function extractSubscriptionId(object|null $object): string
    {
        $direct = $this->stringValue($object, 'subscription');
        if ($direct !== '') {
            return $direct;
        }

        return $this->stringValue($object, 'id');
    }

    private function extractPriceId(object|null $object): string
    {
        $metadata = $this->extractMetadata($object, 'price_id');
        if ($metadata !== '') {
            return $metadata;
        }

        if (isset($object->lines->data[0]->price->id) && is_string($object->lines->data[0]->price->id)) {
            return $object->lines->data[0]->price->id;
        }

        if (isset($object->items->data[0]->price->id) && is_string($object->items->data[0]->price->id)) {
            return $object->items->data[0]->price->id;
        }

        return '';
    }

    private function extractMetadata(object|null $object, string $key): string
    {
        if (isset($object->metadata->{$key}) && is_scalar($object->metadata->{$key})) {
            return trim((string) $object->metadata->{$key});
        }

        return '';
    }

    private function extractPeriodEnd(object|null $object): ?string
    {
        $timestamp = null;

        if (isset($object->current_period_end) && is_numeric($object->current_period_end)) {
            $timestamp = (int) $object->current_period_end;
        } elseif (isset($object->lines->data[0]->period->end) && is_numeric($object->lines->data[0]->period->end)) {
            $timestamp = (int) $object->lines->data[0]->period->end;
        }

        return $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function extractCancelAtPeriodEnd(object|null $object): bool
    {
        return isset($object->cancel_at_period_end) ? (bool) $object->cancel_at_period_end : false;
    }

    private function stringValue(object|null $object, string $property): string
    {
        return isset($object->{$property}) && is_scalar($object->{$property}) ? trim((string) $object->{$property}) : '';
    }

    private function client(): StripeClient
    {
        if ($this->client instanceof StripeClient) {
            return $this->client;
        }

        $secretKey = trim((string) config('billing.providers.stripe.secret_key', ''));
        if ($secretKey === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $this->client = new StripeClient($secretKey);

        return $this->client;
    }
}
PHP;
    }

    private function templateSeoManager(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

final class SeoManager
{
    public function title(array $meta = []): string
    {
        $defaults = $this->defaults();
        $title = trim((string) ($meta['title'] ?? $defaults['title'] ?? config('app.name', 'Flight Starter')));
        $site = trim((string) ($meta['site_name'] ?? config('app.name', 'Flight Starter')));
        $separator = (string) config('seo.title_separator', ' | ');

        if ($title === '' || $title === $site) {
            return $site;
        }

        return $title . $separator . $site;
    }

    public function forPage(string $page, array $overrides = []): array
    {
        $pageConfig = (array) config('seo.pages.' . $page, []);

        return array_replace($this->defaults(), $pageConfig, $overrides);
    }

    public function render(array $meta = []): string
    {
        $meta = array_replace($this->defaults(), $meta);
        $tags = [
            '<meta name="description" content="' . $this->escape((string) ($meta['description'] ?? '')) . '">',
            '<meta name="robots" content="' . $this->escape((string) ($meta['robots'] ?? 'index,follow')) . '">',
            '<link rel="canonical" href="' . $this->escape($this->absoluteUrl((string) ($meta['canonical'] ?? ($_SERVER['REQUEST_URI'] ?? '/')))) . '">',
            '<meta property="og:title" content="' . $this->escape($this->title($meta)) . '">',
            '<meta property="og:description" content="' . $this->escape((string) ($meta['description'] ?? '')) . '">',
            '<meta property="og:type" content="' . $this->escape((string) ($meta['type'] ?? 'website')) . '">',
            '<meta property="og:url" content="' . $this->escape($this->absoluteUrl((string) ($meta['canonical'] ?? ($_SERVER['REQUEST_URI'] ?? '/')))) . '">',
            '<meta property="og:image" content="' . $this->escape($this->absoluteUrl((string) ($meta['image'] ?? ''))) . '">',
            '<meta property="og:site_name" content="' . $this->escape((string) config('app.name', 'Flight Starter')) . '">',
            '<meta property="og:locale" content="' . $this->escape((string) ($meta['locale'] ?? config('seo.locale', 'fr_FR'))) . '">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="' . $this->escape($this->title($meta)) . '">',
            '<meta name="twitter:description" content="' . $this->escape((string) ($meta['description'] ?? '')) . '">',
            '<meta name="twitter:image" content="' . $this->escape($this->absoluteUrl((string) ($meta['image'] ?? ''))) . '">',
        ];

        $twitterSite = (string) ($meta['twitter_site'] ?? config('seo.twitter_site', ''));
        if ($twitterSite !== '') {
            $tags[] = '<meta name="twitter:site" content="' . $this->escape($twitterSite) . '">';
        }

        if (!empty($meta['json_ld']) && is_array($meta['json_ld'])) {
            $tags[] = '<script type="application/ld+json">' . json_encode($meta['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }

        return implode(PHP_EOL, $tags);
    }

    public function sitemap(): string
    {
        $urls = [
            ['loc' => $this->absoluteUrl('/'), 'changefreq' => 'weekly', 'priority' => '1.0'],
        ];

        if ((bool) config('auth.guards.frontend.enabled', true)) {
            $urls[] = ['loc' => $this->absoluteUrl((string) config('auth.guards.frontend.login_path', '/login')), 'changefreq' => 'monthly', 'priority' => '0.4'];

            if ((bool) config('auth.guards.frontend.registration_enabled', true)) {
                $urls[] = ['loc' => $this->absoluteUrl((string) config('auth.guards.frontend.register_path', '/register')), 'changefreq' => 'monthly', 'priority' => '0.4'];
            }
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $this->escape($url['loc']) . '</loc>';
            $xml[] = '    <changefreq>' . $this->escape($url['changefreq']) . '</changefreq>';
            $xml[] = '    <priority>' . $this->escape($url['priority']) . '</priority>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        return implode(PHP_EOL, $xml);
    }

    public function robots(): string
    {
        return implode(PHP_EOL, [
            'User-agent: *',
            'Allow: /',
            'Sitemap: ' . $this->absoluteUrl((string) config('seo.sitemap_path', '/sitemap.xml')),
        ]);
    }

    private function defaults(): array
    {
        return [
            'title' => (string) config('app.name', 'Flight Starter'),
            'description' => (string) config('seo.default_description', ''),
            'image' => (string) config('seo.default_image', ''),
            'type' => (string) config('seo.default_type', 'website'),
            'robots' => (string) config('seo.robots', 'index,follow'),
            'locale' => (string) config('seo.locale', 'fr_FR'),
            'twitter_site' => (string) config('seo.twitter_site', ''),
        ];
    }

    private function absoluteUrl(string $path): string
    {
        if ($path === '') {
            return rtrim((string) config('app.url', ''), '/');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url', ''), '/') . '/' . ltrim($path, '/');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
PHP;
    }

    private function templateMiddlewarePipeline(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use {{namespace}}\Middleware\MiddlewareInterface;

final class MiddlewarePipeline
{
    public static function fromGlobals(): array
    {
        return [
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'uri' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            'input' => $_POST,
            'query' => $_GET,
            'server' => $_SERVER,
        ];
    }

    public function handle(array $request, array $stack, callable $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($stack),
            static function (callable $next, mixed $middleware): callable {
                return static function (array $request) use ($middleware, $next): mixed {
                    $instance = is_string($middleware) ? new $middleware() : $middleware;

                    if (!$instance instanceof MiddlewareInterface) {
                        throw new \RuntimeException('Invalid middleware provided to pipeline.');
                    }

                    return $instance->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}
PHP;
    }

    private function templateViewService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

final class View
{
    public function render(string $view, array $data = [], string $layout = 'layouts/main'): string
    {
        $viewPath = view_path(str_replace('.', '/', $view) . '.php');
        if (!is_file($viewPath)) {
            throw new \RuntimeException('View not found: ' . $viewPath);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $viewPath;
        $content = (string) ob_get_clean();

        $layoutPath = view_path(str_replace('.', '/', $layout) . '.php');
        if (!is_file($layoutPath)) {
            return $content;
        }

        ob_start();
        include $layoutPath;

        return (string) ob_get_clean();
    }
}
PHP;
    }
}