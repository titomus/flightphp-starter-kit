<?php

declare(strict_types=1);

final class InstallerOptions
{
    public readonly bool $force;

    public readonly bool $dryRun;

    public readonly bool $skipComposer;

    public readonly bool $keepInstaller;

    public readonly bool $showHelp;

    public readonly ?bool $withBackoffice;

    private function __construct(array $options)
    {
        $this->force = $options['force'];
        $this->dryRun = $options['dry-run'];
        $this->skipComposer = $options['no-composer'];
        $this->keepInstaller = $options['keep-installer'];
        $this->showHelp = $options['help'];
        $this->withBackoffice = $options['with-backoffice'];
    }

    public static function fromArgv(array $argv): self
    {
        $options = [
            'force' => false,
            'dry-run' => false,
            'no-composer' => false,
            'with-backoffice' => null,
            'keep-installer' => false,
            'help' => false,
        ];

        foreach (array_slice($argv, 1) as $argument) {
            switch ($argument) {
                case '--force':
                    $options['force'] = true;
                    break;
                case '--dry-run':
                    $options['dry-run'] = true;
                    break;
                case '--no-composer':
                    $options['no-composer'] = true;
                    break;
                case '--with-backoffice':
                    $options['with-backoffice'] = true;
                    break;
                case '--without-backoffice':
                    $options['with-backoffice'] = false;
                    break;
                case '--keep-installer':
                    $options['keep-installer'] = true;
                    break;
                case '--help':
                case '-h':
                    $options['help'] = true;
                    break;
            }
        }

        return new self($options);
    }

    public function helpText(): string
    {
        return <<<'TEXT'
Usage:
  php install-flight-starter.php [--force] [--dry-run] [--no-composer] [--with-backoffice|--without-backoffice] [--keep-installer]

Options:
  --force                Overwrite existing files when content differs
  --dry-run              Print planned operations without writing files
  --no-composer          Skip composer install
  --with-backoffice      Enable admin starter generation
  --without-backoffice   Disable admin starter generation
  --keep-installer       Do not auto-remove installer files after success
  -h, --help             Show this help message
TEXT;
    }
}
