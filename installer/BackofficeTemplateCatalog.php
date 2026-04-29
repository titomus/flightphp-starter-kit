<?php

declare(strict_types=1);

final class BackofficeTemplateCatalog
{
    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    public function files(array $vars): array
    {
        return [
            'app/Controllers/Admin/DashboardController.php' => $this->renderer->render($this->templateAdminDashboardController(), $vars),
            'app/Controllers/Admin/LoginController.php' => $this->renderer->render($this->templateAdminLoginController(), $vars),
            'app/Database/Migrations/20260428000001_create_admin_users_table.php' => $this->templateBackofficeMigration(),
            'app/Models/User.php' => $this->renderer->render($this->templateUserModel(), $vars),
            'app/Views/admin/layouts/main.php' => $this->templateAdminLayout(),
            'app/Views/admin/dashboard.php' => $this->templateAdminViewContent(),
            'app/Views/admin/auth/login.php' => $this->templateAdminLoginView(),
        ];
    }

    public function adminRoutes(array $vars): string
    {
        return $this->renderer->render($this->templateAdminRoutes(), $vars);
    }

    public function seeder(): string
    {
        return $this->templateBackofficeSeeder();
    }

    private function templateAdminDashboardController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Admin;

use {{namespace}}\Controllers\BaseController;

final class DashboardController extends BaseController
{
    public function __invoke(): void
    {
        echo $this->view('admin.dashboard', [
            'title' => 'BackOffice',
            'adminPath' => config('backoffice.path', '/admin'),
            'user' => auth('admin')->user(),
        ], 'admin.layouts.main');
    }
}
PHP;
    }

    private function templateAdminLoginController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Admin;

use {{namespace}}\Controllers\BaseController;

final class LoginController extends BaseController
{
    public function create(): void
    {
        echo $this->view('admin.auth.login', [
            'title' => 'Admin login',
            'error' => flash_get('auth.error'),
            'email' => old_input('email', ''),
        ], 'admin.layouts.main');
    }

    public function store(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        with_old_input(['email' => $email]);

        if ($email === '' || $password === '') {
            flash_set('auth.error', 'Email and password are required.');
            redirect((string) config('auth.guards.admin.login_path', '/admin/login'));
        }

        if (!auth('admin')->attempt($email, $password)) {
            flash_set('auth.error', 'Invalid credentials.');
            redirect((string) config('auth.guards.admin.login_path', '/admin/login'));
        }

        redirect((string) config('auth.guards.admin.home_path', '/admin'));
    }

    public function destroy(): void
    {
        auth('admin')->logout();
        flash_set('auth.error', 'Session closed.');
        redirect((string) config('auth.guards.admin.login_path', '/admin/login'));
    }
}
PHP;
    }

    private function templateBackofficeMigration(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateAdminUsersTable';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_users');
    }
};
PHP;
    }

    private function templateUserModel(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Models;

final class User extends BaseModel
{
    protected string $table = 'admin_users';
}
PHP;
    }

    private function templateAdminRoutes(): string
    {
        return <<<'PHP'

if ((bool) config('backoffice.enabled', false)) {
    \Flight::route('GET ' . config('auth.guards.admin.login_path', '/admin/login'), middleware([
        new \{{namespace}}\Middleware\GuestMiddleware('admin'),
    ], [new \{{namespace}}\Controllers\Admin\LoginController(), 'create']));

    \Flight::route('POST ' . config('auth.guards.admin.login_path', '/admin/login'), middleware([
        new \{{namespace}}\Middleware\ThrottleRequestsMiddleware('admin'),
        new \{{namespace}}\Middleware\GuestMiddleware('admin'),
        \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
    ], [new \{{namespace}}\Controllers\Admin\LoginController(), 'store']));

    \Flight::route('POST ' . config('auth.guards.admin.logout_path', '/admin/logout'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('admin'),
        \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
    ], [new \{{namespace}}\Controllers\Admin\LoginController(), 'destroy']));

    \Flight::route('GET ' . config('backoffice.path', '/admin'), middleware([
        new \{{namespace}}\Middleware\AuthenticateMiddleware('admin'),
    ], [new \{{namespace}}\Controllers\Admin\DashboardController(), '__invoke']));
}
PHP;
    }

    private function templateBackofficeSeeder(): string
    {
        return <<<'PHP'
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

    $statement = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE email = :email');
    $statement->execute(['email' => config('backoffice.default_email', 'admin@example.com')]);
    if ((int) $statement->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO admin_users (name, email, password) VALUES (:name, :email, :password)');
        $insert->execute([
            'name' => 'Administrator',
            'email' => config('backoffice.default_email', 'admin@example.com'),
            'password' => password_hash((string) config('backoffice.default_password', 'admin123'), PASSWORD_DEFAULT),
        ]);
    }
PHP;
    }

    private function templateAdminLayout(): string
    {
        return <<<'PHP'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'BackOffice', ENT_QUOTES, 'UTF-8') ?></title>
    <?= vite_tags(['resources/js/app.js']) ?>
