<?php

declare(strict_types=1);

final class ProjectBlueprint
{
    public function __construct(
        private readonly array $config,
        private readonly string $targetDir,
        private readonly InstallerFeatures $features,
        private readonly TemplateCatalog $templates,
    ) {
    }

    public function directories(): array
    {
        $directories = [
            'app',
            'app/Console',
            'app/config',
            'app/Controllers',
            'app/Database',
            'app/Database/Migrations',
            'app/Database/Seeds',
            'app/Jobs',
            'app/Middleware',
            'app/Models',
            'app/Queues',
            'app/Services',
            'app/storage',
            'app/storage/cache',
            'app/storage/logs',
            'app/storage/queue',
            'app/Support',
            'app/routes',
            'app/Views',
            'app/Views/account',
            'app/Views/auth',
            'app/Views/billing',
            'app/Views/layouts',
            'public',
            'tests',
        ];

        array_push(
            $directories,
            'app/Controllers/Auth'
        );

        if ($this->features->backoffice) {
            array_push(
                $directories,
                'app/Controllers/Admin',
                'app/Views/admin',
                'app/Views/admin/layouts',
                'app/Views/admin/generated'
            );
        }

        if ($this->features->frontend) {
            array_push(
                $directories,
                'public/build',
                'resources',
                'resources/css',
                'resources/js',
                'public/assets'
            );
        }

        if ($this->features->mcp) {
            array_push($directories,
                'app/Mcp',
                'app/Controllers/Business',
                'app/Services/Business',
                'app/Models/Business',
                'app/Views/business',
                'app/storage/atelier',
                'app/storage/atelier/drafts',
                'app/storage/atelier/releases',
                'resources',
                'resources/css',
                'resources/js'
            );
        }

        return array_map(fn (string $path): string => $this->joinPath($this->targetDir, $path), $directories);
    }

    public function files(): array
    {
        $resolvedFiles = [];

        foreach ($this->templates->files($this->variables()) as $relativePath => $content) {
            $resolvedFiles[$this->joinPath($this->targetDir, $relativePath)] = $content;
        }

        return $resolvedFiles;
    }

