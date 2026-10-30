<?php

declare(strict_types=1);

final class CoreMvcTemplateCatalog
{
    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    public function files(array $vars): array
    {
        return [
            'app/Controllers/BaseController.php' => $this->renderer->render($this->templateBaseController(), $vars),
            'app/Controllers/AccountController.php' => $this->renderer->render($this->templateAccountController(), $vars),
            'app/Controllers/BillingController.php' => $this->renderer->render($this->templateBillingController(), $vars),
            'app/Controllers/SeoController.php' => $this->renderer->render($this->templateSeoController(), $vars),
            'app/Controllers/HomeController.php' => $this->renderer->render($this->templateHomeController(), $vars),
            'app/Controllers/Auth/GithubLoginController.php' => $this->renderer->render($this->templateGithubLoginController(), $vars),
            'app/Controllers/Auth/GoogleLoginController.php' => $this->renderer->render($this->templateGoogleLoginController(), $vars),
            'app/Controllers/Auth/LoginController.php' => $this->renderer->render($this->templateFrontendLoginController(), $vars),
            'app/Controllers/Auth/RegisterController.php' => $this->renderer->render($this->templateFrontendRegisterController(), $vars),
            'app/Models/BaseModel.php' => $this->renderer->render($this->templateBaseModel(), $vars),
            'app/Models/FrontendUser.php' => $this->renderer->render($this->templateFrontendUserModel(), $vars),
            'app/config/app.php' => $this->renderer->render($this->templateAppConfig(), $vars),
            'app/config/auth.php' => $this->renderer->render($this->templateAuthConfig(), $vars),
            'app/config/billing.php' => $this->renderer->render($this->templateBillingConfig(), $vars),
            'app/config/database.php' => $this->renderer->render($this->templateDatabaseConfig(), $vars),
            'app/config/backoffice.php' => $this->renderer->render($this->templateBackofficeConfig(), $vars),
            'app/config/mail.php' => $this->renderer->render($this->templateMailConfig(), $vars),
            'app/config/seo.php' => $this->renderer->render($this->templateSeoConfig(), $vars),
            'app/config/social_auth.php' => $this->renderer->render($this->templateSocialAuthConfig(), $vars),
            'app/Database/Migrations/20260428000002_create_users_table.php' => $this->renderer->render($this->templateFrontendUsersMigration(), $vars),
            'app/Database/Migrations/20260428000003_create_subscriptions_table.php' => $this->templateSubscriptionsMigration(),
            'app/Database/Migrations/20260428000004_create_billing_events_table.php' => $this->templateBillingEventsMigration(),
            'app/routes/web.php' => $this->renderer->render($this->templateWebRoutes(), $vars),
            'app/routes/api.php' => $this->renderer->render($this->templateApiRoutes(), $vars),
            'app/routes/admin_generated.php' => $this->templateAdminGeneratedRoutes(),
            'app/Views/layouts/main.php' => $this->templateMainLayout(),
            'app/Views/home.php' => $this->templateHomeView(),
            'app/Views/account/index.php' => $this->templateAccountView(),
            'app/Views/billing/pricing.php' => $this->templatePricingView(),
            'app/Views/billing/success.php' => $this->templateBillingSuccessView(),
            'app/Views/billing/cancel.php' => $this->templateBillingCancelView(),
            'app/Views/auth/login.php' => $this->templateFrontendLoginView(),
            'app/Views/auth/register.php' => $this->templateFrontendRegisterView(),
            'app/Middleware/MiddlewareInterface.php' => $this->renderer->render($this->templateMiddlewareInterface(), $vars),
            'app/Middleware/AuthenticateMiddleware.php' => $this->renderer->render($this->templateAuthenticateMiddleware(), $vars),
            'app/Middleware/GuestMiddleware.php' => $this->renderer->render($this->templateGuestMiddleware(), $vars),
            'app/Middleware/VerifyCsrfMiddleware.php' => $this->renderer->render($this->templateVerifyCsrfMiddleware(), $vars),
            'app/Middleware/ThrottleRequestsMiddleware.php' => $this->renderer->render($this->templateThrottleRequestsMiddleware(), $vars),
            'app/Middleware/SecurityHeadersMiddleware.php' => $this->renderer->render($this->templateSecurityHeadersMiddleware(), $vars),
            'app/storage/cache/.gitkeep' => '',
            'app/storage/logs/.gitkeep' => '',
            'app/storage/queue/.gitkeep' => '',
            'tests/.gitkeep' => '',
        ];
    }

    private function templateBaseController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers;

use {{namespace}}\Services\View;

abstract class BaseController
{
    protected function view(string $view, array $data = [], string $layout = 'layouts/main'): string
    {
        return (new View())->render($view, $data, $layout);
    }

    protected function guardCsrf(): void
    {
        $token = $_POST['_token'] ?? '';
        if (!hash_equals(csrf_token(), $token)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }
    }
}
PHP;
    }

    private function templateAccountController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers;

use {{namespace}}\Services\Database;

final class AccountController extends BaseController
{
    public function __invoke(): void
    {
        $user = auth('frontend')->user();
        $subscription = null;

        if (is_array($user) && isset($user['id'])) {
            $statement = Database::connection()->prepare('SELECT * FROM subscriptions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
            $statement->execute(['user_id' => (int) $user['id']]);
            $resolved = $statement->fetch();
            $subscription = is_array($resolved) ? $resolved : null;
        }

        echo $this->view('account.index', [
            'title' => 'My account',
            'user' => $user,
            'subscription' => $subscription,
            'seo' => seo()->forPage('account', [
                'title' => 'My account',
                'canonical' => config('auth.guards.frontend.home_path', '/account'),
                'robots' => 'noindex,nofollow',
            ]),
        ]);
    }
}
PHP;
    }

    private function templateBillingController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers;

use {{namespace}}\Services\Database;
use {{namespace}}\Services\StripeBilling;

final class BillingController extends BaseController
{
    public function pricing(): void
    {
        echo $this->view('billing.pricing', [
            'title' => 'Pricing',
            'error' => flash_get('billing.error'),
            'stripeEnabled' => (bool) config('billing.providers.stripe.enabled', false),
            'checkoutPath' => config('billing.providers.stripe.checkout_path', '/billing/checkout'),
            'defaultPlan' => config('billing.providers.stripe.default_plan', 'starter_monthly'),
            'plans' => config('billing.providers.stripe.plans', []),
            'currency' => strtoupper((string) config('billing.providers.stripe.currency', 'eur')),
            'seo' => seo()->forPage('pricing', [
                'title' => 'Pricing',
                'canonical' => config('billing.providers.stripe.pricing_path', '/pricing'),
            ]),
        ]);
    }

