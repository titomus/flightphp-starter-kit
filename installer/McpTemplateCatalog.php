<?php

declare(strict_types=1);

final class McpTemplateCatalog
{
    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    public function files(array $vars): array
    {
        $vars['project_name_php'] = var_export($vars['project_name'], true);

        $templates = [
            'app/Mcp/Server.php' => 'Server.php.tpl',
            'app/Mcp/tools.php' => 'tools.php.tpl',
            'public/mcp.php' => 'mcp.php.tpl',
        ];

        $files = [];
        foreach ($templates as $destination => $template) {
            $source = __DIR__ . '/templates/mcp/' . $template;
            $contents = file_get_contents($source);
            if ($contents === false) {
                throw new RuntimeException('Missing MCP template: ' . $source);
            }
            $files[$destination] = $this->renderer->render($contents, $vars);
        }

        return $files;
    }
}
