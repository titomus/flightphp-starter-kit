<?php

declare(strict_types=1);

require_once __DIR__ . '/TemplateRenderer.php';
require_once __DIR__ . '/CoreTemplateCatalog.php';
require_once __DIR__ . '/FrontendTemplateCatalog.php';
require_once __DIR__ . '/ConsoleTemplateCatalog.php';
require_once __DIR__ . '/BackofficeTemplateCatalog.php';
require_once __DIR__ . '/InstallerFeatures.php';

final class TemplateCatalog
{
    private CoreTemplateCatalog $core;

    private FrontendTemplateCatalog $frontend;

    private ConsoleTemplateCatalog $console;

    private BackofficeTemplateCatalog $backoffice;

    public function __construct(private readonly InstallerFeatures $features)
    {
        $renderer = new TemplateRenderer();
        $this->core = new CoreTemplateCatalog($renderer);
        $this->frontend = new FrontendTemplateCatalog();
        $this->console = new ConsoleTemplateCatalog($renderer);
        $this->backoffice = new BackofficeTemplateCatalog($renderer);
    }

    public function files(array $vars): array
    {
        $files = array_merge(
            $this->core->files($vars, $this->features),
            $this->console->files($vars)
        );

        if ($this->features->frontend) {
            $files = array_merge($files, $this->frontend->files($vars));
        }

        if ($this->features->backoffice) {
            $files = array_merge($files, $this->backoffice->files($vars));
        }

        return $files;
    }

    public function renderAdminRoutes(array $vars): string
    {
        return $this->features->backoffice ? $this->backoffice->adminRoutes($vars) : '';
    }

    public function backofficeSeeder(): string
    {
        return $this->features->backoffice ? $this->backoffice->seeder() : '';
    }
}