    public function checkout(): void
    {
        $user = auth('frontend')->user();
        if (!is_array($user)) {
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        $planKey = trim((string) ($_POST['plan'] ?? config('billing.providers.stripe.default_plan', 'starter_monthly')));

        try {
            $url = (new StripeBilling())->checkoutUrl($user, $planKey !== '' ? $planKey : null);
            redirect($url, 303);
        } catch (\Throwable $exception) {
            flash_set('billing.error', $exception->getMessage());
            redirect((string) config('billing.providers.stripe.pricing_path', '/pricing'));
        }
    }

    public function success(): void
    {
        $user = auth('frontend')->user();
        if (is_array($user) && isset($user['id'])) {
            $this->updateBillingStatus((int) $user['id'], 'active');
        }

        echo $this->view('billing.success', [
            'title' => 'Payment confirmed',
            'sessionId' => (string) ($_GET['session_id'] ?? ''),
            'accountPath' => config('auth.guards.frontend.home_path', '/account'),
            'seo' => seo()->forPage('billing-success', [
                'title' => 'Payment confirmed',
                'canonical' => config('billing.providers.stripe.success_path', '/billing/success'),
                'robots' => 'noindex,nofollow',
            ]),
        ]);
    }

    public function cancel(): void
    {
        echo $this->view('billing.cancel', [
            'title' => 'Checkout canceled',
            'pricingPath' => config('billing.providers.stripe.pricing_path', '/pricing'),
            'seo' => seo()->forPage('billing-cancel', [
                'title' => 'Checkout canceled',
                'canonical' => config('billing.providers.stripe.cancel_path', '/billing/cancel'),
                'robots' => 'noindex,nofollow',
            ]),
        ]);
    }

    public function webhook(): void
    {
        $payload = (string) file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

        try {
            $event = (new StripeBilling())->constructEvent($payload, $signature);
        } catch (\Throwable) {
            http_response_code(400);
            echo 'Invalid webhook.';
            return;
        }

        try {
            (new StripeBilling())->handleWebhookEvent($event);
        } catch (\Throwable) {
            http_response_code(500);
            echo 'Webhook handling failed.';
            return;
        }

        \Flight::json(['received' => true]);
    }
}
PHP;
    }

    private function templateSeoController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers;

final class SeoController extends BaseController
{
    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        echo seo()->sitemap();
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        echo seo()->robots();
    }
}
PHP;
    }

    private function templateHomeController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers;

final class HomeController extends BaseController
{
    public function index(): void
    {
        echo $this->view('home', [
            'title' => config('app.name', '{{project_name}}'),
            'adminEnabled' => (bool) config('backoffice.enabled', false),
            'adminPath' => config('backoffice.path', '/admin'),
            'frontendAuthEnabled' => (bool) config('auth.guards.frontend.enabled', true),
            'frontendLoginPath' => config('auth.guards.frontend.login_path', '/login'),
            'frontendRegisterPath' => config('auth.guards.frontend.register_path', '/register'),
            'frontendAccountPath' => config('auth.guards.frontend.home_path', '/account'),
            'googleLoginEnabled' => (bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.google.enabled', false),
            'googleLoginPath' => config('social_auth.providers.google.login_path', '/auth/google'),
            'githubLoginEnabled' => (bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.github.enabled', false),
            'githubLoginPath' => config('social_auth.providers.github.login_path', '/auth/github'),
            'stripeEnabled' => (bool) config('billing.providers.stripe.enabled', false),
            'pricingPath' => config('billing.providers.stripe.pricing_path', '/pricing'),
            'frontendUser' => auth('frontend')->user(),
            'seo' => seo()->forPage('home', [
                'title' => config('app.name', '{{project_name}}'),
                'canonical' => '/',
            ]),
        ]);
    }
}
PHP;
    }

    private function templateGithubLoginController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Auth;

use {{namespace}}\Controllers\BaseController;
use {{namespace}}\Services\Database;
use {{namespace}}\Services\GithubOAuth;

final class GithubLoginController extends BaseController
{
    public function redirect(): void
    {
        try {
            redirect((new GithubOAuth())->authorizationUrl());
        } catch (\Throwable $exception) {
            flash_set('auth.error', $exception->getMessage());
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }
    }

    public function callback(): void
    {
        $oauth = new GithubOAuth();

        if (!$oauth->validateState(isset($_GET['state']) ? (string) $_GET['state'] : null)) {
            flash_set('auth.error', 'GitHub OAuth state mismatch.');
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        try {
            $profile = $oauth->fetchIdentity((string) ($_GET['code'] ?? ''));
        } catch (\Throwable $exception) {
            flash_set('auth.error', $exception->getMessage());
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        $user = $this->persistUser($profile);
        auth('frontend')->login($user);

        redirect((string) config('auth.guards.frontend.home_path', '/account'));
    }

    private function persistUser(array $profile): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE github_id = :github_id OR email = :email LIMIT 1');
        $statement->execute([
            'github_id' => (string) $profile['github_id'],
            'email' => (string) $profile['email'],
        ]);
        $existing = $statement->fetch();

        if (is_array($existing)) {
            Database::connection()->prepare('UPDATE users SET github_id = :github_id, github_username = :username, name = :name, avatar = :avatar WHERE id = :id')->execute([
                'github_id' => (string) $profile['github_id'],
                'username' => (string) $profile['username'],
                'name' => (string) $profile['name'],
                'avatar' => (string) $profile['avatar'],
                'id' => (int) $existing['id'],
            ]);

            $refresh = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $refresh->execute(['id' => (int) $existing['id']]);
            $user = $refresh->fetch();

            return is_array($user) ? $user : $existing;
        }

        $password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        Database::connection()->prepare('INSERT INTO users (name, email, password, github_id, github_username, avatar, billing_status) VALUES (:name, :email, :password, :github_id, :username, :avatar, :billing_status)')->execute([
            'name' => (string) $profile['name'],
            'email' => (string) $profile['email'],
            'password' => $password,
            'github_id' => (string) $profile['github_id'],
            'username' => (string) $profile['username'],
            'avatar' => (string) $profile['avatar'],
            'billing_status' => 'free',
        ]);

        return [
            'id' => (int) Database::connection()->lastInsertId(),
            'name' => (string) $profile['name'],
            'email' => (string) $profile['email'],
            'github_id' => (string) $profile['github_id'],
            'github_username' => (string) $profile['username'],
            'avatar' => (string) $profile['avatar'],
            'billing_status' => 'free',
        ];
    }
}
PHP;
    }

