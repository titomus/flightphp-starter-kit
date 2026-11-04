<?php
declare(strict_types=1);

namespace {{namespace}}\Controllers\Admin;

use {{namespace}}\Controllers\BaseController;
use {{namespace}}\Services\Database;
use {{namespace}}\Services\RuntimeSettings;

final class SettingsController extends BaseController
{
    public function index(): void
    {
        try {
            echo $this->view('admin.settings', [
                'title' => 'Modules & réglages',
                'adminPath' => config('backoffice.path', '/admin'),
                'saved' => RuntimeSettings::read(),
                'notice' => flash_get('settings.message'),
                'states' => [
                    'members' => (bool) config('auth.guards.frontend.enabled', false),
                    'google' => (bool) config('social_auth.providers.google.enabled', false),
                    'github' => (bool) config('social_auth.providers.github.enabled', false),
                    'stripe' => (bool) config('billing.providers.stripe.enabled', false),
                    'mcp' => class_exists(\{{namespace}}\Mcp\DynamicOAuth::class),
                ],
            ], 'admin.layouts.main');
        } catch (\Throwable $e) {
            error_log('Settings unavailable: ' . $e->getMessage());
            http_response_code(503);
            echo 'Configuration privee indisponible.';
        }
    }

    public function update(): void
    {
        try {
            $owner = auth('admin')->user();
            $id = (int) ($owner['id'] ?? 0);
            $password = $_POST['owner_password'] ?? null;
            if ($id < 1 || !is_string($password) || $password === '') {
                throw new \RuntimeException('Mot de passe propriétaire nécessaire.');
            }
            $lookup = Database::connection()->prepare('SELECT password FROM admin_users WHERE id=:id');
            $lookup->execute(['id'=>$id]);
            $hash = $lookup->fetchColumn();
            if (!is_string($hash) || !password_verify($password,$hash)) {
                throw new \RuntimeException('Mot de passe propriétaire incorrect.');
            }
            RuntimeSettings::update($_POST);
            flash_set('settings.message', 'Réglage sauvegardé. Il s’applique aux nouvelles requêtes.');
        } catch (\Throwable $e) {
            // Only the validation error is logged, never submitted API secrets.
            error_log('Settings change rejected: ' . $e->getMessage());
            flash_set('settings.message','Réglage refusé : '.substr($e->getMessage(),0,170));
        }
        redirect((string) config('backoffice.path','/admin').'/settings');
    }
}