    private function variables(): array
    {
        $design = $this->resolveDesign();

        $vars = [
            'namespace' => trim((string) $this->config['namespace'], '\\'),
            'project_name' => (string) $this->config['project_name'],
            'base_url' => (string) $this->config['base_url'],
            'timezone' => (string) $this->config['timezone'],
            'admin_path' => (string) $this->config['backoffice']['path'],
            'admin_email' => (string) $this->config['backoffice']['default_email'],
            'admin_password' => (string) $this->config['backoffice']['default_password'],
            'auth_enabled' => !empty($this->config['auth']['enabled']) ? 'true' : 'false',
            'auth_session_key' => (string) $this->config['auth']['session_key'],
            'auth_login_path' => (string) $this->config['auth']['login_path'],
            'auth_logout_path' => (string) $this->config['auth']['logout_path'],
            'auth_home_path' => (string) $this->config['auth']['home_path'],
            'auth_throttle_attempts' => (string) $this->config['auth']['throttle_attempts'],
            'auth_throttle_decay_seconds' => (string) $this->config['auth']['throttle_decay_seconds'],
            'frontend_auth_enabled' => !empty($this->config['frontend_auth']['enabled']) ? 'true' : 'false',
            'frontend_auth_registration_enabled' => !empty($this->config['frontend_auth']['registration_enabled']) ? 'true' : 'false',
            'frontend_auth_session_key' => (string) $this->config['frontend_auth']['session_key'],
            'frontend_auth_login_path' => (string) $this->config['frontend_auth']['login_path'],
            'frontend_auth_register_path' => (string) $this->config['frontend_auth']['register_path'],
            'frontend_auth_logout_path' => (string) $this->config['frontend_auth']['logout_path'],
            'frontend_auth_home_path' => (string) $this->config['frontend_auth']['home_path'],
            'frontend_auth_throttle_attempts' => (string) $this->config['frontend_auth']['throttle_attempts'],
            'frontend_auth_throttle_decay_seconds' => (string) $this->config['frontend_auth']['throttle_decay_seconds'],
            'mail_default' => (string) $this->config['mail']['default'],
            'mail_from_address' => (string) $this->config['mail']['from_address'],
            'mail_from_name' => (string) $this->config['mail']['from_name'],
            'mail_log_path' => (string) $this->config['mail']['log_path'],
            'mail_smtp_scheme' => (string) $this->config['mail']['smtp']['scheme'],
            'mail_smtp_host' => (string) $this->config['mail']['smtp']['host'],
            'mail_smtp_port' => (string) $this->config['mail']['smtp']['port'],
            'mail_smtp_encryption' => (string) $this->config['mail']['smtp']['encryption'],
            'mail_smtp_username' => (string) $this->config['mail']['smtp']['username'],
            'mail_smtp_password' => (string) $this->config['mail']['smtp']['password'],
            'mail_smtp_timeout' => (string) $this->config['mail']['smtp']['timeout'],
            'composer_require_mailer' => ((string) ($this->config['mail']['default'] ?? 'log') === 'smtp') ? 'true' : 'false',
            'queue_table' => (string) $this->config['queue']['table'],
            'queue_sleep' => (string) $this->config['queue']['sleep_seconds'],
            'queue_tries' => (string) $this->config['queue']['tries'],
            'seo_title_separator' => (string) $this->config['seo']['title_separator'],
            'seo_default_description' => (string) $this->config['seo']['default_description'],
            'seo_default_image' => (string) $this->config['seo']['default_image'],
            'seo_default_type' => (string) $this->config['seo']['default_type'],
            'seo_locale' => (string) $this->config['seo']['locale'],
            'seo_twitter_site' => (string) $this->config['seo']['twitter_site'],
            'seo_robots' => (string) $this->config['seo']['robots'],
            'seo_sitemap_path' => (string) $this->config['seo']['sitemap_path'],
            'seo_robots_path' => (string) $this->config['seo']['robots_path'],
            'stripe_enabled' => !empty($this->config['stripe']['enabled']) ? 'true' : 'false',
            'stripe_pricing_path' => (string) $this->config['stripe']['pricing_path'],
            'stripe_checkout_path' => (string) $this->config['stripe']['checkout_path'],
            'stripe_success_path' => (string) $this->config['stripe']['success_path'],
            'stripe_cancel_path' => (string) $this->config['stripe']['cancel_path'],
            'stripe_webhook_path' => (string) $this->config['stripe']['webhook_path'],
            'stripe_publishable_key' => (string) $this->config['stripe']['publishable_key'],
            'stripe_secret_key' => (string) $this->config['stripe']['secret_key'],
            'stripe_webhook_secret' => (string) $this->config['stripe']['webhook_secret'],
            'stripe_currency' => (string) $this->config['stripe']['currency'],
            'stripe_default_plan' => (string) ($this->config['stripe']['default_plan'] ?? 'starter_monthly'),
            'stripe_default_price_id' => (string) $this->config['stripe']['default_price_id'],
            'stripe_default_mode' => (string) $this->config['stripe']['default_mode'],
            'stripe_plans_php' => $this->exportPhp($this->config['stripe']['plans'] ?? []),
            'composer_require_stripe' => !empty($this->config['stripe']['enabled']) ? 'true' : 'false',
            'social_auth_enabled' => !empty($this->config['social_auth']['enabled']) ? 'true' : 'false',
            'google_login_enabled' => !empty($this->config['social_auth']['google']['enabled']) ? 'true' : 'false',
            'composer_require_google' => (!empty($this->config['social_auth']['enabled']) && !empty($this->config['social_auth']['google']['enabled'])) ? 'true' : 'false',
            'google_login_path' => (string) $this->config['social_auth']['google']['login_path'],
            'google_login_callback_path' => (string) $this->config['social_auth']['google']['callback_path'],
            'google_login_client_id' => (string) $this->config['social_auth']['google']['client_id'],
            'google_login_client_secret' => (string) $this->config['social_auth']['google']['client_secret'],
            'google_login_hosted_domain' => (string) $this->config['social_auth']['google']['hosted_domain'],
            'google_login_scopes' => implode(',', (array) ($this->config['social_auth']['google']['scopes'] ?? ['openid', 'email', 'profile'])),
            'github_login_enabled' => !empty($this->config['social_auth']['github']['enabled']) ? 'true' : 'false',
            'composer_require_github' => (!empty($this->config['social_auth']['enabled']) && !empty($this->config['social_auth']['github']['enabled'])) ? 'true' : 'false',
            'github_login_path' => (string) $this->config['social_auth']['github']['login_path'],
            'github_login_callback_path' => (string) $this->config['social_auth']['github']['callback_path'],
            'github_login_client_id' => (string) $this->config['social_auth']['github']['client_id'],
            'github_login_client_secret' => (string) $this->config['social_auth']['github']['client_secret'],
            'github_login_scopes' => implode(',', (array) ($this->config['social_auth']['github']['scopes'] ?? ['user:email'])),
            'sqlite_database' => (string) $this->config['database']['database'],
            'frontend_vite_enabled' => !empty($this->config['frontend']['vite']) ? 'true' : 'false',
            'frontend_tailwind_enabled' => !empty($this->config['frontend']['tailwind']) ? 'true' : 'false',
            'frontend_htmx_enabled' => !empty($this->config['frontend']['htmx']) ? 'true' : 'false',
            'frontend_alpine_enabled' => !empty($this->config['frontend']['alpine']) ? 'true' : 'false',
            'frontend_build_dir' => (string) ($this->config['frontend']['build_dir'] ?? 'build'),
            'frontend_dev_server' => (string) ($this->config['frontend']['dev_server'] ?? ''),
            'backoffice_enabled' => $this->features->backoffice ? 'true' : 'false',
            'mcp_enabled' => $this->features->mcp ? 'true' : 'false',
            'design_config_php' => $this->exportPhp($design),
            'design_preset' => (string) ($design['preset'] ?? 'custom'),
            'design_label' => (string) ($design['label'] ?? 'Custom'),
            'design_fullscreen' => !empty($design['layout']['fullscreen']) ? 'true' : 'false',
            'design_content_width' => (string) ($design['layout']['content_width'] ?? '1180px'),
            'design_font_import_url' => (string) ($design['typography']['font_import_url'] ?? ''),
            'design_font_display' => (string) ($design['typography']['display'] ?? '"Space Grotesk", "Segoe UI", sans-serif'),
            'design_font_body' => (string) ($design['typography']['body'] ?? '"Manrope", "Segoe UI", sans-serif'),
            'design_color_primary' => (string) ($design['palette']['primary'] ?? '#2563eb'),
            'design_color_accent' => (string) ($design['palette']['accent'] ?? '#14b8a6'),
            'design_color_background' => (string) ($design['palette']['background'] ?? '#081120'),
            'design_color_surface' => (string) ($design['palette']['surface'] ?? 'rgba(8, 17, 32, 0.78)'),
            'design_color_surface_alt' => (string) ($design['palette']['surface_alt'] ?? 'rgba(255, 255, 255, 0.06)'),
            'design_color_text' => (string) ($design['palette']['text'] ?? '#eff6ff'),
            'design_color_muted' => (string) ($design['palette']['muted'] ?? '#cbd5e1'),
            'design_color_border' => (string) ($design['palette']['border'] ?? 'rgba(191, 219, 254, 0.18)'),
            'design_radius' => (string) ($design['effects']['radius'] ?? '30px'),
            'design_shadow' => (string) ($design['effects']['shadow'] ?? '0 30px 90px rgba(2, 6, 23, 0.45)'),
            'design_background_image' => (string) ($design['effects']['background_image'] ?? 'linear-gradient(140deg, #081120 0%, #0f172a 52%, #111827 100%)'),
        ];

        return $vars + [
            'backoffice_dashboard_routes' => $this->templates->renderAdminRoutes($vars),
            'backoffice_seed' => $this->templates->backofficeSeeder(),
        ];
    }

