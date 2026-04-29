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
        $entity = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $resource)));
        $plural = strtolower($resource);
        $controllerClass = $entity . 'Controller';
        $controllerPath = base_path('app/Controllers/Admin/' . $controllerClass . '.php');
        $viewDir = view_path('admin/generated/' . $plural);
        $routesPath = app_path('routes/admin_generated.php');
        $table = $plural;

        if (!is_dir(dirname($controllerPath))) {
            mkdir(dirname($controllerPath), 0777, true);
        }

        if (!is_dir($viewDir)) {
            mkdir($viewDir, 0777, true);
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
        $records = Database::connection()->query('SELECT * FROM ' . $this->table . ' ORDER BY id DESC')->fetchAll();
        echo $this->view('admin.generated.__PLURAL__.index', ['records' => $records, 'fields' => $this->fields, 'title' => '__ENTITY__'], 'admin.layouts.main');
    }

    public function create(): void
    {
        echo $this->view('admin.generated.__PLURAL__.create', ['fields' => $this->fields, 'title' => '__ENTITY__'], 'admin.layouts.main');
    }

    public function store(): void
    {
        $this->guardCsrf();
        $payload = [];
        foreach ($this->fields as $field => $type) {
            $payload[$field] = $_POST[$field] ?? null;
        }

        $columns = implode(', ', array_keys($payload));
        $params = implode(', ', array_map(static fn (string $field): string => ':' . $field, array_keys($payload)));
        $statement = Database::connection()->prepare('INSERT INTO ' . $this->table . ' (' . $columns . ') VALUES (' . $params . ')');
        $statement->execute($payload);

        header('Location: ' . config('backoffice.path', '/admin') . '/__PLURAL__');
        exit;
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
            <p class="mb-2 text-xs font-semibold uppercase tracking-[0.25em] text-sky-300">Generated CRUD</p>
            <h1 class="text-3xl font-black tracking-tight text-white"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <a class="button-primary" href="<?= htmlspecialchars(config('backoffice.path', '/admin'), ENT_QUOTES, 'UTF-8') ?>/{{plural}}/create">Add new</a>
    </header>

    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950/50">
        <table class="min-w-full divide-y divide-white/10 text-left text-sm text-slate-200">
            <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-slate-400">
            <tr>
                <?php foreach ($fields as $field => $type): ?>
                    <th class="px-4 py-3"><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
            <?php foreach ($records as $record): ?>
                <tr class="hover:bg-white/5">
                    <?php foreach ($fields as $field => $type): ?>
                        <td class="px-4 py-3"><?= htmlspecialchars((string) ($record[$field] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
VIEW;

        $create = <<<'VIEW'
<section class="panel-card p-8">
    <header class="mb-8">
        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.25em] text-sky-300">Generated CRUD</p>
        <h1 class="text-3xl font-black tracking-tight text-white">Create <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    </header>

    <form class="space-y-5" method="post" action="<?= htmlspecialchars(config('backoffice.path', '/admin'), ENT_QUOTES, 'UTF-8') ?>/{{plural}}">
        <?= csrf_field() ?>
        <?php foreach ($fields as $field => $type): ?>
            <label class="block space-y-2">
                <span class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-300"><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?></span>
                <input class="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-slate-100 outline-none ring-0 transition placeholder:text-slate-500 focus:border-sky-400" type="text" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>">
            </label>
        <?php endforeach; ?>
        <button class="button-primary" type="submit">Save</button>
    </form>
</section>
VIEW;

        file_put_contents($viewDir . '/index.php', str_replace('{{plural}}', $plural, $index));
        file_put_contents($viewDir . '/create.php', str_replace('{{plural}}', $plural, $create));

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
            'routes' => $routesPath,
        ];
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