    private function templateGoogleLoginController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Auth;

use {{namespace}}\Controllers\BaseController;
use {{namespace}}\Services\Database;
use {{namespace}}\Services\GoogleOAuth;

final class GoogleLoginController extends BaseController
{
    public function redirect(): void
    {
        try {
            $oauth = new GoogleOAuth();
            $state = $oauth->issueState();
            redirect($oauth->authorizationUrl($state));
        } catch (\Throwable $exception) {
            flash_set('auth.error', $exception->getMessage());
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }
    }

    public function callback(): void
    {
        $oauth = new GoogleOAuth();

        if (!$oauth->validateState(isset($_GET['state']) ? (string) $_GET['state'] : null)) {
            flash_set('auth.error', 'Google OAuth state mismatch.');
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        try {
            $profile = $oauth->fetchIdentity((string) ($_GET['code'] ?? ''));
        } catch (\Throwable $exception) {
            flash_set('auth.error', $exception->getMessage());
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        if (($profile['email'] ?? '') === '' || !($profile['verified_email'] ?? false)) {
            flash_set('auth.error', 'Google account email must be verified.');
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        $user = $this->persistUser($profile);
        auth('frontend')->login($user);

        redirect((string) config('auth.guards.frontend.home_path', '/account'));
    }

    private function persistUser(array $profile): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE google_id = :google_id OR email = :email LIMIT 1');
        $statement->execute([
            'google_id' => (string) $profile['google_id'],
            'email' => (string) $profile['email'],
        ]);
        $existing = $statement->fetch();

        if (is_array($existing)) {
            Database::connection()->prepare('UPDATE users SET google_id = :google_id, name = :name, avatar = :avatar WHERE id = :id')->execute([
                'google_id' => (string) $profile['google_id'],
                'name' => (string) $profile['name'],
                'avatar' => (string) $profile['avatar'],
                'id' => (int) $existing['id'],
            ]);

            $refresh = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $refresh->execute(['id' => (int) $existing['id']]);

            $user = $refresh->fetch();
            return is_array($user) ? $user : $existing;
        }

        $password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        Database::connection()->prepare('INSERT INTO users (name, email, password, google_id, avatar, billing_status) VALUES (:name, :email, :password, :google_id, :avatar, :billing_status)')->execute([
            'name' => (string) $profile['name'],
            'email' => (string) $profile['email'],
            'password' => $password,
            'google_id' => (string) $profile['google_id'],
            'avatar' => (string) $profile['avatar'],
            'billing_status' => 'free',
        ]);

        return [
            'id' => (int) Database::connection()->lastInsertId(),
            'name' => (string) $profile['name'],
            'email' => (string) $profile['email'],
            'google_id' => (string) $profile['google_id'],
            'avatar' => (string) $profile['avatar'],
            'billing_status' => 'free',
        ];
    }
}
PHP;
    }

