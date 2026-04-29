<?php

declare(strict_types=1);

final class FrontendTemplateCatalog
{
    public function files(array $vars): array
    {
        return [
            'package.json' => $this->templatePackageJson($vars),
            'vite.config.js' => $this->templateViteConfig($vars),
            'tailwind.config.js' => $this->templateTailwindConfig(),
            'postcss.config.js' => $this->templatePostCssConfig(),
            'app/config/frontend.php' => $this->templateFrontendConfig($vars),
            'app/config/design.php' => $this->templateDesignConfig($vars),
            'public/build/.gitkeep' => '',
            'resources/css/app.css' => $this->templateViteCss($vars),
            'resources/js/app.js' => $this->templateViteJs(),
        ];
    }

    private function templateFrontendConfig(array $vars): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(env('FRONTEND_VITE', '{$vars['frontend_vite_enabled']}'), FILTER_VALIDATE_BOOL),
    'tailwind' => filter_var(env('FRONTEND_TAILWIND', '{$vars['frontend_tailwind_enabled']}'), FILTER_VALIDATE_BOOL),
    'build_dir' => env('VITE_BUILD_DIR', '{$vars['frontend_build_dir']}'),
    'dev_server' => env('VITE_DEV_SERVER', '{$vars['frontend_dev_server']}'),
];
PHP;
    }

    private function templateDesignConfig(array $vars): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

