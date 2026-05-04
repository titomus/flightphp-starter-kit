<?php

declare(strict_types=1);

final class ConsoleTemplateCatalog
{
    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    public function files(array $vars): array
    {
        return [
            'app/Services/Migrator.php' => $this->renderer->render($this->templateMigratorService(), $vars),
            'app/Services/Generator.php' => $this->renderer->render($this->templateGeneratorService(), $vars),
            'app/Services/CrudGenerator.php' => $this->renderer->render($this->templateCrudGeneratorService(), $vars),
            'app/Jobs/JobInterface.php' => $this->renderer->render($this->templateJobInterface(), $vars),
            'app/Jobs/ExampleJob.php' => $this->renderer->render($this->templateExampleJob(), $vars),
            'app/Jobs/SendMailJob.php' => $this->renderer->render($this->templateSendMailJob(), $vars),
            'app/Queues/QueueManager.php' => $this->renderer->render($this->templateQueueManager(), $vars),
            'app/Console/Kernel.php' => $this->renderer->render($this->templateConsoleKernel(), $vars),
            'app/Console/console.php' => $this->renderer->render($this->templateConsoleEntry(), $vars),
            'app/Database/Migrations/20260428000000_create_jobs_table.php' => $this->renderer->render($this->templateJobsMigration(), $vars),
            'app/Database/Seeds/DatabaseSeeder.php' => $this->renderer->render($this->templateDatabaseSeeder(), $vars),
            'app/config/queue.php' => $this->renderer->render($this->templateQueueConfig(), $vars),
        ];
    }