    private function templateFrontendLoginController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Auth;

use {{namespace}}\Controllers\BaseController;

final class LoginController extends BaseController
{
    public function create(): void
    {
        echo $this->view('auth.login', [
            'title' => 'Connexion',
            'error' => flash_get('auth.error'),
            'email' => old_input('email', ''),
            'googleLoginEnabled' => (bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.google.enabled', false),
            'googleLoginPath' => config('social_auth.providers.google.login_path', '/auth/google'),
            'githubLoginEnabled' => (bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.github.enabled', false),
            'githubLoginPath' => config('social_auth.providers.github.login_path', '/auth/github'),
            'seo' => seo()->forPage('login', [
                'title' => 'Connexion',
                'canonical' => config('auth.guards.frontend.login_path', '/login'),
                'robots' => 'noindex,nofollow',
            ]),
        ]);
    }

    public function store(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        with_old_input(['email' => $email]);

        if ($email === '' || $password === '') {
            flash_set('auth.error', 'Email and password are required.');
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        if (!auth('frontend')->attempt($email, $password)) {
            flash_set('auth.error', 'Invalid credentials.');
            redirect((string) config('auth.guards.frontend.login_path', '/login'));
        }

        redirect((string) config('auth.guards.frontend.home_path', '/account'));
    }

    public function destroy(): void
    {
        auth('frontend')->logout();
        redirect((string) config('auth.guards.frontend.login_path', '/login'));
    }
}
PHP;
    }

    private function templateFrontendRegisterController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Auth;

use {{namespace}}\Controllers\BaseController;
use {{namespace}}\Services\Database;

final class RegisterController extends BaseController
{
    public function create(): void
    {
        echo $this->view('auth.register', [
            'title' => 'Créer un compte',
            'error' => flash_get('auth.error'),
            'name' => old_input('name', ''),
            'email' => old_input('email', ''),
            'googleLoginEnabled' => (bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.google.enabled', false),
            'googleLoginPath' => config('social_auth.providers.google.login_path', '/auth/google'),
            'githubLoginEnabled' => (bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.github.enabled', false),
            'githubLoginPath' => config('social_auth.providers.github.login_path', '/auth/github'),
            'seo' => seo()->forPage('register', [
                'title' => 'Créer un compte',
                'canonical' => config('auth.guards.frontend.register_path', '/register'),
                'robots' => 'noindex,nofollow',
            ]),
        ]);
    }

    public function store(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        with_old_input(['name' => $name, 'email' => $email]);

        if ($name === '' || $email === '' || $password === '') {
            flash_set('auth.error', 'All fields are required.');
            redirect((string) config('auth.guards.frontend.register_path', '/register'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('auth.error', 'Invalid email address.');
            redirect((string) config('auth.guards.frontend.register_path', '/register'));
        }

        if ($password !== $confirmation) {
            flash_set('auth.error', 'Passwords do not match.');
            redirect((string) config('auth.guards.frontend.register_path', '/register'));
        }

        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        if ((int) $statement->fetchColumn() > 0) {
            flash_set('auth.error', 'An account already exists for this email.');
            redirect((string) config('auth.guards.frontend.register_path', '/register'));
        }

        $insert = Database::connection()->prepare('INSERT INTO users (name, email, password) VALUES (:name, :email, :password)');
        $insert->execute([
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        auth('frontend')->attempt($email, $password);
        redirect((string) config('auth.guards.frontend.home_path', '/account'));
    }
}
PHP;
    }

    private function templateBaseModel(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Models;

use {{namespace}}\Services\Database;

abstract class BaseModel
{
    protected string $table = '';

    public function all(): array
    {
        return Database::connection()->query('SELECT * FROM ' . $this->table . ' ORDER BY id DESC')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM ' . $this->table . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function create(array $attributes): bool
    {
        $columns = implode(', ', array_keys($attributes));
        $params = implode(', ', array_map(static fn (string $column): string => ':' . $column, array_keys($attributes)));
        $statement = Database::connection()->prepare('INSERT INTO ' . $this->table . ' (' . $columns . ') VALUES (' . $params . ')');

        return $statement->execute($attributes);
    }
}
PHP;
    }

    private function templateFrontendUserModel(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Models;

final class FrontendUser extends BaseModel
{
    protected string $table = 'users';
}
PHP;
    }

    private function templateAppConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', '{{project_name}}'),
    'env' => env('APP_ENV', 'local'),
    'debug' => filter_var(env('APP_DEBUG', true), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', '{{base_url}}'),
    'timezone' => env('APP_TIMEZONE', '{{timezone}}'),
];
PHP;
    }

    private function templateDatabaseConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => env('DB_DRIVER', 'sqlite'),
    'database' => env('DB_DATABASE', '{{sqlite_database}}'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'username' => env('DB_USER', ''),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
PHP;
    }

    private function templateAuthConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'guards' => [
        'admin' => [
            'enabled' => filter_var(env('AUTH_ENABLED', '{{auth_enabled}}'), FILTER_VALIDATE_BOOL),
            'table' => 'admin_users',
            'session_key' => env('AUTH_SESSION_KEY', '{{auth_session_key}}'),
            'login_path' => env('AUTH_LOGIN_PATH', '{{auth_login_path}}'),
            'logout_path' => env('AUTH_LOGOUT_PATH', '{{auth_logout_path}}'),
            'home_path' => env('AUTH_HOME_PATH', '{{auth_home_path}}'),
            'throttle_attempts' => (int) env('AUTH_THROTTLE_ATTEMPTS', '{{auth_throttle_attempts}}'),
            'throttle_decay_seconds' => (int) env('AUTH_THROTTLE_DECAY_SECONDS', '{{auth_throttle_decay_seconds}}'),
        ],
        'frontend' => [
            'enabled' => filter_var(env('FRONTEND_AUTH_ENABLED', '{{frontend_auth_enabled}}'), FILTER_VALIDATE_BOOL),
            'registration_enabled' => filter_var(env('FRONTEND_AUTH_REGISTRATION_ENABLED', '{{frontend_auth_registration_enabled}}'), FILTER_VALIDATE_BOOL),
            'table' => 'users',
            'session_key' => env('FRONTEND_AUTH_SESSION_KEY', '{{frontend_auth_session_key}}'),
            'login_path' => env('FRONTEND_AUTH_LOGIN_PATH', '{{frontend_auth_login_path}}'),
            'register_path' => env('FRONTEND_AUTH_REGISTER_PATH', '{{frontend_auth_register_path}}'),
            'logout_path' => env('FRONTEND_AUTH_LOGOUT_PATH', '{{frontend_auth_logout_path}}'),
            'home_path' => env('FRONTEND_AUTH_HOME_PATH', '{{frontend_auth_home_path}}'),
            'throttle_attempts' => (int) env('FRONTEND_AUTH_THROTTLE_ATTEMPTS', '{{frontend_auth_throttle_attempts}}'),
            'throttle_decay_seconds' => (int) env('FRONTEND_AUTH_THROTTLE_DECAY_SECONDS', '{{frontend_auth_throttle_decay_seconds}}'),
        ],
    ],
];
PHP;
    }

    private function templateBackofficeConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(env('ADMIN_ENABLED', '{{backoffice_enabled}}'), FILTER_VALIDATE_BOOL),
    'path' => env('ADMIN_PATH', '{{admin_path}}'),
    'default_email' => env('ADMIN_EMAIL', '{{admin_email}}'),
    'default_password' => env('ADMIN_PASSWORD', '{{admin_password}}'),
];
PHP;
    }

    private function templateBillingConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'providers' => [
        'stripe' => [
            'enabled' => filter_var(env('STRIPE_ENABLED', '{{stripe_enabled}}'), FILTER_VALIDATE_BOOL),
            'pricing_path' => env('STRIPE_PRICING_PATH', '{{stripe_pricing_path}}'),
            'checkout_path' => env('STRIPE_CHECKOUT_PATH', '{{stripe_checkout_path}}'),
            'success_path' => env('STRIPE_SUCCESS_PATH', '{{stripe_success_path}}'),
            'cancel_path' => env('STRIPE_CANCEL_PATH', '{{stripe_cancel_path}}'),
            'webhook_path' => env('STRIPE_WEBHOOK_PATH', '{{stripe_webhook_path}}'),
            'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', '{{stripe_publishable_key}}'),
            'secret_key' => env('STRIPE_SECRET_KEY', '{{stripe_secret_key}}'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', '{{stripe_webhook_secret}}'),
            'currency' => env('STRIPE_CURRENCY', '{{stripe_currency}}'),
            'default_plan' => env('STRIPE_DEFAULT_PLAN', '{{stripe_default_plan}}'),
            'default_price_id' => env('STRIPE_DEFAULT_PRICE_ID', '{{stripe_default_price_id}}'),
            'default_mode' => env('STRIPE_DEFAULT_MODE', '{{stripe_default_mode}}'),
            'plans' => {{stripe_plans_php}},
        ],
    ],
];
PHP;
    }

    private function templateMailConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'default' => env('MAIL_MAILER', '{{mail_default}}'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', '{{mail_from_address}}'),
        'name' => env('MAIL_FROM_NAME', '{{mail_from_name}}'),
    ],
    'log_path' => env('MAIL_LOG_PATH', '{{mail_log_path}}'),
    'mailers' => [
        'smtp' => [
            'scheme' => env('MAIL_SCHEME', '{{mail_smtp_scheme}}'),
            'host' => env('MAIL_HOST', '{{mail_smtp_host}}'),
            'port' => (int) env('MAIL_PORT', '{{mail_smtp_port}}'),
            'encryption' => env('MAIL_ENCRYPTION', '{{mail_smtp_encryption}}'),
            'username' => env('MAIL_USERNAME', '{{mail_smtp_username}}'),
            'password' => env('MAIL_PASSWORD', '{{mail_smtp_password}}'),
            'timeout' => (int) env('MAIL_TIMEOUT', '{{mail_smtp_timeout}}'),
        ],
    ],
];
PHP;
    }

