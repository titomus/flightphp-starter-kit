<?php

declare(strict_types=1);

/**
 * Web installer recipe => existing Flight Starter Kit generator configuration.
 * Pure and side-effect-free. Never accepts arbitrary PHP code, paths or URLs.
 */
final class WebInstallRecipe
{
    public const TYPES = [
        'saas' => ['label' => 'SaaS', 'design' => 'saas', 'members' => true, 'mcp' => true],
        'outil_metier' => ['label' => 'Outil métier', 'design' => 'outil_metier', 'members' => true, 'mcp' => true],
        'blog' => ['label' => 'Blog / média', 'design' => 'blog', 'members' => false, 'mcp' => false],
        'site_vitrine' => ['label' => 'Site vitrine', 'design' => 'blog', 'members' => false, 'mcp' => false],
    ];

    public static function defaults(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Type de projet inconnu.');
        }
        return self::TYPES[$type];
    }

    public static function validate(array $data): array
    {
        $type = (string) ($data['type'] ?? 'saas');
        $defaults = self::defaults($type);
        $name = trim((string) ($data['project_name'] ?? ''));
        $url = trim((string) ($data['base_url'] ?? ''));
        $email = trim((string) ($data['admin_email'] ?? ''));
        $password = (string) ($data['admin_password'] ?? '');
        $passwordConfirm = (string) ($data['password_confirm'] ?? '');
        $members = self::flag($data, 'members', $defaults['members']);
        $mcp = self::flag($data, 'mcp', $defaults['mcp']);
        $stripe = self::flag($data, 'stripe', false);

        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[<>{}\x00-\x1f]/u', $name)) {
            throw new InvalidArgumentException('Nom du projet invalide (1 à 80 caractères).');
        }
        if (!self::httpsUrl($url)) {
            throw new InvalidArgumentException('Adresse HTTPS valide obligatoire, sans chemin ni paramètres.');
        }
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse email du propriétaire invalide.');
        }
        if (strlen($password) < 16 || strlen($password) > 256 || $password !== $passwordConfirm) {
            throw new InvalidArgumentException('Mot de passe propriétaire de 16 caractères minimum et confirmation identique.');
        }
        if (in_array(strtolower($password), ['password12345678','admin123456789012','changeme123456789'], true)) {
            throw new InvalidArgumentException('Choisissez un mot de passe unique.');
        }
        if ($stripe && !$members) {
            throw new InvalidArgumentException('Stripe nécessite les comptes utilisateurs.');
        }
        return [
            'type' => $type,
            'name' => $name,
            'url' => rtrim($url, '/'),
            'email' => $email,
            'password' => $password,
            'members' => $members,
            'mcp' => $mcp,
            'stripe' => $stripe,
            'preset' => $defaults['design'],
        ];
    }

    public static function apply(array $config, array $validated, string $target): array
    {
        if (!is_dir($target) || !is_dir($target . '/public')) {
            throw new RuntimeException('Dossier de projet invalide.');
        }
        $config['project_name'] = $validated['name'];
        $config['namespace'] = 'App';
        $config['target_dir'] = $target;
        $config['base_url'] = $validated['url'];
        $config['timezone'] = 'Europe/Paris';
        $config['backoffice']['enabled'] = true;
        $config['backoffice']['path'] = '/admin';
        $config['backoffice']['default_email'] = $validated['email'];
        $config['backoffice']['default_password'] = $validated['password'];
        $config['auth']['enabled'] = true;
        $config['frontend_auth']['enabled'] = $validated['members'];
        $config['frontend_auth']['registration_enabled'] = $validated['members'];
        $config['frontend_auth']['home_path'] = '/account';
        $config['mcp']['enabled'] = $validated['mcp'];
        $config['stripe']['enabled'] = $validated['stripe'];
        $config['design']['preset'] = $validated['preset'];
        $config['design']['overrides'] = [];
        $config['database']['driver'] = 'sqlite';
        $config['database']['database'] = 'app/storage/database.sqlite';
        $config['composer']['auto_install'] = false;
        $config['npm']['auto_build'] = false;
        $config['installer']['cleanup_on_success'] = false;
        $config['features'] = [
            'backoffice' => true,
            'frontend' => true,
        ];
        $config['frontend']['build_dir'] = 'build';
        $config['frontend']['vite'] = true;
        $config['frontend']['tailwind'] = true;
        $config['frontend']['htmx'] = true;
        $config['frontend']['alpine'] = true;
        return $config;
    }

    private static function flag(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        return in_array($data[$key], [true, 1, '1', 'yes', 'on'], true);
    }

    private static function httpsUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        return ($parts['path'] ?? '') === '' || $parts['path'] === '/';
    }
}
