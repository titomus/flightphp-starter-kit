<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Mcp\DynamicOAuth;
use InvalidArgumentException;

/**
 * ChatGPT registers a public OAuth client automatically (DCR + PKCE).
 * The admin authenticates normally and explicitly consents to each scope.
 */
final class McpOAuthController
{
    public function metadata(): void
    {
        $base = DynamicOAuth::baseUrl();
        $this->json([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [
                DynamicOAuth::READ, DynamicOAuth::WRITE, DynamicOAuth::EVOLVE,
            ],
        ]);
    }

    public function protectedResource(): void
    {
        $this->json([
            'resource' => DynamicOAuth::resource(),
            'authorization_servers' => [DynamicOAuth::baseUrl()],
            'scopes_supported' => [
                DynamicOAuth::READ, DynamicOAuth::WRITE, DynamicOAuth::EVOLVE,
            ],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function register(): void
    {
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
            return;
        }

        $body = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($body) || strlen($body) > 16384) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
            return;
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                throw new InvalidArgumentException('invalid_client_metadata');
            }
            $this->json((new DynamicOAuth())->register($payload), 201);
        } catch (InvalidArgumentException|\JsonException) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
        } catch (\Throwable $error) {
            error_log('MCP client registration failure: ' . $error->getMessage());
            $this->json(['error' => 'server_error'], 503);
        }
    }

    public function authorize(): void
    {
        header('Cache-Control: no-store');
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\' https://chatgpt.com; base-uri \'none\'; frame-ancestors \'none\'');

        $request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
        $oauth = new DynamicOAuth();

        try {
            $validated = $oauth->verifyAuthorizationRequest($request);
        } catch (\Throwable $error) {
            error_log('MCP authorization request validation failed: ' . $error->getMessage());
            http_response_code(503);
            echo 'OAuth service is temporarily unavailable.';
            return;
        }

        if ($validated === null) {
            http_response_code(400);
            echo 'Invalid OAuth client, callback, scopes or PKCE challenge.';
            return;
        }

        $admin = auth('admin')->user();
        if (!is_array($admin) || (int) ($admin['id'] ?? 0) < 1) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Connexion requise</title>'
                . '<p>Connectez-vous à l’administration dans un autre onglet puis rechargez cette page.</p>'
                . '<a href="/admin/login" target="_blank" rel="noopener">Se connecter</a>';
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $submittedCsrf = (string) ($_POST['_csrf'] ?? '');
            if ($submittedCsrf === '' || !hash_equals(csrf_token(), $submittedCsrf)) {
                http_response_code(403);
                echo 'Invalid consent CSRF token.';
                return;
            }

            try {
                if (($_POST['decision'] ?? '') === 'approve') {
                    $code = $oauth->authorize((int) $admin['id'], $validated);
                    $parameters = ['code' => $code];
                } else {
                    $parameters = ['error' => 'access_denied'];
                }
            } catch (\Throwable $error) {
                error_log('MCP consent failure: ' . $error->getMessage());
                http_response_code(503);
                echo 'Authorization could not be completed.';
                return;
            }

            $parameters['state'] = $validated['state'];
            header('Location: ' . $validated['redirect_uri'] . '?'
                . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), true, 303);
            // Preserve the explicit 303 and avoid FlightPHP rewriting it.
            exit;
        }

        $fields = '';
        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge',
            'code_challenge_method', 'scope', 'state', 'resource'] as $name) {
            if (isset($request[$name]) && is_string($request[$name])) {
                $fields .= '<input type="hidden" name="'
                    . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                    . '" value="' . htmlspecialchars($request[$name], ENT_QUOTES, 'UTF-8') . '">';
            }
        }

        $scopes = explode(' ', $validated['scope']);
        $rights = [];
        if (in_array(DynamicOAuth::READ, $scopes, true)) {
            $rights[] = 'consulter le catalogue et prévisualiser les imports';
        }
        if (in_array(DynamicOAuth::WRITE, $scopes, true)) {
            $rights[] = 'importer ou mettre à jour les références';
        }
        if (in_array(DynamicOAuth::EVOLVE, $scopes, true)) {
            $rights[] = 'envoyer des demandes d’évolution de l’application';
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width"><title>Autorisation ChatGPT</title>'
            . '<main style="max-width:560px;padding:24px;margin:8vh auto;font:16px system-ui,sans-serif;line-height:1.6">'
            . '<h1>Connecter Atelier Catalogue à ChatGPT</h1><p>Vous autorisez ChatGPT à :</p><ul><li>'
            . implode('</li><li>', array_map(static fn (string $right): string =>
                htmlspecialchars($right, ENT_QUOTES, 'UTF-8'), $rights))
            . '</li></ul><p>La modification du code du serveur et le déploiement '
            . 'ne sont pas autorisés par cette connexion.</p>'
            . '<form method="post" action="/oauth/authorize">'
            . $fields . '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">'
            . '<button name="decision" value="approve" style="padding:12px 20px;margin-right:10px">Autoriser</button>'
            . '<button name="decision" value="deny" style="padding:12px 20px">Refuser</button>'
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

        // Dynamic registration produces a public client, authenticated with PKCE.
        if (isset($_POST['client_secret'])
            || str_starts_with((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Basic ')) {
            $this->json(['error' => 'invalid_client'], 401);
            return;
        }

        try {
            $oauth = new DynamicOAuth();
            $result = match ($_POST['grant_type'] ?? '') {
                'authorization_code' => $oauth->exchange($_POST),
                'refresh_token' => $oauth->refresh($_POST),
                default => throw new InvalidArgumentException('unsupported_grant_type'),
            };
            $this->json($result);
        } catch (InvalidArgumentException $error) {
            $message = $error->getMessage();
            $this->json([
                'error' => in_array($message, ['invalid_grant', 'unsupported_grant_type'], true)
                    ? $message : 'invalid_grant',
            ], 400);
        } catch (\Throwable $error) {
            error_log('MCP OAuth token failure: ' . $error->getMessage());
            $this->json(['error' => 'server_error'], 503);
        }
    }

    private function json(array $payload, int $code = 200): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // FlightPHP can normalize the response status after a route callback
        // returns. OAuth status codes (201/400/401/503) must reach clients
        // unchanged, so finish the HTTP response here.
        exit;
    }
}