    private function templateSeoConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'title_separator' => env('SEO_TITLE_SEPARATOR', '{{seo_title_separator}}'),
    'default_description' => env('SEO_DEFAULT_DESCRIPTION', '{{seo_default_description}}'),
    'default_image' => env('SEO_DEFAULT_IMAGE', '{{seo_default_image}}'),
    'default_type' => env('SEO_DEFAULT_TYPE', '{{seo_default_type}}'),
    'locale' => env('SEO_LOCALE', '{{seo_locale}}'),
    'twitter_site' => env('SEO_TWITTER_SITE', '{{seo_twitter_site}}'),
    'robots' => env('SEO_ROBOTS', '{{seo_robots}}'),
    'sitemap_path' => env('SEO_SITEMAP_PATH', '{{seo_sitemap_path}}'),
    'robots_path' => env('SEO_ROBOTS_PATH', '{{seo_robots_path}}'),
    'pages' => [
        'home' => [
            'title' => env('APP_NAME', '{{project_name}}'),
            'description' => env('SEO_DEFAULT_DESCRIPTION', '{{seo_default_description}}'),
            'canonical' => '/',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => env('APP_NAME', '{{project_name}}'),
                'url' => env('APP_URL', '{{base_url}}'),
            ],
        ],
    ],
];
PHP;
    }

    private function templateSocialAuthConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(env('SOCIAL_AUTH_ENABLED', '{{social_auth_enabled}}'), FILTER_VALIDATE_BOOL),
    'providers' => [
        'google' => [
            'enabled' => filter_var(env('GOOGLE_AUTH_ENABLED', '{{google_login_enabled}}'), FILTER_VALIDATE_BOOL),
            'login_path' => env('GOOGLE_AUTH_LOGIN_PATH', '{{google_login_path}}'),
            'callback_path' => env('GOOGLE_AUTH_CALLBACK_PATH', '{{google_login_callback_path}}'),
            'client_id' => env('GOOGLE_CLIENT_ID', '{{google_login_client_id}}'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET', '{{google_login_client_secret}}'),
            'hosted_domain' => env('GOOGLE_HOSTED_DOMAIN', '{{google_login_hosted_domain}}'),
            'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_SCOPES', '{{google_login_scopes}}'))))),
        ],
        'github' => [
            'enabled' => filter_var(env('GITHUB_AUTH_ENABLED', '{{github_login_enabled}}'), FILTER_VALIDATE_BOOL),
            'login_path' => env('GITHUB_AUTH_LOGIN_PATH', '{{github_login_path}}'),
            'callback_path' => env('GITHUB_AUTH_CALLBACK_PATH', '{{github_login_callback_path}}'),
            'client_id' => env('GITHUB_CLIENT_ID', '{{github_login_client_id}}'),
            'client_secret' => env('GITHUB_CLIENT_SECRET', '{{github_login_client_secret}}'),
            'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('GITHUB_SCOPES', '{{github_login_scopes}}'))))),
        ],
    ],
];
PHP;
    }

    private function templateMiddlewareInterface(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Middleware;

interface MiddlewareInterface
{
    public function handle(array $request, \Closure $next): mixed;
}
PHP;
    }

    private function templateAuthenticateMiddleware(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Middleware;

final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $guard = 'admin')
    {
    }

    public function handle(array $request, \Closure $next): mixed
    {
        if (!(bool) config('auth.guards.' . $this->guard . '.enabled', true) || auth($this->guard)->check()) {
            return $next($request);
        }

        flash_set('auth.error', 'Authentication required.');
        redirect((string) config('auth.guards.' . $this->guard . '.login_path', '/login'));
    }
}
PHP;
    }

    private function templateGuestMiddleware(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Middleware;

final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $guard = 'admin')
    {
    }

    public function handle(array $request, \Closure $next): mixed
    {
        if (auth($this->guard)->guest()) {
            return $next($request);
        }

        redirect((string) config('auth.guards.' . $this->guard . '.home_path', '/'));
    }
}
PHP;
    }

    private function templateVerifyCsrfMiddleware(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Middleware;

final class VerifyCsrfMiddleware implements MiddlewareInterface
{
    public function handle(array $request, \Closure $next): mixed
    {
        if (!in_array($request['method'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $token = $request['input']['_token'] ?? ($request['server']['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        return $next($request);
    }
}
PHP;
    }

    private function templateThrottleRequestsMiddleware(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Middleware;

final class ThrottleRequestsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $guard = 'admin',
        private readonly ?int $maxAttempts = null,
        private readonly ?int $decaySeconds = null,
    ) {
    }

    public function handle(array $request, \Closure $next): mixed
    {
        $maxAttempts = $this->maxAttempts ?? (int) config('auth.guards.' . $this->guard . '.throttle_attempts', 5);
        $decaySeconds = $this->decaySeconds ?? (int) config('auth.guards.' . $this->guard . '.throttle_decay_seconds', 900);
        $path = trim((string) ($request['uri'] ?? '/'), '/');
        $key = sha1($this->guard . '|' . ($request['ip'] ?? '127.0.0.1') . '|' . ($request['method'] ?? 'GET') . '|' . $path);
        $file = storage_path('cache/throttle_' . $key . '.json');
        $now = time();

        $state = ['attempts' => 0, 'expires_at' => $now + $decaySeconds];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['attempts'], $decoded['expires_at'])) {
                $state = $decoded;
            }
        }

        if (($state['expires_at'] ?? 0) <= $now) {
            $state = ['attempts' => 0, 'expires_at' => $now + $decaySeconds];
        }

        $state['attempts']++;
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES));

        if ($state['attempts'] > $maxAttempts) {
            header('Retry-After: ' . max(1, (int) $state['expires_at'] - $now));
            http_response_code(429);
            exit('Too many attempts. Please retry later.');
        }

        return $next($request);
    }
}
PHP;
    }

    private function templateSecurityHeadersMiddleware(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Middleware;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(array $request, \Closure $next): mixed
    {
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        }

        return $next($request);
    }
}
PHP;
    }

    private function templateWebRoutes(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

\Flight::route('/', [new \{{namespace}}\Controllers\HomeController(), 'index']);

\Flight::route('GET ' . config('seo.sitemap_path', '/sitemap.xml'), [new \{{namespace}}\Controllers\SeoController(), 'sitemap']);
\Flight::route('GET ' . config('seo.robots_path', '/robots.txt'), [new \{{namespace}}\Controllers\SeoController(), 'robots']);
\Flight::route('GET ' . config('billing.providers.stripe.pricing_path', '/pricing'), [new \{{namespace}}\Controllers\BillingController(), 'pricing']);

if ((bool) config('billing.providers.stripe.enabled', false)) {
    \Flight::route('POST ' . config('billing.providers.stripe.checkout_path', '/billing/checkout'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('frontend'),
        \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
    ], [new \{{namespace}}\Controllers\BillingController(), 'checkout']));

    \Flight::route('GET ' . config('billing.providers.stripe.success_path', '/billing/success'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('frontend'),
    ], [new \{{namespace}}\Controllers\BillingController(), 'success']));

    \Flight::route('GET ' . config('billing.providers.stripe.cancel_path', '/billing/cancel'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('frontend'),
    ], [new \{{namespace}}\Controllers\BillingController(), 'cancel']));

    \Flight::route('POST ' . config('billing.providers.stripe.webhook_path', '/stripe/webhook'), [new \{{namespace}}\Controllers\BillingController(), 'webhook']);
}

