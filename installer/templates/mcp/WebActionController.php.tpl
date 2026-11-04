<?php
declare(strict_types=1);

namespace {{namespace}}\Controllers\Business;

use {{namespace}}\Actions\ActionContext;
use {{namespace}}\Actions\BusinessActionRegistry;

/** Authenticated same-origin JSON endpoint for every registered web action. */
final class WebActionController
{
    public function run(string $name): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->respond(405, 'Method not allowed');
            return;
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($type, 'application/json')) {
            $this->respond(415, 'JSON required');
            return;
        }
        // Cookie-bearing web requests require a real session and CSRF token.
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals(csrf_token(), $token)) {
            $this->respond(403, 'CSRF token required');
            return;
        }
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $base = (string) config('app.url', '');
        if ($origin !== '' && !hash_equals(rtrim($base, '/'), rtrim($origin, '/'))) {
            $this->respond(403, 'Origin denied');
            return;
        }
        $body = file_get_contents('php://input', false, null, 0, 65537);
        if (!is_string($body) || strlen($body) > 65536) {
            $this->respond(413, 'Payload too large');
            return;
        }
        try {
            $args = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($args) || ($args !== [] && array_is_list($args))) {
                throw new \InvalidArgumentException('A JSON object is required.');
            }
            try {
                $actor = ActionContext::fromWebSession();
            } catch (\RuntimeException $e) {
                $this->respond(401, 'Authentication required');
                return;
            }
            $result = BusinessActionRegistry::fromApplication()->invoke($name, $args, $actor);
            http_response_code(200);
            echo json_encode(['data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\DomainException $e) {
            $this->respond(403, 'Action forbidden');
        } catch (\InvalidArgumentException | \JsonException $e) {
            $this->respond(400, 'Invalid action or input');
        } catch (\Throwable $e) {
            error_log('Business action rejected: ' . $e->getMessage());
            $this->respond(500, 'Business action unavailable');
        }
    }

    private function respond(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    }
}