</head>
<?php $design = config('design', []); $shellClass = !empty($design['layout']['fullscreen']) ? 'site-shell--full' : 'site-shell--contained'; ?>
<body class="site-body">
<main class="site-shell <?= $shellClass ?>">
    <nav class="site-nav mb-8">
        <strong class="display-title text-lg tracking-wide"><?= htmlspecialchars(config('app.name', 'Flight Starter'), ENT_QUOTES, 'UTF-8') ?></strong>
        <div class="flex items-center gap-5 text-sm font-medium">
            <a class="site-link" href="<?= htmlspecialchars(config('backoffice.path', '/admin'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
            <a class="site-link" href="/">Site</a>
            <?php if (auth('admin')->check()): ?>
                <form method="post" action="<?= htmlspecialchars(config('auth.guards.admin.logout_path', '/admin/logout'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= csrf_field() ?>
                    <button class="site-link" type="submit">Logout</button>
                </form>
            <?php endif; ?>
        </div>
    </nav>

    <?= $content ?>
</main>
</body>
</html>
PHP;
    }

    private function templateAdminViewContent(): string
    {
        return <<<'PHP'
<?php ob_start(); ?>
<section class="panel-card p-8">
    <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <p class="eyebrow">BackOffice</p>
            <h1 class="display-title text-3xl md:text-5xl"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <span class="button-primary">Starter ready</span>
    </header>

    <div class="grid gap-5 md:grid-cols-3">
        <article class="surface-card p-6">
            <p class="eyebrow">CRUD</p>
            <h2 class="section-title">Generate modules</h2>
            <p class="muted-copy mt-3">Use <code>php app/Console/console.php make:backoffice posts title:string content:text</code></p>
        </article>
        <article class="surface-card p-6">
            <p class="eyebrow">Queue</p>
            <h2 class="section-title">Asynchronous work</h2>
            <p class="muted-copy mt-3">Dispatch jobs then run <code>queue:work --once</code> during development.</p>
        </article>
        <article class="surface-card p-6">
            <p class="eyebrow">Migrations</p>
            <h2 class="section-title">Database bootstrap</h2>
            <p class="muted-copy mt-3">Run <code>migrate</code> then <code>seed</code> after install.</p>
        </article>
    </div>

    <?php if (is_array($user ?? null)): ?>
        <div class="notice notice--success mt-6">
            Logged in as <strong><?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
    <?php endif; ?>
</section>
<?php $content = (string) ob_get_clean(); include view_path('admin/layouts/main.php'); ?>
PHP;
    }

    private function templateAdminLoginView(): string
    {
        return <<<'PHP'
<?php ob_start(); ?>
<section class="mx-auto max-w-lg panel-card p-8">
    <header class="mb-8">
        <p class="eyebrow">Authentication</p>
        <h1 class="display-title text-3xl">Admin login</h1>
        <p class="muted-copy mt-3">Sign in to access the backoffice and generated CRUD routes.</p>
    </header>

    <?php if (!empty($error)): ?>
        <div class="notice notice--error mb-6">
            <?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form class="space-y-5" method="post" action="<?= htmlspecialchars(config('auth.guards.admin.login_path', '/admin/login'), ENT_QUOTES, 'UTF-8') ?>">
        <?= csrf_field() ?>
        <label class="block space-y-2">
            <span class="field-label">Email</span>
            <input class="field-input" type="email" name="email" value="<?= htmlspecialchars((string) $email, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label class="block space-y-2">
            <span class="field-label">Password</span>
            <input class="field-input" type="password" name="password" required>
        </label>
        <button class="button-primary w-full justify-center" type="submit">Sign in</button>
    </form>
</section>
<?php $content = (string) ob_get_clean(); include view_path('admin/layouts/main.php'); ?>
PHP;
    }
}