if ((bool) config('auth.guards.frontend.enabled', true)) {
    \Flight::route('GET ' . config('auth.guards.frontend.login_path', '/login'), middleware([
        new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
    ], [new \{{namespace}}\Controllers\Auth\LoginController(), 'create']));

    \Flight::route('POST ' . config('auth.guards.frontend.login_path', '/login'), middleware([
        new \{{namespace}}\Middleware\ThrottleRequestsMiddleware('frontend'),
        new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
        \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
    ], [new \{{namespace}}\Controllers\Auth\LoginController(), 'store']));

    if ((bool) config('auth.guards.frontend.registration_enabled', true)) {
        \Flight::route('GET ' . config('auth.guards.frontend.register_path', '/register'), middleware([
            new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
        ], [new \{{namespace}}\Controllers\Auth\RegisterController(), 'create']));

        \Flight::route('POST ' . config('auth.guards.frontend.register_path', '/register'), middleware([
            new \{{namespace}}\Middleware\ThrottleRequestsMiddleware('frontend'),
            new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
            \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
        ], [new \{{namespace}}\Controllers\Auth\RegisterController(), 'store']));
    }

    \Flight::route('POST ' . config('auth.guards.frontend.logout_path', '/logout'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('frontend'),
        \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
    ], [new \{{namespace}}\Controllers\Auth\LoginController(), 'destroy']));

    if ((bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.google.enabled', false)) {
        \Flight::route('GET ' . config('social_auth.providers.google.login_path', '/auth/google'), middleware([
            new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
        ], [new \{{namespace}}\Controllers\Auth\GoogleLoginController(), 'redirect']));

        \Flight::route('GET ' . config('social_auth.providers.google.callback_path', '/auth/google/callback'), middleware([
            new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
        ], [new \{{namespace}}\Controllers\Auth\GoogleLoginController(), 'callback']));
    }

    if ((bool) config('social_auth.enabled', true) && (bool) config('social_auth.providers.github.enabled', false)) {
        \Flight::route('GET ' . config('social_auth.providers.github.login_path', '/auth/github'), middleware([
            new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
        ], [new \{{namespace}}\Controllers\Auth\GithubLoginController(), 'redirect']));

        \Flight::route('GET ' . config('social_auth.providers.github.callback_path', '/auth/github/callback'), middleware([
            new \{{namespace}}\Middleware\GuestMiddleware('frontend'),
        ], [new \{{namespace}}\Controllers\Auth\GithubLoginController(), 'callback']));
    }

    \Flight::route('GET ' . config('auth.guards.frontend.home_path', '/account'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('frontend'),
    ], [new \{{namespace}}\Controllers\AccountController(), '__invoke']));
}

{{backoffice_dashboard_routes}}
PHP;
    }

    private function templateApiRoutes(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

\Flight::route('/api/health', static function (): void {
    \Flight::json([
        'status' => 'ok',
        'app' => config('app.name'),
        'timestamp' => date(DATE_ATOM),
    ]);
});
PHP;
    }

    private function templateAdminGeneratedRoutes(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);
