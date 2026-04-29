<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreProjectTemplateCatalog.php';
require_once __DIR__ . '/CoreRuntimeTemplateCatalog.php';
require_once __DIR__ . '/CoreMvcTemplateCatalog.php';

final class CoreTemplateCatalog
{
    private CoreProjectTemplateCatalog $project;

    private CoreRuntimeTemplateCatalog $runtime;

    private CoreMvcTemplateCatalog $mvc;

    public function __construct(private readonly TemplateRenderer $renderer)
    {
        $this->project = new CoreProjectTemplateCatalog();
        $this->runtime = new CoreRuntimeTemplateCatalog($renderer);
        $this->mvc = new CoreMvcTemplateCatalog($renderer);
    }

    public function files(array $vars, InstallerFeatures $features): array
    {
        return array_merge(
            [
                'composer.json' => $this->project->templateComposerJson($vars),
                '.gitignore' => $this->project->templateGitignore(),
                '.env.example' => $this->project->templateEnvExample($vars),
                'README.md' => $this->project->templateReadme($vars, $features),
                'public/index.php' => $this->renderer->render($this->project->templatePublicIndex(), $vars),
                'public/router.php' => $this->project->templatePublicRouter(),
                'public/.htaccess' => $this->project->templateHtaccess(),
            ],
            $this->runtime->files($vars),
            $this->mvc->files($vars)
        );
    }
}