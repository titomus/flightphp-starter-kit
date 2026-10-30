<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Mcp\OAuthStore;
use InvalidArgumentException;

final class McpOAuthController
{
    public function metadata(): void
    {
        $base = OAuthStore::baseUrl();
        $this->json([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [OAuthStore::SCOPE],
        ]);
    }

    public function protectedResource(): void
    {
        $base = OAuthStore::baseUrl();
        $this->json([
            'resource' => $base . '/mcp.php',
            'authorization_servers' => [$base],
            'scopes_supported' => [OAuthStore::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorize(): void
    {
        header('Cache-Control: no-store');
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

        $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        if (!OAuthStore::isValidRequest($request)) {
            http_response_code(400);
            echo 'Authorization request invalid or OAuth not configured.';
            return;
        }

        $admin = auth('admin')->user();
        if (!is_array($admin) || (int) ($admin['id'] ?? 0) < 1) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Connexion requise</title>'
                . '<p>Connectez-vous d’abord à l’administration dans un autre onglet, '
                . 'puis rechargez cette page.</p>'
                . '<a href="/admin/login" target="_blank" rel="noopener">Connexion administrateur</a>';
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrf = (string) ($_POST['_csrf'] ?? '');
            if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
                http_response_code(403);
                echo 'Invalid consent CSRF token.';
                return;
            }

            $target = OAuthStore::redirectUri();
            if (($_POST['decision'] ?? '') !== 'approve') {
                $parameters = ['error' => 'access_denied'];
            } else {
                $code = (new OAuthStore())->authorize(
                    (int) $admin['id'],
                    (string) $request['client_id'],
                    (string) $request['redirect_uri'],
                    (string) $request['code_challenge']
                );
                $parameters = ['code' => $code];
            }
            if (isset($request['state']) && $request['state'] !== '') {
                $parameters['state'] = $request['state'];
            }
            header('Location: ' . $target . (str_contains($target, '?') ? '&' : '?')
                . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), true, 303);
            return;
        }

        $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
        $fields = '';
        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'scope', 'state'] as $field) {
            if (isset($request[$field]) && is_string($request[$field])) {
                $key = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
                $value = htmlspecialchars($request[$field], ENT_QUOTES, 'UTF-8');
                $fields .= '<input type="hidden" name="' . $key . '" value="' . $value . '">';
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>Autoriser l’accès au catalogue</title>'
            . '<main style="max-width:540px;margin:8vh auto;font:16px system-ui,sans-serif;line-height:1.6;padding:24px">'
            . '<h1>Connecter le catalogue à ChatGPT</h1>'
            . '<p>Autorisez-vous l’accès <strong>en lecture seule</strong> aux références du catalogue '
            . 'et à la prévisualisation des imports ?</p>'
            . '<p>Cette autorisation ne permet pas de créer, modifier ou supprimer des produits.</p>'
            . '<form method="post" action="/oauth/authorize">'
            . $fields . '<input type="hidden" name="_csrf" value="' . $token . '">'
            . '<button name="decision" value="approve" style="padding:12px 20px;margin-right:12px;cursor:pointer">Autoriser</button>'
            . '<button name="decision" value="deny" style="padding:12px 20px;cursor:pointer">Refuser</button>'
            . '</form></main></html>';
    }

    public function token(): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') === false) {
            $this->json(['error' => 'invalid_request'], 400);
            return;
        }

        $clientId = (string) ($_POST['client_id'] ?? '');
        $clientSecret = (string) ($_POST['client_secret'] ?? '');
        $basic = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with($basic, 'Basic ')) {
            $decoded = base64_decode(substr($basic, 6), true);
            if (!is_string($decoded) || !str_contains($decoded, ':')) {
                $this->json(['error' => 'invalid_client'], 401);
                return;
            }
            [$clientId, $clientSecret] = explode(':', $decoded, 2);
            $clientId = urldecode($clientId);
            $clientSecret = urldecode($clientSecret);
        }

        if (!OAuthStore::authenticateClient($clientId, $clientSecret)) {
            header('WWW-Authenticate: Basic realm="MCP OAuth"');
            $this->json(['error' => 'invalid_client'], 401);
            return;
        }

        try {
            $store = new OAuthStore();
            $type = $_POST['grant_type'] ?? '';
            $result = match ($type) {
                'authorization_code' => $store->exchange(
                    (string) ($_POST['code'] ?? ''),
                    $clientId,
                    (string) ($_POST['redirect_uri'] ?? ''),
                    (string) ($_POST['code_verifier'] ?? '')
                ),
                'refresh_token' => $store->refresh((string) ($_POST['refresh_token'] ?? ''), $clientId),
                default => throw new InvalidArgumentException('unsupported_grant_type'),
            };
            $this->json($result);
        } catch (InvalidArgumentException $exception) {
            $error = in_array($exception->getMessage(), ['invalid_grant', 'unsupported_grant_type'], true)
                ? $exception->getMessage() : 'invalid_grant';
            $this->json(['error' => $error], 400);
        } catch (\Throwable $exception) {
            error_log('OAuth token failure: ' . $exception->getMessage());
            $this->json(['error' => 'server_error'], 500);
        }
    }

    private function json(array $data, int $status = 200): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
