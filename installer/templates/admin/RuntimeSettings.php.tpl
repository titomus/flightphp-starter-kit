<?php
declare(strict_types=1);

namespace {{namespace}}\Services;

/** Persistent, owner-managed overrides. No arbitrary config keys or PHP execution. */
final class RuntimeSettings
{
    private const SECTIONS = ['design', 'seo', 'mail', 'stripe', 'google', 'github', 'members'];

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/app/storage/config/runtime.json';
    }

    public static function read(): array
    {
        $path = self::path();
        if (!file_exists($path)) return [];
        if (is_link($path) || !is_file($path) || filesize($path) > 32768) {
            throw new \RuntimeException('Unsafe private configuration.');
        }
        $data = json_decode((string) file_get_contents($path), true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid private settings.');
        return $data;
    }

    /** Only map explicit keys to the framework configuration. */
    public static function overrides(array $data): array
    {
        $out = [];
        $d = $data['design'] ?? [];
        if (is_array($d)) {
            foreach (['primary', 'accent'] as $key) {
                if (self::hex($d[$key] ?? null)) $out['design']['palette'][$key] = $d[$key];
            }
            if (self::logo($d['logo'] ?? null)) $out['branding']['logo'] = $d['logo'];
        }
        $seo = $data['seo'] ?? [];
        if (is_array($seo)) {
            foreach (['default_description', 'title_separator'] as $key) {
                if (is_string($seo[$key] ?? null)) $out['seo'][$key] = $seo[$key];
            }
        }
        $members = $data['members'] ?? [];
        if (is_array($members) && isset($members['enabled'])) {
            $out['auth']['guards']['frontend']['enabled'] = $members['enabled'] === true;
            $out['auth']['guards']['frontend']['registration_enabled'] =
                ($members['enabled'] === true) && (($members['registration_enabled'] ?? false) === true);
        }
        $mail = $data['mail'] ?? [];
        if (is_array($mail) && isset($mail['default'])) {
            $out['mail']['default'] = $mail['default'] === 'smtp' ? 'smtp' : 'log';
            foreach (['from_address' => ['from','address'], 'from_name' => ['from','name']] as $key => $dest) {
                if (is_string($mail[$key] ?? null)) $out['mail'][$dest[0]][$dest[1]] = $mail[$key];
            }
            foreach (['host','port','username','password','encryption'] as $key) {
                if (isset($mail[$key])) $out['mail']['mailers']['smtp'][$key] = $mail[$key];
            }
        }
        foreach (['stripe','google','github'] as $provider) {
            $p = $data[$provider] ?? null;
            if (!is_array($p)) continue;
            $keys = $provider === 'stripe'
                ? ['enabled','publishable_key','secret_key','webhook_secret']
                : ['enabled','client_id','client_secret'];
            $target = $provider === 'stripe' ? ['billing','providers','stripe'] : ['social_auth','providers',$provider];
            foreach ($keys as $key) {
                if (!array_key_exists($key, $p)) continue;
                if ($provider === 'stripe') $out[$target[0]][$target[1]][$target[2]][$key] = $p[$key];
                else $out['social_auth']['providers'][$provider][$key] = $p[$key];
            }
            if ($provider !== 'stripe') $out['social_auth']['enabled'] = true;
        }
        return $out;
    }

    public static function css(): string
    {
        $primary = config('design.palette.primary', '#2563eb');
        $accent = config('design.palette.accent', '#14b8a6');
        if (!self::hex($primary) || !self::hex($accent)) return '';
        return ':root{--theme-primary:' . $primary . ';--theme-accent:' . $accent . '}';
    }

    public static function update(array $input): void
    {
        $section = $input['section'] ?? '';
        if (!is_string($section) || !in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException('Unknown settings section.');
        }
        $all = self::read();
        $value = $all[$section] ?? [];
        if (!is_array($value)) $value = [];
        $field = static function (array $data, string $key, int $length = 190): string {
            $v = $data[$key] ?? '';
            if (!is_string($v) || strlen($v) > $length || preg_match('/[\r\n\x00-\x1f]/', $v)) {
                throw new \InvalidArgumentException('Invalid value for ' . $key);
            }
            return trim($v);
        };
        $flag = static fn (string $name): bool => ($input[$name] ?? '') === '1';
        if ($section === 'design') {
            $value = ['primary' => $field($input,'primary',7), 'accent' => $field($input,'accent',7),
                'logo' => $field($input,'logo')];
            if (!self::hex($value['primary']) || !self::hex($value['accent']) || !self::logo($value['logo'])) {
                throw new \InvalidArgumentException('Invalid colors or logo path.');
            }
        } elseif ($section === 'seo') {
            $value = ['title_separator' => $field($input,'title_separator',10),
                'default_description' => $field($input,'default_description',300)];
        } elseif ($section === 'members') {
            $enabled = $flag('enabled');
            if (!$enabled && (($all['stripe']['enabled'] ?? config('billing.providers.stripe.enabled', false)) === true)) {
                throw new \InvalidArgumentException('Disable Stripe before member accounts.');
            }
            $value = ['enabled' => $enabled, 'registration_enabled' => $enabled && $flag('registration_enabled')];
        } elseif ($section === 'mail') {
            $mode = $field($input,'default',8);
            $port = $field($input,'port',5);
            $host = $field($input,'host');
            $from = $field($input,'from_address');
            $username = $field($input,'username');
            $secret = $field($input,'password',500) ?: ($value['password'] ?? config('mail.mailers.smtp.password',''));
            if (!in_array($mode,['log','smtp'],true) || !ctype_digit($port) || (int)$port<1 || (int)$port>65535
                || ($from !== '' && !filter_var($from,FILTER_VALIDATE_EMAIL))
                || ($host !== '' && preg_match('/^[a-zA-Z0-9.-]+$/D',$host)!==1)) {
                throw new \InvalidArgumentException('Invalid SMTP configuration.');
            }
            if ($mode==='smtp' && (!$host || !$from || !$username || !$secret
                || !class_exists(\Symfony\Component\Mailer\Mailer::class))) {
                throw new \InvalidArgumentException('Configure SMTP credentials and installed mailer before activation.');
            }
            $value = ['default'=>$mode,'from_address'=>$from,'from_name'=>$field($input,'from_name',80),
                'host'=>$host,'port'=>(int)$port,'encryption'=>'tls','username'=>$username,'password'=>$secret];
        } else {
            $enabled = $flag('enabled');
            $keys = $section === 'stripe' ? ['publishable_key','secret_key','webhook_secret'] : ['client_id','client_secret'];
            foreach ($keys as $key) {
                $entered = $field($input,$key,500);
                if ($entered !== '') $value[$key] = $entered;
                elseif (!isset($value[$key])) $value[$key] = (string) config(
                    $section === 'stripe' ? 'billing.providers.stripe.'.$key : 'social_auth.providers.'.$section.'.'.$key, '');
            }
            if ($enabled) {
                if ($section === 'stripe') {
                    if (!(bool) config('auth.guards.frontend.enabled', false) ||
                        !str_starts_with($value['publishable_key'],'pk_') ||
                        !str_starts_with($value['secret_key'],'sk_') ||
                        !str_starts_with($value['webhook_secret'],'whsec_') ||
                        !class_exists(\Stripe\StripeClient::class)) {
                        throw new \InvalidArgumentException('Members, Stripe keys and vendor dependency are required.');
                    }
                } else {
                    $klass = $section === 'google' ? \League\OAuth2\Client\Provider\Google::class
                        : \League\OAuth2\Client\Provider\Github::class;
                    if (!$value['client_id'] || !$value['client_secret'] || !class_exists($klass)) {
                        throw new \InvalidArgumentException('OAuth client credentials and dependency required.');
                    }
                }
            }
            $value['enabled'] = $enabled;
        }
        $all[$section] = $value;
        self::write($all);
    }

    public static function write(array $data): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))
            || is_link($path)) throw new \RuntimeException('Private config directory unsafe.');
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (strlen($json)>32768) throw new \RuntimeException('Configuration too large.');
        $tmp = tempnam($dir, '.config-');
        if (!is_string($tmp)) throw new \RuntimeException('Cannot save configuration.');
        try {
            chmod($tmp,0600);
            if (file_put_contents($tmp,$json,LOCK_EX)!==strlen($json) || !rename($tmp,$path)) {
                throw new \RuntimeException('Failed to save private configuration.');
            }
            chmod($path,0600);
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
    }

    public static function hex(mixed $s): bool
    {
        return is_string($s) && preg_match('/^#[0-9a-fA-F]{6}$/D',$s)===1;
    }

    public static function logo(mixed $s): bool
    {
        return is_string($s) && ($s==='' ||
            (strlen($s)<190 && !str_contains($s,'..')
                && preg_match('~^/assets/[a-zA-Z0-9_./-]+\.(?:svg|png|webp|jpg)$~D',$s)===1));
    }
}