    private function templateMigratorService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->ensureRepository();
        foreach ($this->files() as $file) {
            $migration = require $file;
            $name = $migration->name();

            if ($this->hasRun($name)) {
                continue;
            }

            $migration->up($this->pdo);
            $statement = $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
            $statement->execute(['migration' => $name]);
            echo 'Migrated: ' . $name . PHP_EOL;
        }
    }

    public function rollback(): void
    {
        $this->ensureRepository();
        $migrationName = $this->pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
        if (!$migrationName) {
            echo 'Nothing to rollback.' . PHP_EOL;
            return;
        }

        foreach (array_reverse($this->files()) as $file) {
            $migration = require $file;
            if ($migration->name() !== $migrationName) {
                continue;
            }

            $migration->down($this->pdo);
            $statement = $this->pdo->prepare('DELETE FROM migrations WHERE migration = :migration');
            $statement->execute(['migration' => $migrationName]);
            echo 'Rolled back: ' . $migrationName . PHP_EOL;
            return;
        }

        echo 'Migration file not found for rollback: ' . $migrationName . PHP_EOL;
    }

    private function ensureRepository(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    }

    private function hasRun(string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = :migration');
        $statement->execute(['migration' => $name]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function files(): array
    {
        $files = glob(base_path('app/Database/Migrations/*.php')) ?: [];
        sort($files);

        return $files;
    }
}
PHP;
    }

    private function templateGeneratorService(): string
    {
        return <<<'GENERATOR_TEMPLATE'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

final class Generator
{
    public function makeController(string $name): string
    {
        $class = $this->normalizeClass($name, 'Controller');
        $path = base_path('app/Controllers/' . $class . '.php');
        $contents = <<<PHP
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers;

final class {$class} extends BaseController
{
    public function __invoke(): void
    {
        echo 'Controller {$class} ready.';
    }
}
PHP;

        return $this->write($path, $contents);
    }

    public function makeModel(string $name): string
    {
        $class = $this->normalizeClass($name);
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class) ?? $class) . 's';
        $path = base_path('app/Models/' . $class . '.php');
        $contents = <<<PHP
<?php

declare(strict_types=1);

namespace {{namespace}}\Models;

final class {$class} extends BaseModel
{
    protected string $table = '{$table}';
}
PHP;

        return $this->write($path, $contents);
    }

    public function makeMigration(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name) ?? $name, '_'));
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $slug)));
        $file = base_path('app/Database/Migrations/' . date('YmdHis') . '_' . $slug . '.php');
        $contents = <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return '{$className}';
    }

    public function up(\PDO $pdo): void
    {
        // Write your migration here.
    }

    public function down(\PDO $pdo): void
    {
        // Write your rollback here.
    }
};
PHP;

        return $this->write($file, $contents);
    }

    public function makeJob(string $name): string
    {
        $class = $this->normalizeClass($name, 'Job');
        $file = base_path('app/Jobs/' . $class . '.php');
        $contents = <<<PHP
<?php

declare(strict_types=1);

namespace {{namespace}}\Jobs;

final class {$class} implements JobInterface
{
    public function handle(array $payload = []): void
    {
        file_put_contents(storage_path('logs/jobs.log'), '[' . date('c') . '] {$class}: ' . json_encode($payload) . PHP_EOL, FILE_APPEND);
    }
}
PHP;

        return $this->write($file, $contents);
    }

    private function write(string $path, string $contents): string
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, str_replace('{{namespace}}', '{{namespace}}', $contents));

        return $path;
    }

    private function normalizeClass(string $name, string $suffix = ''): string
    {
        $name = preg_replace('/[^a-zA-Z0-9]+/', ' ', $name) ?? $name;
        $class = str_replace(' ', '', ucwords(trim($name)));

        return str_ends_with($class, $suffix) ? $class : $class . $suffix;
    }
}
GENERATOR_TEMPLATE;
    }

    private function templateCrudGeneratorService(): string
    {
        return <<<'CRUD_TEMPLATE'
<?php

declare(strict_types=1);

namespace {{namespace}}\Services;

final class CrudGenerator
{
    public function generate(string $resource, array $fields): array
    {
        $plural = $this->normalizeResource($resource);
        $entity = str_replace(' ', '', ucwords(str_replace('_', ' ', rtrim($plural, 's'))));
        $controllerClass = $entity . 'Controller';
        $controllerPath = base_path('app/Controllers/Admin/' . $controllerClass . '.php');
        $viewDir = view_path('admin/generated/' . $plural);
        $routesPath = app_path('routes/admin_generated.php');
        $migrationPath = app_path('Database/Migrations/' . date('YmdHis') . '_create_' . $plural . '_table.php');
        $table = $plural;
        $fields = $this->normalizeFields($fields);

        if (!is_dir(dirname($controllerPath))) {
            mkdir(dirname($controllerPath), 0777, true);
        }

        if (!is_dir($viewDir)) {
            mkdir($viewDir, 0777, true);
        }

        if (!is_dir(dirname($migrationPath))) {
            mkdir(dirname($migrationPath), 0777, true);
        }

        $columns = var_export($fields, true);

        $controller = <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Controllers\Admin;

use {{namespace}}\Controllers\BaseController;
use {{namespace}}\Services\Database;

final class __CONTROLLER_CLASS__ extends BaseController
{
    private string $table = '__TABLE__';
    private array $fields = __FIELDS__;

    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $total = (int) Database::connection()->query('SELECT COUNT(*) FROM ' . $this->table)->fetchColumn();
        $records = Database::connection()->query('SELECT * FROM ' . $this->table . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset)->fetchAll();

        echo $this->view('admin.generated.__PLURAL__.index', [
            'records' => $records,
            'fields' => $this->fields,
            'title' => '__ENTITY__',
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'basePath' => config('backoffice.path', '/admin') . '/__PLURAL__',
            'success' => flash_get('crud.success'),
            'error' => flash_get('crud.error'),
        ], 'admin.layouts.main');
    }

    public function create(): void
    {
        echo $this->view('admin.generated.__PLURAL__.form', [
            'fields' => $this->fields,
            'title' => 'Create __ENTITY__',
            'record' => [],
            'action' => config('backoffice.path', '/admin') . '/__PLURAL__',
            'submitLabel' => 'Create',
            'error' => flash_get('crud.error'),
        ], 'admin.layouts.main');
    }

    public function edit(string $id): void
    {
        $record = $this->findRecord((int) $id);
        if ($record === null) {
            flash_set('crud.error', '__ENTITY__ not found.');
            redirect(config('backoffice.path', '/admin') . '/__PLURAL__');
        }

        echo $this->view('admin.generated.__PLURAL__.form', [
            'fields' => $this->fields,
            'title' => 'Edit __ENTITY__',
            'record' => $record,
            'action' => config('backoffice.path', '/admin') . '/__PLURAL__/' . (int) $id,
            'submitLabel' => 'Save changes',
            'error' => flash_get('crud.error'),
        ], 'admin.layouts.main');
    }

    public function store(): void
    {
        $this->guardCsrf();
        [$payload, $errors] = $this->payloadFromRequest();

        if ($errors !== []) {
            flash_set('crud.error', implode(' ', $errors));
            redirect(config('backoffice.path', '/admin') . '/__PLURAL__/create');
        }

        $columns = implode(', ', array_keys($payload));
        $params = implode(', ', array_map(static fn (string $field): string => ':' . $field, array_keys($payload)));
        $statement = Database::connection()->prepare('INSERT INTO ' . $this->table . ' (' . $columns . ') VALUES (' . $params . ')');
        $statement->execute($payload);

        flash_set('crud.success', '__ENTITY__ created.');
        redirect(config('backoffice.path', '/admin') . '/__PLURAL__');
    }

    public function update(string $id): void
    {
        $this->guardCsrf();
        $record = $this->findRecord((int) $id);
        if ($record === null) {
            flash_set('crud.error', '__ENTITY__ not found.');
            redirect(config('backoffice.path', '/admin') . '/__PLURAL__');
        }

        [$payload, $errors] = $this->payloadFromRequest();
        if ($errors !== []) {
            flash_set('crud.error', implode(' ', $errors));
            redirect(config('backoffice.path', '/admin') . '/__PLURAL__/' . (int) $id . '/edit');
        }

        $assignments = implode(', ', array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($payload)));
        $payload['id'] = (int) $id;
        $statement = Database::connection()->prepare('UPDATE ' . $this->table . ' SET ' . $assignments . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute($payload);

        flash_set('crud.success', '__ENTITY__ updated.');
        redirect(config('backoffice.path', '/admin') . '/__PLURAL__');
    }

    public function destroy(string $id): void
    {
        $this->guardCsrf();
        $statement = Database::connection()->prepare('DELETE FROM ' . $this->table . ' WHERE id = :id');
        $statement->execute(['id' => (int) $id]);

        flash_set('crud.success', '__ENTITY__ deleted.');
        redirect(config('backoffice.path', '/admin') . '/__PLURAL__');
    }

    private function findRecord(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM ' . $this->table . ' WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();

        return is_array($record) ? $record : null;
    }

    private function payloadFromRequest(): array
    {
        $payload = [];
        $errors = [];

        foreach ($this->fields as $field => $definition) {
            $meta = $this->fieldMeta((string) $definition);
            $value = $_POST[$field] ?? null;

            if ($meta['type'] === 'boolean') {
                $payload[$field] = ((string) $value === '1') ? 1 : 0;
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($meta['required'] && ($value === null || $value === '')) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
                continue;
            }

            if ($value === '') {
                $payload[$field] = null;
                continue;
            }

            $payload[$field] = match ($meta['type']) {
                'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null,
                'float' => is_numeric($value) ? (float) $value : null,
                default => $value,
            };
        }

        return [$payload, $errors];
    }

    private function fieldMeta(string $definition): array
    {
        $parts = array_values(array_filter(explode(':', strtolower($definition)), static fn (string $part): bool => $part !== ''));
        $type = $parts[0] ?? 'string';

        if ($type === 'int') {
            $type = 'integer';
        }

        if ($type === 'bool') {
            $type = 'boolean';
        }

        return [
            'type' => $type,
            'required' => in_array('required', $parts, true),
            'options' => $type === 'select' && isset($parts[1]) ? array_filter(array_map('trim', explode(',', $parts[1]))) : [],
        ];
    }
}
PHP;

        file_put_contents($controllerPath, strtr($controller, [
            '__CONTROLLER_CLASS__' => $controllerClass,
            '__TABLE__' => $table,
            '__FIELDS__' => $columns,
            '__PLURAL__' => $plural,
            '__ENTITY__' => $entity,
        ]));

        $index = <<<'VIEW'
<section class="panel-card p-8">
    <header class="mb-8 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <p class="eyebrow">Generated CRUD</p>
            <h1 class="display-title text-3xl"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <a class="button-primary" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/create">Add new</a>
    </header>

    <?php if (!empty($success)): ?>
        <div class="notice notice--success mb-6"><?= htmlspecialchars((string) $success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="notice notice--error mb-6"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="surface-card overflow-x-auto p-1">
        <table class="min-w-full text-left text-sm">
            <thead class="meta-copy uppercase">
            <tr>
                <?php foreach ($fields as $field => $type): ?>
                    <th class="px-4 py-3"><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?></th>
                <?php endforeach; ?>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y">
            <?php foreach ($records as $record): ?>
                <tr>
                    <?php foreach ($fields as $field => $type): ?>
                        <td class="px-4 py-3"><?= htmlspecialchars((string) ($record[$field] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php endforeach; ?>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            <a class="button-secondary" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/<?= (int) $record['id'] ?>/edit">Edit</a>
                            <form method="post" action="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>/<?= (int) $record['id'] ?>/delete" onsubmit="return confirm('Delete this record?')">
                                <?= csrf_field() ?>
                                <button class="button-secondary" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($records === []): ?>
                <tr><td class="muted-copy px-4 py-6" colspan="<?= count($fields) + 1 ?>">No records yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php $lastPage = max(1, (int) ceil(((int) $total) / ((int) $perPage))); ?>
    <footer class="mt-6 flex items-center justify-between gap-4">
        <p class="meta-copy">Page <?= (int) $page ?> / <?= (int) $lastPage ?> - <?= (int) $total ?> records</p>
        <div class="flex gap-2">
            <?php if ((int) $page > 1): ?>
                <a class="button-secondary" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>?page=<?= (int) $page - 1 ?>">Previous</a>
            <?php endif; ?>
            <?php if ((int) $page < $lastPage): ?>
                <a class="button-secondary" href="<?= htmlspecialchars((string) $basePath, ENT_QUOTES, 'UTF-8') ?>?page=<?= (int) $page + 1 ?>">Next</a>
            <?php endif; ?>
        </div>
    </footer>
</section>
VIEW;

        $form = <<<'VIEW'
<section class="panel-card p-8">
    <header class="mb-8">
        <p class="eyebrow">Generated CRUD</p>
        <h1 class="display-title text-3xl"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    </header>

    <?php if (!empty($error)): ?>
        <div class="notice notice--error mb-6"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php
    $fieldMeta = static function (string $definition): array {
        $parts = array_values(array_filter(explode(':', strtolower($definition)), static fn (string $part): bool => $part !== ''));
        $type = $parts[0] ?? 'string';
        if ($type === 'int') { $type = 'integer'; }
        if ($type === 'bool') { $type = 'boolean'; }
        return [
            'type' => $type,
            'required' => in_array('required', $parts, true),
            'options' => $type === 'select' && isset($parts[1]) ? array_filter(array_map('trim', explode(',', $parts[1]))) : [],
        ];
    };
    ?>

    <form class="space-y-5" method="post" action="<?= htmlspecialchars((string) $action, ENT_QUOTES, 'UTF-8') ?>">
        <?= csrf_field() ?>
        <?php foreach ($fields as $field => $type): ?>
            <?php $meta = $fieldMeta((string) $type); $value = (string) ($record[$field] ?? ''); ?>
            <label class="block space-y-2">
                <span class="field-label"><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?><?= $meta['required'] ? ' *' : '' ?></span>
                <?php if ($meta['type'] === 'text'): ?>
                    <textarea class="field-input min-h-36" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" <?= $meta['required'] ? 'required' : '' ?>><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                <?php elseif ($meta['type'] === 'boolean'): ?>
                    <input type="hidden" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" value="0">
                    <input class="h-5 w-5" type="checkbox" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= !empty($record[$field]) ? 'checked' : '' ?>>
                <?php elseif ($meta['type'] === 'select'): ?>
                    <select class="field-input" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" <?= $meta['required'] ? 'required' : '' ?>>
                        <option value="">Choose...</option>
                        <?php foreach ($meta['options'] as $option): ?>
                            <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES, 'UTF-8') ?>" <?= $value === (string) $option ? 'selected' : '' ?>><?= htmlspecialchars((string) $option, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php $inputType = match ($meta['type']) { 'integer', 'float' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', default => 'text' }; ?>
                    <input class="field-input" type="<?= $inputType ?>" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $meta['type'] === 'float' ? 'step="any"' : '' ?> <?= $meta['required'] ? 'required' : '' ?>>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>
        <div class="flex flex-wrap gap-3">
            <button class="button-primary" type="submit"><?= htmlspecialchars((string) $submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
            <a class="button-secondary" href="<?= htmlspecialchars(config('backoffice.path', '/admin'), ENT_QUOTES, 'UTF-8') ?>/{{plural}}">Cancel</a>
        </div>
    </form>
</section>
VIEW;

        file_put_contents($viewDir . '/index.php', str_replace('{{plural}}', $plural, $index));
        file_put_contents($viewDir . '/form.php', str_replace('{{plural}}', $plural, $form));

        if (!is_file($migrationPath)) {
            file_put_contents($migrationPath, $this->migrationTemplate($plural, $fields));
        }

        $routeChunk = <<<'PHP'

\Flight::route('GET ' . config('backoffice.path', '/admin') . '/__PLURAL__', middleware([
    \{{namespace}}\Middleware\AuthenticateMiddleware::class,
], [new \{{namespace}}\Controllers\Admin\__CONTROLLER_CLASS__(), 'index']));
\Flight::route('GET ' . config('backoffice.path', '/admin') . '/__PLURAL__/create', middleware([
    \{{namespace}}\Middleware\AuthenticateMiddleware::class,
], [new \{{namespace}}\Controllers\Admin\__CONTROLLER_CLASS__(), 'create']));
\Flight::route('POST ' . config('backoffice.path', '/admin') . '/__PLURAL__', middleware([
    \{{namespace}}\Middleware\AuthenticateMiddleware::class,
    \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
], [new \{{namespace}}\Controllers\Admin\__CONTROLLER_CLASS__(), 'store']));
\Flight::route('GET ' . config('backoffice.path', '/admin') . '/__PLURAL__/@id/edit', middleware([
    \{{namespace}}\Middleware\AuthenticateMiddleware::class,
], [new \{{namespace}}\Controllers\Admin\__CONTROLLER_CLASS__(), 'edit']));
\Flight::route('POST ' . config('backoffice.path', '/admin') . '/__PLURAL__/@id', middleware([
    \{{namespace}}\Middleware\AuthenticateMiddleware::class,
    \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
], [new \{{namespace}}\Controllers\Admin\__CONTROLLER_CLASS__(), 'update']));
\Flight::route('POST ' . config('backoffice.path', '/admin') . '/__PLURAL__/@id/delete', middleware([
    \{{namespace}}\Middleware\AuthenticateMiddleware::class,
    \{{namespace}}\Middleware\VerifyCsrfMiddleware::class,
], [new \{{namespace}}\Controllers\Admin\__CONTROLLER_CLASS__(), 'destroy']));
PHP;

        $routeChunk = strtr($routeChunk, [
            '__PLURAL__' => $plural,
            '__CONTROLLER_CLASS__' => $controllerClass,
        ]);

        $currentRoutes = is_file($routesPath) ? (string) file_get_contents($routesPath) : "<?php\n\n";
        if (!str_contains($currentRoutes, $controllerClass)) {
            file_put_contents($routesPath, rtrim($currentRoutes) . $routeChunk . "\n");
        }

        return [
            'controller' => $controllerPath,
            'views' => $viewDir,
            'migration' => $migrationPath,
            'routes' => $routesPath,
        ];
    }

    private function normalizeResource(string $resource): string
    {
        $resource = strtolower(trim($resource));
        $resource = preg_replace('/[^a-z0-9_]+/', '_', $resource) ?: 'records';
        $resource = trim($resource, '_');

        return $resource !== '' ? $resource : 'records';
    }

    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field => $type) {
            $name = strtolower((string) $field);
            $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?: '';
            $name = trim($name, '_');

            if ($name === '' || $name === 'id') {
                continue;
            }

            $normalized[$name] = $this->normalizeType((string) $type);
        }

        return $normalized !== [] ? $normalized : ['title' => 'string:required', 'content' => 'text'];
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $base = explode(':', $type, 2)[0] ?: 'string';
        $aliases = ['int' => 'integer', 'bool' => 'boolean', 'decimal' => 'float'];
        $base = $aliases[$base] ?? $base;
        $allowed = ['string', 'text', 'integer', 'float', 'boolean', 'date', 'datetime', 'select'];

        return in_array($base, $allowed, true) ? $type : 'string';
    }

    private function migrationTemplate(string $table, array $fields): string
    {
        $columns = [];
        foreach ($fields as $field => $type) {
            $columns[] = '    ' . $field . ' ' . $this->sqlType((string) $type);
        }

        $columnsSql = implode(",\n", $columns);
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', 'create_' . $table . '_table')));

        return <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return '{$className}';
    }

    public function up(\PDO \$pdo): void
    {
        \$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS {$table} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
{$columnsSql},
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    public function down(\PDO \$pdo): void
    {
        \$pdo->exec('DROP TABLE IF EXISTS {$table}');
    }
};
PHP;
    }

    private function sqlType(string $type): string
    {
        $base = explode(':', strtolower($type), 2)[0] ?: 'string';

        return match ($base) {
            'text' => 'TEXT',
            'integer', 'int', 'boolean', 'bool' => 'INTEGER',
            'float', 'decimal' => 'REAL',
            'date', 'datetime' => 'TEXT',
            default => 'TEXT',
        };
    }
}
CRUD_TEMPLATE;
    }

    private function templateJobInterface(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Jobs;

interface JobInterface
{
    public function handle(array $payload = []): void;
}
PHP;
    }

    private function templateExampleJob(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Jobs;

final class ExampleJob implements JobInterface
{
    public function handle(array $payload = []): void
    {
        $message = $payload['message'] ?? 'No message provided';
        file_put_contents(storage_path('logs/jobs.log'), '[' . date('c') . '] ExampleJob: ' . $message . PHP_EOL, FILE_APPEND);
    }
}
PHP;
    }

    private function templateSendMailJob(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Jobs;

use {{namespace}}\Services\Mailer;

final class SendMailJob implements JobInterface
{
    public function handle(array $payload = []): void
    {
        $to = (string) ($payload['to'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $html = (string) ($payload['html'] ?? '');
        $text = (string) ($payload['text'] ?? '');

        if ($to === '' || $subject === '') {
            throw new \InvalidArgumentException('SendMailJob requires at least "to" and "subject" payload keys.');
        }

        (new Mailer())->send($to, $subject, $html, $text);
    }
}
PHP;
    }

    private function templateQueueManager(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Queues;

use {{namespace}}\Jobs\JobInterface;
use {{namespace}}\Services\Database;
use PDO;

final class QueueManager
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function dispatch(string $jobClass, array $payload = []): void
    {
        $statement = $this->pdo->prepare('INSERT INTO {{queue_table}} (job_class, payload, status, attempts, available_at, created_at) VALUES (:job_class, :payload, :status, :attempts, :available_at, :created_at)');
        $statement->execute([
            'job_class' => $jobClass,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function work(bool $once = false): void
    {
        do {
            $job = $this->nextJob();
            if ($job === null) {
                if ($once) {
                    echo 'No pending jobs.' . PHP_EOL;
                    return;
                }

                sleep((int) config('queue.sleep_seconds', {{queue_sleep}}));
                continue;
            }

            $this->process($job);
        } while (!$once);
    }

    private function nextJob(): ?array
    {
        $statement = $this->pdo->query("SELECT * FROM {{queue_table}} WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        $job = $statement->fetch();

        return $job ?: null;
    }

    private function process(array $job): void
    {
        $this->pdo->prepare("UPDATE {{queue_table}} SET status = 'processing', attempts = attempts + 1 WHERE id = :id")
            ->execute(['id' => $job['id']]);

        try {
            $jobClass = $job['job_class'];
            if (!class_exists($jobClass)) {
                throw new \RuntimeException('Job class not found: ' . $jobClass);
            }

            $instance = new $jobClass();
            if (!$instance instanceof JobInterface) {
                throw new \RuntimeException('Job class must implement JobInterface: ' . $jobClass);
            }

            $payload = json_decode((string) $job['payload'], true, 512, JSON_THROW_ON_ERROR);
            $instance->handle(is_array($payload) ? $payload : []);

            $this->pdo->prepare("UPDATE {{queue_table}} SET status = 'done', finished_at = :finished_at WHERE id = :id")
                ->execute(['id' => $job['id'], 'finished_at' => date('Y-m-d H:i:s')]);

            echo 'Processed job #' . $job['id'] . ' (' . $jobClass . ')' . PHP_EOL;
        } catch (\Throwable $exception) {
            $maxTries = (int) config('queue.tries', {{queue_tries}});
            $status = ((int) $job['attempts'] + 1) >= $maxTries ? 'failed' : 'pending';

            $this->pdo->prepare("UPDATE {{queue_table}} SET status = :status, error_message = :message WHERE id = :id")
                ->execute([
                    'id' => $job['id'],
                    'status' => $status,
                    'message' => $exception->getMessage(),
                ]);

            file_put_contents(storage_path('logs/jobs.log'), '[' . date('c') . '] Job failed: ' . $exception->getMessage() . PHP_EOL, FILE_APPEND);
            echo 'Job failed: ' . $exception->getMessage() . PHP_EOL;
        }
    }
}
PHP;
    }

    private function templateConsoleKernel(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}}\Console;

use {{namespace}}\Queues\QueueManager;
use {{namespace}}\Services\CrudGenerator;
use {{namespace}}\Services\Database;
use {{namespace}}\Services\Generator;
use {{namespace}}\Services\Migrator;

final class Kernel
{
    public function handle(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        return match ($command) {
            'migrate' => $this->migrate(),
            'rollback' => $this->rollback(),
            'seed' => $this->seed(),
            'queue:dispatch' => $this->dispatch($argv),
            'queue:work' => $this->work($argv),
            'make:controller' => $this->makeController($argv),
            'make:model' => $this->makeModel($argv),
            'make:migration' => $this->makeMigration($argv),
            'make:job' => $this->makeJob($argv),
            'make:backoffice' => $this->makeBackoffice($argv),
            default => $this->help(),
        };
    }

    private function migrate(): int
    {
        (new Migrator(Database::connection()))->migrate();
        return 0;
    }

    private function rollback(): int
    {
        (new Migrator(Database::connection()))->rollback();
        return 0;
    }

    private function seed(): int
    {
        $seeder = require base_path('app/Database/Seeds/DatabaseSeeder.php');
        $seeder(Database::connection());
        return 0;
    }

    private function dispatch(array $argv): int
    {
        $jobClass = $argv[2] ?? null;
        if ($jobClass === null) {
            echo 'Usage: php app/Console/console.php queue:dispatch App\\Jobs\\ExampleJob "{"message":"hello"}"' . PHP_EOL;
            echo 'Example mail job: php app/Console/console.php queue:dispatch App\\Jobs\\SendMailJob "{"to":"hello@example.com","subject":"Welcome","html":"<h1>Hello</h1>"}"' . PHP_EOL;
            return 1;
        }

        $payload = json_decode($argv[3] ?? '[]', true);
        (new QueueManager())->dispatch($jobClass, is_array($payload) ? $payload : []);
        echo 'Job dispatched.' . PHP_EOL;
        return 0;
    }

    private function work(array $argv): int
    {
        $once = in_array('--once', $argv, true);
        (new QueueManager())->work($once);
        return 0;
    }

    private function makeController(array $argv): int
    {
        $name = $argv[2] ?? null;
        if ($name === null) {
            echo 'Controller name is required.' . PHP_EOL;
            return 1;
        }

        echo (new Generator())->makeController($name) . PHP_EOL;
        return 0;
    }

    private function makeModel(array $argv): int
    {
        $name = $argv[2] ?? null;
        if ($name === null) {
            echo 'Model name is required.' . PHP_EOL;
            return 1;
        }

        echo (new Generator())->makeModel($name) . PHP_EOL;
        return 0;
    }

    private function makeMigration(array $argv): int
    {
        $name = $argv[2] ?? null;
        if ($name === null) {
            echo 'Migration name is required.' . PHP_EOL;
            return 1;
        }

        echo (new Generator())->makeMigration($name) . PHP_EOL;
        return 0;
    }

    private function makeJob(array $argv): int
    {
        $name = $argv[2] ?? null;
        if ($name === null) {
            echo 'Job name is required.' . PHP_EOL;
            return 1;
        }

        echo (new Generator())->makeJob($name) . PHP_EOL;
        return 0;
    }

    private function makeBackoffice(array $argv): int
    {
        $resource = $argv[2] ?? null;
        if ($resource === null) {
            echo 'Resource name is required.' . PHP_EOL;
            return 1;
        }

        $fields = [];
        foreach (array_slice($argv, 3) as $definition) {
            [$field, $type] = array_pad(explode(':', $definition, 2), 2, 'string');
            $fields[$field] = $type;
        }

        if ($fields === []) {
            $fields = ['title' => 'string', 'content' => 'text'];
        }

        $result = (new CrudGenerator())->generate($resource, $fields);
        foreach ($result as $label => $path) {
            echo $label . ': ' . $path . PHP_EOL;
        }

        return 0;
    }

    private function help(): int
    {
        echo <<<TEXT
Available commands:
  migrate
  rollback
  seed
  queue:dispatch <jobClass> [jsonPayload]
  queue:work [--once]
  make:controller <Name>
  make:model <Name>
  make:migration <name>
  make:job <Name>
  make:backoffice <resource> [field:type...]
TEXT;

        return 0;
    }
}
PHP;
    }

    private function templateConsoleEntry(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

define('APP_CONTEXT', 'cli');

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$kernel = new {{namespace}}\Console\Kernel();
exit($kernel->handle($argv));
PHP;
    }

    private function templateJobsMigration(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return new class {
    public function name(): string
    {
        return 'CreateJobsTable';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {{queue_table}} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_class TEXT NOT NULL,
    payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    available_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL
)
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS {{queue_table}}');
    }
};
PHP;
    }

    private function templateDatabaseSeeder(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return function (\PDO $pdo): void {
{{backoffice_seed}}
    echo 'Seed completed.' . PHP_EOL;
};
PHP;
    }

    private function templateQueueConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => env('QUEUE_DRIVER', 'database'),
    'table' => env('QUEUE_TABLE', '{{queue_table}}'),
    'sleep_seconds' => (int) env('QUEUE_SLEEP', '{{queue_sleep}}'),
    'tries' => (int) env('QUEUE_TRIES', '{{queue_tries}}'),
];
PHP;
    }
}