return {$vars['design_config_php']};
PHP;
    }

    private function templatePackageJson(array $vars): string
    {
        $devDependencies = [
            'vite' => '^5.4.14',
        ];

        if ($vars['frontend_tailwind_enabled'] === 'true') {
            $devDependencies['tailwindcss'] = '^3.4.13';
            $devDependencies['postcss'] = '^8.4.47';
            $devDependencies['autoprefixer'] = '^10.4.20';
        }

        $package = [
            'name' => strtolower(str_replace(' ', '-', $vars['project_name'])),
            'private' => true,
            'type' => 'module',
            'scripts' => [
                'dev' => 'vite',
                'build' => 'vite build',
                'preview' => 'vite preview',
            ],
            'devDependencies' => $devDependencies,
        ];

        return json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function templateViteConfig(array $vars): string
    {
        return <<<JS
import { defineConfig } from 'vite';

export default defineConfig({
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
    },
    build: {
        manifest: true,
        outDir: 'public/{$vars['frontend_build_dir']}',
        emptyOutDir: true,
        rollupOptions: {
            input: ['resources/js/app.js'],
        },
    },
});
JS;
    }

    private function templateTailwindConfig(): string
    {
        return <<<'JS'
export default {
    content: [
        './app/**/*.php',
        './resources/**/*.js',
    ],
    theme: {
        extend: {},
    },
    plugins: [],
};
JS;
    }

    private function templatePostCssConfig(): string
    {
        return <<<'JS'
export default {
    plugins: {
        tailwindcss: {},
        autoprefixer: {},
    },
};
JS;
    }

    private function templateViteCss(array $vars): string
    {
        $fontImport = trim((string) $vars['design_font_import_url']);
        $fontImportBlock = $fontImport !== '' ? '@import url("' . $fontImport . '");' . PHP_EOL . PHP_EOL : '';

        return $fontImportBlock . <<<CSS
@tailwind base;
@tailwind components;
@tailwind utilities;

:root {
    --theme-primary: {$vars['design_color_primary']};
    --theme-accent: {$vars['design_color_accent']};
    --theme-background: {$vars['design_color_background']};
    --theme-surface: {$vars['design_color_surface']};
    --theme-surface-alt: {$vars['design_color_surface_alt']};
    --theme-text: {$vars['design_color_text']};
    --theme-muted: {$vars['design_color_muted']};
    --theme-border: {$vars['design_color_border']};
    --theme-radius: {$vars['design_radius']};
    --theme-shadow: {$vars['design_shadow']};
    --theme-background-image: {$vars['design_background_image']};
    --theme-content-width: {$vars['design_content_width']};
    --theme-font-display: {$vars['design_font_display']};
    --theme-font-body: {$vars['design_font_body']};
}

@layer base {
    html {
        color-scheme: light dark;
    }

    body {
        min-height: 100vh;
        background: var(--theme-background-image);
        background-color: var(--theme-background);
        color: var(--theme-text);
        font-family: var(--theme-font-body);
        @apply antialiased;
    }

    h1,
    h2,
    h3,
    h4,
    h5,
    h6 {
        font-family: var(--theme-font-display);
    }

    code {
        color: var(--theme-primary);
        @apply text-[0.92em] font-semibold;
    }
}

@layer components {
    .site-body {
        min-height: 100vh;
        background: var(--theme-background-image);
        background-color: var(--theme-background);
        color: var(--theme-text);
    }

    .site-shell {
        width: min(100%, var(--theme-content-width));
        margin-inline: auto;
        padding-inline: clamp(1rem, 2vw, 2rem);
    }

    .site-shell--contained {
        padding-block: clamp(2rem, 4vw, 4rem);
    }

    .site-shell--full {
        width: 100%;
        max-width: none;
        padding-block: clamp(1rem, 2vw, 2rem);
    }

    .panel-card {
        border-radius: var(--theme-radius);
        border: 1px solid var(--theme-border);
        background: var(--theme-surface);
        box-shadow: var(--theme-shadow);
        backdrop-filter: blur(18px);
    }

    .surface-card {
        border-radius: calc(var(--theme-radius) - 8px);
        border: 1px solid var(--theme-border);
        background: var(--theme-surface-alt);
    }

    .feature-card--highlight {
        border-color: var(--theme-primary);
        box-shadow: inset 0 0 0 1px var(--theme-primary);
    }

    .eyebrow {
        color: var(--theme-accent);
        @apply mb-2 text-xs font-semibold uppercase tracking-[0.3em];
    }

    .display-title {
        color: var(--theme-text);
        @apply font-black tracking-tight;
    }

    .section-title {
        color: var(--theme-text);
        @apply text-xl font-bold;
    }

    .lead-copy {
        color: var(--theme-muted);
        @apply text-lg leading-8;
    }

    .muted-copy {
        color: var(--theme-muted);
        @apply text-sm leading-7;
    }

    .meta-copy {
        color: var(--theme-muted);
        @apply text-sm;
    }

    .button-primary {
        background: var(--theme-primary);
        color: #ffffff;
        @apply inline-flex items-center justify-center rounded-full px-4 py-2 text-sm font-semibold transition;
    }

    .button-primary:hover {
        filter: brightness(1.06);
        transform: translateY(-1px);
    }

    .button-secondary {
        border: 1px solid var(--theme-border);
        background: var(--theme-surface-alt);
        color: var(--theme-text);
        @apply inline-flex items-center justify-center rounded-2xl px-4 py-3 text-sm font-semibold transition;
    }

    .button-secondary:hover {
        filter: brightness(1.04);
    }

    .field-label {
        color: var(--theme-muted);
        @apply text-sm font-semibold uppercase tracking-[0.2em];
    }

    .field-input {
        border: 1px solid var(--theme-border);
        background: color-mix(in srgb, var(--theme-surface-alt) 82%, transparent);
        color: var(--theme-text);
        @apply w-full rounded-2xl px-4 py-3 outline-none transition;
    }

    .field-input:focus {
        border-color: var(--theme-primary);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--theme-primary) 18%, transparent);
    }

    .divider-copy {
        color: var(--theme-muted);
        @apply flex items-center gap-3 text-xs uppercase tracking-[0.25em];
    }

    .divider-copy::before,
    .divider-copy::after {
        content: '';
        flex: 1 1 0%;
        height: 1px;
        background: var(--theme-border);
    }

    .notice {
        @apply rounded-2xl px-4 py-3 text-sm;
    }

    .notice--error {
        border: 1px solid rgba(244, 63, 94, 0.28);
        background: rgba(244, 63, 94, 0.1);
        color: #ffe4e6;
    }

    .notice--success {
        border: 1px solid rgba(16, 185, 129, 0.28);
        background: rgba(16, 185, 129, 0.1);
        color: #d1fae5;
    }

    .site-nav {
        border: 1px solid var(--theme-border);
        background: var(--theme-surface);
        box-shadow: var(--theme-shadow);
        @apply flex flex-col gap-4 rounded-[calc(var(--theme-radius)-6px)] p-5 md:flex-row md:items-center md:justify-between;
    }

    .site-link {
        color: var(--theme-muted);
        @apply transition;
    }

    .site-link:hover {
        color: var(--theme-text);
    }
}
CSS;
    }

    private function templateViteJs(): string
    {
        return <<<'JS'
import '../css/app.css';

document.documentElement.classList.add('js-ready');
JS;
    }
}
