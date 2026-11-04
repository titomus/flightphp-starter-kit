<?php
declare(strict_types=1);

namespace CodeGraft;

use RuntimeException;

final class Vault
{
    public readonly string $root;
    public readonly string $directory;
    public readonly string $adapter;

    public function __construct(string $root, string $directory, string $adapter = 'generic')
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('Project root does not exist.');
        }
        $this->root = rtrim($resolved, '/');
        $this->adapter = $adapter;
        if (!in_array($adapter, ['generic', 'flight', 'wordpress'], true)) {
            throw new RuntimeException('Unsupported integration.');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Vault may not be a symlink.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Vault cannot be created.');
        }
        $actual = realpath($directory);
        if ($actual === false || Policy::under($actual, $this->root)) {
            throw new RuntimeException('Vault must be OUTSIDE the public project root.');
        }
        $this->directory = $actual;
        foreach (['drafts', 'releases'] as $name) {
            $folder = $actual . '/' . $name;
            if (is_link($folder)) {
                throw new RuntimeException('Unsafe vault folder.');
            }
            if (!is_dir($folder) && !mkdir($folder, 0700) && !is_dir($folder)) {
                throw new RuntimeException('Cannot create private vault folder.');
            }
        }
        if (!is_file($this->directory . '/policy.json')) {
            $this->savePolicy(Policy::defaults($adapter));
        }
    }

    public function policy(): array
    {
        return $this->json('policy.json');
    }

    public function savePolicy(array $input): array
    {
        $policy = Policy::normalize($input, $this->adapter);
        $policy['updated_at'] = gmdate('c');
        $this->put('policy.json', $policy);
        return $policy;
    }

    /** Token shown once to site operator; only its HMAC-compatible SHA256 is stored. */
    public function rotateToken(): string
    {
        $token = 'cg_' . bin2hex(random_bytes(32));
        $this->put('token.json', ['sha256' => hash('sha256', $token), 'created_at' => gmdate('c')]);
        return $token;
    }

    public function revokeToken(): void
    {
        $this->put('token.json', ['sha256' => null, 'revoked_at' => gmdate('c')]);
    }

    public function authenticate(?string $authorization): bool
    {
        if (!is_string($authorization) || !preg_match('/^Bearer (cg_[a-f0-9]{64})$/Di', $authorization, $m)) {
            return false;
        }
        $record = $this->json('token.json', []);
        $hash = $record['sha256'] ?? null;
        return is_string($hash) && strlen($hash) === 64
            && hash_equals($hash, hash('sha256', $m[1]));
    }

    public function json(string $file, array $default = []): array
    {
        $name = $this->safeName($file);
        if (!is_file($name)) {
            return $default;
        }
        if (is_link($name) || filesize($name) > 4000000) {
            throw new RuntimeException('Unsafe state file.');
        }
        $bytes = file_get_contents($name);
        if (!is_string($bytes)) throw new RuntimeException('Cannot read vault.');
        $json = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($json)) throw new RuntimeException('Invalid vault JSON.');
        return $json;
    }

    public function put(string $file, array $data): void
    {
        $name = $this->safeName($file);
        $folder = dirname($name);
        $tmp = tempnam($folder, '.cg-');
        if ($tmp === false) throw new RuntimeException('Cannot stage data.');
        try {
            $body = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body)) {
                throw new RuntimeException('State write failed.');
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $name)) throw new RuntimeException('Cannot activate data.');
        } finally {
            if (file_exists($tmp)) unlink($tmp);
        }
    }

    public function locked(callable $callback): mixed
    {
        $name = $this->directory . '/.write.lock';
        if (is_link($name)) throw new RuntimeException('Unsafe vault lock.');
        $fp = fopen($name, 'c');
        if ($fp === false) throw new RuntimeException('Cannot lock vault.');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('Vault busy.');
            return $callback();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function safeName(string $relative): string
    {
        if (!preg_match('~^(?:(?:drafts|releases)/[a-f0-9]{32}\.json|(?:policy|token|admin|endpoint)\.json)$~D', $relative)) {
            throw new RuntimeException('State path not allowed.');
        }
        $path = $this->directory . '/' . $relative;
        if (is_link($path)) throw new RuntimeException('Symlink state denied.');
        return $path;
    }
}