PHP;
    }

    private function templateMainLayout(): string
    {
        return <<<'PHP'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(seo()->title($seo ?? []), ENT_QUOTES, 'UTF-8') ?></title>
    <?= seo()->render($seo ?? []) ?>
    <?= vite_tags(['resources/js/app.js']) ?>
    <?php if (is_file(public_path('assets/business.css'))): ?><link rel="stylesheet" href="/assets/business.css"><?php endif; ?>
</head>
<?php $design = config('design', []); $shellClass = !empty($design['layout']['fullscreen']) ? 'site-shell--full' : 'site-shell--contained'; ?>
<body class="site-body">
<main class="site-shell <?= $shellClass ?>">
    <?= $content ?>
</main>
<?php if (is_file(public_path('assets/business.js'))): ?><script defer src="/assets/business.js"></script><?php endif; ?>
</body>
</html>
PHP;
    }

    private function templateHomeView(): string
    {
        return <<<'PHP'
<section class="panel-card overflow-hidden p-8 md:p-12">
    <p class="eyebrow">Flight PHP Starter</p>
    <h1 class="display-title max-w-3xl text-4xl md:text-6xl"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="lead-copy mt-6 max-w-3xl">
        Votre starter Flight est opérationnel avec MVC, configuration centralisée, migrations,
        queue worker et générateur de BackOffice. Le but est d’avoir une base légère, lisible et
        directement exploitable sans couches inutiles.
    </p>

    <div class="mt-10 grid gap-5 md:grid-cols-3">
        <article class="surface-card p-6">
            <h2 class="section-title">HTTP</h2>
            <p class="muted-copy mt-3">Front controller dans <code>public/index.php</code>, routes web et API séparées.</p>
            <p class="muted-copy mt-2">Endpoint santé: <code>/api/health</code></p>
        </article>
        <article class="surface-card p-6">
            <h2 class="section-title">Console</h2>
            <p class="muted-copy mt-3">Migrations, seeders, queue worker et générateurs via <code>app/Console/console.php</code>.</p>
        </article>
        <article class="surface-card p-6">
            <h2 class="section-title">Security</h2>
            <p class="muted-copy mt-3">Auth session, CSRF, throttling et security headers sont prêts dès l’installation.</p>
        </article>
    </div>

    <div class="mt-5 grid gap-5 md:grid-cols-2">
        <article class="surface-card p-6">
            <h2 class="section-title">BackOffice</h2>
            <p class="muted-copy mt-3"><?= $adminEnabled ? 'Zone admin activée et prête à être étendue.' : 'Zone admin désactivée pour cette installation.' ?></p>
            <?php if ($adminEnabled): ?>
                <a class="button-primary mt-4" href="<?= htmlspecialchars($adminPath, ENT_QUOTES, 'UTF-8') ?>">Ouvrir l’admin</a>
            <?php endif; ?>
        </article>
        <article class="surface-card p-6">
            <h2 class="section-title">Billing</h2>
            <p class="muted-copy mt-3"><?= $stripeEnabled ? 'Stripe Checkout et le webhook sont prêts à être branchés à tes price IDs.' : 'Stripe Checkout est déjà scaffoldé, il suffit d’ajouter les clés et les price IDs.' ?></p>
            <a class="button-primary mt-4" href="<?= htmlspecialchars($pricingPath, ENT_QUOTES, 'UTF-8') ?>">Voir le pricing</a>
        </article>
    </div>

    <?php if ($frontendAuthEnabled): ?>
        <div class="mt-5 flex flex-wrap gap-4">
            <?php if (is_array($frontendUser ?? null)): ?>
                <a class="button-primary" href="<?= htmlspecialchars($frontendAccountPath, ENT_QUOTES, 'UTF-8') ?>">Mon compte</a>
                <form method="post" action="<?= htmlspecialchars(config('auth.guards.frontend.logout_path', '/logout'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= csrf_field() ?>
                    <button class="button-secondary" type="submit">Déconnexion</button>
                </form>
            <?php else: ?>
                <a class="button-primary" href="<?= htmlspecialchars($frontendLoginPath, ENT_QUOTES, 'UTF-8') ?>">Connexion</a>
                <a class="button-secondary" href="<?= htmlspecialchars($frontendRegisterPath, ENT_QUOTES, 'UTF-8') ?>">Créer un compte</a>
                <?php if ($googleLoginEnabled): ?>
                    <a class="button-secondary" href="<?= htmlspecialchars($googleLoginPath, ENT_QUOTES, 'UTF-8') ?>">Continuer avec Google</a>
                <?php endif; ?>
                <?php if ($githubLoginEnabled): ?>
                    <a class="button-secondary" href="<?= htmlspecialchars($githubLoginPath, ENT_QUOTES, 'UTF-8') ?>">Continuer avec GitHub</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
PHP;
    }

    private function templateFrontendUsersMigration(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateUsersTable';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    google_id TEXT UNIQUE,
    github_id TEXT UNIQUE,
    github_username TEXT,
    avatar TEXT,
    billing_customer_id TEXT,
    billing_provider TEXT,
    billing_status TEXT DEFAULT 'free',
    stripe_price_id TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS users');
    }
};
PHP;
    }

    private function templateSubscriptionsMigration(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateSubscriptionsTable';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider TEXT NOT NULL,
    provider_customer_id TEXT,
    provider_subscription_id TEXT,
    plan_key TEXT,
    price_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    current_period_end DATETIME,
    cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
    raw_payload TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS subscriptions');
    }
};
PHP;
    }

    private function templateBillingEventsMigration(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateBillingEventsTable';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS billing_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    provider TEXT NOT NULL,
    provider_event_id TEXT NOT NULL,
    provider_customer_id TEXT,
    provider_subscription_id TEXT,
    price_id TEXT,
    plan_key TEXT,
    type TEXT NOT NULL,
    payload TEXT,
    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS billing_events');
    }
};
PHP;
    }

    private function templateFrontendLoginView(): string
    {
        return <<<'PHP'
<section class="mx-auto max-w-lg panel-card p-8">
    <header class="mb-8">
        <p class="eyebrow">Frontend auth</p>
        <h1 class="display-title text-3xl">Connexion</h1>
    </header>

    <?php if (!empty($error)): ?>
        <div class="notice notice--error mb-6"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($googleLoginEnabled)): ?>
        <a class="button-secondary mb-4 flex w-full justify-center" href="<?= htmlspecialchars((string) $googleLoginPath, ENT_QUOTES, 'UTF-8') ?>">Continuer avec Google</a>
    <?php endif; ?>
    <?php if (!empty($githubLoginEnabled)): ?>
        <a class="button-secondary mb-6 flex w-full justify-center" href="<?= htmlspecialchars((string) $githubLoginPath, ENT_QUOTES, 'UTF-8') ?>">Continuer avec GitHub</a>
    <?php endif; ?>
    <?php if (!empty($googleLoginEnabled) || !empty($githubLoginEnabled)): ?>
        <div class="divider-copy mb-6">ou</div>
    <?php endif; ?>

    <form class="space-y-5" method="post" action="<?= htmlspecialchars(config('auth.guards.frontend.login_path', '/login'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrf_field() ?>
        <label class="block space-y-2">
            <span class="field-label">Email</span>
            <input class="field-input" type="email" name="email" value="<?= htmlspecialchars((string) $email, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label class="block space-y-2">
            <span class="field-label">Password</span>
            <input class="field-input" type="password" name="password" required>
        </label>
        <button class="button-primary w-full justify-center" type="submit">Se connecter</button>
    </form>
</section>
PHP;
    }

    private function templateFrontendRegisterView(): string
    {
        return <<<'PHP'
<section class="mx-auto max-w-lg panel-card p-8">
    <header class="mb-8">
        <p class="eyebrow">Frontend auth</p>
        <h1 class="display-title text-3xl">Créer un compte</h1>
    </header>

    <?php if (!empty($error)): ?>
        <div class="notice notice--error mb-6"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($googleLoginEnabled)): ?>
        <a class="button-secondary mb-4 flex w-full justify-center" href="<?= htmlspecialchars((string) $googleLoginPath, ENT_QUOTES, 'UTF-8') ?>">Créer un compte avec Google</a>
    <?php endif; ?>
    <?php if (!empty($githubLoginEnabled)): ?>
        <a class="button-secondary mb-6 flex w-full justify-center" href="<?= htmlspecialchars((string) $githubLoginPath, ENT_QUOTES, 'UTF-8') ?>">Créer un compte avec GitHub</a>
    <?php endif; ?>
    <?php if (!empty($googleLoginEnabled) || !empty($githubLoginEnabled)): ?>
        <div class="divider-copy mb-6">ou</div>
    <?php endif; ?>

    <form class="space-y-5" method="post" action="<?= htmlspecialchars(config('auth.guards.frontend.register_path', '/register'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrf_field() ?>
        <label class="block space-y-2">
            <span class="field-label">Name</span>
            <input class="field-input" type="text" name="name" value="<?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label class="block space-y-2">
            <span class="field-label">Email</span>
            <input class="field-input" type="email" name="email" value="<?= htmlspecialchars((string) $email, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label class="block space-y-2">
            <span class="field-label">Password</span>
            <input class="field-input" type="password" name="password" required>
        </label>
        <label class="block space-y-2">
            <span class="field-label">Confirm password</span>
            <input class="field-input" type="password" name="password_confirmation" required>
        </label>
        <button class="button-primary w-full justify-center" type="submit">Créer le compte</button>
    </form>
