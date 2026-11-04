<?php
declare(strict_types=1);

namespace {{namespace}}\Controllers\Admin;

use {{namespace}}\Controllers\BaseController;
use {{namespace}}\Mcp\CodeGraftFactory;
use {{namespace}}\Services\Database;
use CodeGraft\Admin as CodeGraftAdmin;

final class McpSettingsController extends BaseController
{
    private function approvalFile(): string
    {
        return dirname(__DIR__, 3) . '/app/storage/atelier/deployment-approval.json';
    }

    private function approved(): bool
    {
        $path = $this->approvalFile();
        if (!is_file($path) || is_link($path)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) && ($data['approved'] ?? false) === true;
    }

    private function requireOwnerPassword(): int
    {
        $user = auth('admin')->user();
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($id < 1) {
            throw new \RuntimeException('Session propriétaire absente.');
        }
        $password = $_POST['owner_password'] ?? null;
        if (!is_string($password) || $password === '') {
            throw new \RuntimeException('Mot de passe administrateur requis.');
        }
        $query = Database::connection()->prepare('SELECT password FROM admin_users WHERE id = :id');
        $query->execute(['id' => $id]);
        $hash = $query->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) {
            throw new \RuntimeException('Authentification propriétaire refusée.');
        }
        return $id;
    }

    private function saveApproval(bool $approved): void
    {
        $path = $this->approvalFile();
        $dir = dirname($path);
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) || is_link($path)) {
            throw new \RuntimeException('Dossier des autorisations indisponible.');
        }
        $json = json_encode(['approved' => $approved, 'updated_at' => gmdate('c')], JSON_THROW_ON_ERROR);
        $tmp = tempnam($dir, '.approval-');
        if (!is_string($tmp)) {
            throw new \RuntimeException('Impossible de préparer le réglage.');
        }
        try {
            chmod($tmp, 0600);
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !rename($tmp, $path)) {
                throw new \RuntimeException('Impossible de sauvegarder le réglage.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function index(): void
    {
        try {
            $vault = CodeGraftFactory::vault();
            $history = CodeGraftFactory::workspace()->history(25)['releases'] ?? [];
            $admin = auth('admin')->user();
            $id = (int) ($admin['id'] ?? 0);
            $query = Database::connection()->prepare(
                'SELECT c.client_id, c.redirect_uri, c.created_at, ' .
                'COUNT(t.id) AS active_tokens FROM mcp_dcr_clients c ' .
                'LEFT JOIN mcp_dcr_tokens t ON t.client_id = c.client_id ' .
                'AND t.admin_id = :owner AND t.revoked_at IS NULL AND t.refresh_expires_at > :now ' .
                'GROUP BY c.client_id, c.redirect_uri, c.created_at ' .
                'ORDER BY c.created_at DESC LIMIT 30'
            );
            $query->execute(['owner' => $id, 'now' => time()]);
            echo $this->view('admin.mcp-settings', [
                'title' => 'CodeGraft · IA & MCP',
                'adminPath' => config('backoffice.path', '/admin'),
                'mcpUrl' => rtrim((string) env('APP_URL', ''), '/') . '/mcp',
                'policy' => $vault->policy(),
                'operations' => \CodeGraft\Policy::OPERATIONS,
                'serverEnabled' => filter_var((string) env('ATELIER_CODE_UPDATES_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
                'approved' => $this->approved(),
                'history' => $history,
                'audit' => CodeGraftFactory::recentAudit(50),
                'clients' => $query->fetchAll(\PDO::FETCH_ASSOC),
                'notice' => flash_get('mcp.message'),
            ], 'admin.layouts.main');
        } catch (\Throwable $e) {
            error_log('CodeGraft admin unavailable: ' . $e->getMessage());
            http_response_code(503);
            echo 'Administration CodeGraft temporairement indisponible. Vérifier le coffre privé et les migrations OAuth.';
        }
    }

    public function update(): void
    {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $vault = CodeGraftFactory::vault();
            if ($action === 'save_policy') {
                $result = CodeGraftAdmin::handle($vault, $_POST);
                $message = (string) ($result['message'] ?? 'Droits enregistrés.');
            } elseif ($action === 'toggle_deploy') {
                $this->requireOwnerPassword();
                $enable = ($_POST['deployment_approved'] ?? '') === '1';
                if ($enable && ($_POST['confirmation'] ?? '') !== 'AUTORISER') {
                    throw new \RuntimeException('Tapez AUTORISER pour activer les déploiements.');
                }
                if ($enable && !filter_var((string) env('ATELIER_CODE_UPDATES_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
                    throw new \RuntimeException('Le verrou serveur est désactivé dans .env.');
                }
                $this->saveApproval($enable);
                $message = $enable ? 'Déploiement autorisé pour les clients disposant des droits requis.' : 'Déploiements bloqués.';
            } elseif ($action === 'revoke_oauth') {
                $owner = $this->requireOwnerPassword();
                $clientId = (string) ($_POST['client_id'] ?? '');
                if (!preg_match('/^mcp_[a-zA-Z0-9_-]{10,150}$/D', $clientId)) {
                    throw new \RuntimeException('Client OAuth invalide.');
                }
                $query = Database::connection()->prepare(
                    'UPDATE mcp_dcr_tokens SET revoked_at = :now ' .
                    'WHERE client_id = :client AND admin_id = :owner AND revoked_at IS NULL'
                );
                $query->execute(['now' => time(), 'client' => $clientId, 'owner' => $owner]);
                $message = 'Jetons OAuth de ce client révoqués (' . $query->rowCount() . ').';
            } elseif ($action === 'rollback') {
                $this->requireOwnerPassword();
                if (($_POST['confirmation'] ?? '') !== 'RESTAURER' || !CodeGraftFactory::deploymentAllowed()) {
                    throw new \RuntimeException('Restauration refusée : confirmation et autorisations nécessaires.');
                }
                $id = (string) ($_POST['release_id'] ?? '');
                if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
                    throw new \RuntimeException('Identifiant de version invalide.');
                }
                $result = CodeGraftFactory::workspace()->rollback($id, true);
                $message = 'Version restaurée : ' . (string) ($result['id'] ?? $id);
            } else {
                throw new \RuntimeException('Opération inconnue.');
            }
            flash_set('mcp.message', $message);
        } catch (\Throwable $e) {
            error_log('CodeGraft admin action refused: ' . $e->getMessage());
            flash_set('mcp.message', 'Action non réalisée : ' . substr($e->getMessage(), 0, 180));
        }
        redirect((string) config('backoffice.path', '/admin') . '/mcp');
    }
}