    private function resolveDesign(): array
    {
        $design = is_array($this->config['design'] ?? null) ? $this->config['design'] : [];
        $presets = is_array($design['presets'] ?? null) ? $design['presets'] : [];
        $preset = (string) ($design['preset'] ?? 'saas');

        $resolved = [];
        if (isset($presets[$preset]) && is_array($presets[$preset])) {
            $resolved = $presets[$preset];
        } elseif ($presets !== []) {
            $firstPreset = reset($presets);
            $resolved = is_array($firstPreset) ? $firstPreset : [];
        }

        $resolved = $this->mergeRecursiveDistinct($resolved, is_array($design['overrides'] ?? null) ? $design['overrides'] : []);
        $resolved['preset'] = $preset;
        $resolved['label'] = (string) ($resolved['label'] ?? ucfirst(str_replace('_', ' ', $preset)));

        return $resolved;
    }

    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function joinPath(string ...$segments): string
    {
        $trimmed = [];
        foreach ($segments as $index => $segment) {
            $trimmed[] = $index === 0
                ? rtrim($segment, '\\/')
                : trim($segment, '\\/');
        }

        return implode(DIRECTORY_SEPARATOR, $trimmed);
    }

    private function exportPhp(mixed $value): string
    {
        return var_export($value, true);
    }
}
