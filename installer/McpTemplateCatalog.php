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
            'app/Mcp/business_tools.php' => 'business_tools.php.tpl',
            'app/Actions/ActionContext.php' => 'ActionContext.php.tpl',
            'app/Actions/BusinessActionRegistry.php' => 'BusinessActionRegistry.php.tpl',
            'app/Actions/business.php' => 'business_actions.php.tpl',
            'app/Controllers/Business/WebActionController.php' => 'WebActionController.php.tpl',
            'app/Console/check_mcp_parity.php' => 'check_mcp_parity.php.tpl',
            'app/Mcp/DynamicOAuth.php' => 'DynamicOAuth.php.tpl',
            'app/Mcp/HttpEndpoint.php' => 'HttpEndpoint.php.tpl',
            'app/Mcp/CodeWorkspace.php' => 'CodeWorkspace.php.tpl',
            'app/Controllers/McpOAuthController.php' => 'McpOAuthController.php.tpl',
            'app/Services/EvolutionRequestService.php' => 'EvolutionRequestService.php.tpl',
            'app/Console/atelier_rollback.php' => 'atelier_rollback.php.tpl',
            'app/routes/mcp.php' => 'mcp_routes.php.tpl',
            'app/routes/business.php' => 'business_routes.php.tpl',
            'app/Database/Migrations/20261030020000_create_mcp_dcr_tables.php' => '20261030020000_create_mcp_dcr_tables.php.tpl',
            'app/Database/Migrations/20261030030000_create_application_change_requests.php' => '20261030030000_create_application_change_requests.php.tpl',
            'public/assets/business.css' => 'business.css.tpl',
            'public/assets/business.js' => 'business.js.tpl',
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

        // Real framework-neutral CodeGraft engine, vendored from titomus/CodeGraft.
        // Generated applications receive the sources, not merely four allowlisted files.
        foreach (['Policy', 'Vault', 'Workspace', 'Mcp', 'Admin'] as $class) {
            $source = __DIR__ . '/codegraft-src/' . $class . '.php';
            $contents = file_get_contents($source);
            if (!is_string($contents)) {
                throw new RuntimeException('Missing CodeGraft core: ' . $source);
            }
            $files['app/CodeGraft/' . $class . '.php'] = $contents;
        }
        $factory = file_get_contents(__DIR__ . '/templates/mcp/CodeGraftFactory.php.tpl');
        if (!is_string($factory)) {
            throw new RuntimeException('Missing CodeGraft Flight bridge.');
        }
        $files['app/Mcp/CodeGraftFactory.php'] = $this->renderer->render($factory, $vars);

        return $files;
    }
}