</section>
PHP;
    }

    private function templateAccountView(): string
    {
        return <<<'PHP'
<section class="panel-card p-8">
    <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <p class="eyebrow">Frontend auth</p>
            <h1 class="display-title text-3xl">Mon compte</h1>
        </div>
        <form method="post" action="<?= htmlspecialchars(config('auth.guards.frontend.logout_path', '/logout'), ENT_QUOTES, 'UTF-8') ?>">
            <?= csrf_field() ?>
            <button class="button-secondary" type="submit">Déconnexion</button>
        </form>
    </header>

    <div class="grid gap-5 md:grid-cols-2">
        <article class="surface-card p-6">
            <p class="eyebrow">Profile</p>
            <h2 class="section-title mt-3"><?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="muted-copy mt-2"><?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="muted-copy mt-2">Provider: <?= htmlspecialchars((string) (!empty($user['github_id']) ? 'github' : (!empty($user['google_id']) ? 'google' : 'password')), ENT_QUOTES, 'UTF-8') ?></p>
        </article>
        <article class="surface-card p-6">
            <p class="eyebrow">Billing</p>
            <p class="muted-copy mt-3">Statut: <?= htmlspecialchars((string) ($user['billing_status'] ?? 'free'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="muted-copy mt-2">Provider: <?= htmlspecialchars((string) ($user['billing_provider'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (is_array($subscription ?? null)): ?>
                <p class="muted-copy mt-2">Plan: <?= htmlspecialchars((string) ($subscription['plan_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="muted-copy mt-2">Subscription status: <?= htmlspecialchars((string) ($subscription['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </article>
    </div>
</section>
PHP;
    }

    private function templatePricingView(): string
    {
        return <<<'PHP'
<section class="mx-auto max-w-4xl panel-card p-8 md:p-10">
    <header class="max-w-2xl">
        <p class="eyebrow">Stripe billing</p>
        <h1 class="display-title text-4xl">Lance un pricing prêt à vendre</h1>
        <p class="lead-copy mt-4">Le starter inclut déjà Stripe Checkout, un endpoint webhook et le stockage du statut d’abonnement côté utilisateur.</p>
    </header>

    <?php if (!empty($error)): ?>
        <div class="notice notice--error mt-6"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="mt-8 grid gap-5 md:grid-cols-3">
        <?php foreach ((array) $plans as $planKey => $plan): ?>
            <article class="surface-card <?= !empty($plan['highlight']) ? 'feature-card--highlight' : '' ?> p-6">
                <p class="eyebrow"><?= htmlspecialchars((string) ($plan['label'] ?? $planKey), ENT_QUOTES, 'UTF-8') ?></p>
                <h2 class="display-title mt-3 text-3xl"><?= number_format(((int) ($plan['amount'] ?? 0)) / 100, 0, ',', ' ') ?> <?= htmlspecialchars(strtoupper((string) ($plan['currency'] ?? $currency)), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="meta-copy mt-2"><?php if (!empty($plan['interval'])): ?>/ <?= htmlspecialchars((string) $plan['interval'], ENT_QUOTES, 'UTF-8') ?><?php else: ?>paiement unique<?php endif; ?></p>
                <p class="muted-copy mt-4 min-h-16"><?= htmlspecialchars((string) ($plan['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <form class="mt-6 space-y-4" method="post" action="<?= htmlspecialchars((string) $checkoutPath, ENT_QUOTES, 'UTF-8') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="plan" value="<?= htmlspecialchars((string) $planKey, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="button-primary w-full justify-center" type="submit" <?= empty($stripeEnabled) ? 'disabled' : '' ?>><?= !empty($stripeEnabled) ? (($planKey === $defaultPlan) ? 'Plan recommandé' : 'Choisir ce plan') : 'Configurer Stripe dans .env' ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>

    <article class="surface-card mt-6 p-6">
        <p class="eyebrow">Inclus</p>
        <ul class="muted-copy mt-4 space-y-3">
            <li>Catalogue de plans multi-price prêt à adapter à Stripe.</li>
            <li>Webhook pour synchroniser le statut utilisateur et la table `subscriptions`.</li>
            <li>Journal des événements dans `billing_events` pour auditer ce qui s’est passé.</li>
            <li>Point d’entrée simple pour brancher un pricing SaaS crédible rapidement.</li>
        </ul>
    </article>
</section>
PHP;
    }

    private function templateBillingSuccessView(): string
    {
        return <<<'PHP'
<section class="mx-auto max-w-2xl panel-card p-8 text-center">
    <p class="eyebrow">Stripe billing</p>
    <h1 class="display-title mt-3 text-4xl">Paiement confirmé</h1>
    <p class="lead-copy mt-4">La session Stripe a bien été créée. Tu peux maintenant utiliser le webhook pour fiabiliser l’état d’abonnement.</p>
    <?php if ($sessionId !== ''): ?>
        <p class="meta-copy mt-3">Session: <?= htmlspecialchars((string) $sessionId, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <a class="button-primary mt-8 inline-flex" href="<?= htmlspecialchars((string) $accountPath, ENT_QUOTES, 'UTF-8') ?>">Retour au compte</a>
</section>
PHP;
    }

    private function templateBillingCancelView(): string
    {
        return <<<'PHP'
<section class="mx-auto max-w-2xl panel-card p-8 text-center">
    <p class="eyebrow">Stripe billing</p>
    <h1 class="display-title mt-3 text-4xl">Checkout annulé</h1>
    <p class="lead-copy mt-4">Aucun problème. La page de pricing et le flux Stripe restent déjà branchés pour réessayer quand tu veux.</p>
    <a class="button-primary mt-8 inline-flex" href="<?= htmlspecialchars((string) $pricingPath, ENT_QUOTES, 'UTF-8') ?>">Retour au pricing</a>
</section>
PHP;
    }
}