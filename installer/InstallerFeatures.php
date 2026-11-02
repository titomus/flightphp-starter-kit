<?php

declare(strict_types=1);

final class InstallerFeatures
{
    public function __construct(
        public readonly bool $backoffice,
        public readonly bool $frontend,
        public readonly bool $mcp,
    ) {
    }

    public static function fromConfig(array $config, InstallerOptions $options): self
    {
        $configured = $config['features'] ?? [];

        $backoffice = $options->withBackoffice
            ?? (bool) ($configured['backoffice'] ?? $config['backoffice']['enabled'] ?? false);

        $frontend = (bool) ($configured['frontend']
            ?? (($config['frontend']['vite'] ?? false) || ($config['frontend']['tailwind'] ?? false)));

        return new self($backoffice, $frontend, (bool) ($config['mcp']['enabled'] ?? false));
    }
}
