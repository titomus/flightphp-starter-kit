<?php
declare(strict_types=1);

require dirname(__DIR__).'/installer/WebInstallEngine.php';
function config(string $key,mixed $default=null): mixed { return $default; }

$root=sys_get_temp_dir().'/flight-settings-'.bin2hex(random_bytes(6));
mkdir($root.'/public',0700,true);
try {
    $settings=[
        'type'=>'saas','project_name'=>'Test Modules','base_url'=>'https://example.test',
        'admin_email'=>'owner@example.test','admin_password'=>'SuperSecret2026!!',
        'password_confirm'=>'SuperSecret2026!!','members'=>'1','mcp'=>'1','stripe'=>'1',
        'google'=>'1','github'=>'1','smtp'=>'1','design_preset'=>'blog',
        'primary'=>'#224466','accent'=>'#66aa55',
    ];
    $data=WebInstallRecipe::validate($settings);
    foreach (['preset'=>'blog','primary'=>'#224466','google'=>true,'smtp'=>true,'stripe'=>true] as $k=>$v) {
        if ($data[$k]!==$v) throw new RuntimeException('Installer option missing: '.$k);
    }
    $config=require dirname(__DIR__).'/installer/config.php';
    $prepared=WebInstallRecipe::apply($config,$data,$root);
    if ($prepared['stripe']['enabled'] || $prepared['social_auth']['google']['enabled']
        || $prepared['social_auth']['github']['enabled'] || $prepared['mail']['default']!=='log') {
        throw new RuntimeException('Unconfigured provider accidentally enabled.');
    }
    foreach (['primary'=>'red','accent'=>'#redred','design_preset'=>'../../../app/config'] as $key=>$bad) {
        try {
            WebInstallRecipe::validate(array_replace($settings,[$key=>$bad]));
            throw new RuntimeException('Unsafe installer option accepted: '.$key);
        } catch (InvalidArgumentException) {}
    }
    $cfg=WebInstallRecipe::apply($config,$data,$root);
    $options=InstallerOptions::fromArgv(['install','--dry-run']);
    $features=InstallerFeatures::fromConfig($cfg,$options);
    $files=(new ProjectBlueprint($cfg,$root,$features,new TemplateCatalog($features)))->files();
    $needs=['app/Services/RuntimeSettings.php','app/Controllers/Admin/SettingsController.php',
        'app/Views/admin/settings.php','app/Views/layouts/main.php','app/Views/admin/layouts/main.php'];
    foreach ($needs as $relative) {
        $file=$root.'/'.$relative;
        if (!isset($files[$file])) throw new RuntimeException('Generated settings component missing: '.$relative);
        if (!is_dir(dirname($file))) mkdir(dirname($file),0700,true);
        file_put_contents($file,$files[$file]);
        if (str_ends_with($relative,'.php')) {
            $log=[];
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1',$log,$status);
            if ($status!==0) throw new RuntimeException('Settings PHP lint: '.$relative.' '.implode("\n",$log));
        }
    }
    if (!str_contains($files[$root.'/app/Views/admin/layouts/main.php'],'/settings')
        || !str_contains($files[$root.'/app/Views/layouts/main.php'],'RuntimeSettings::css()')) {
        throw new RuntimeException('Admin nav or live design CSS not generated.');
    }
    require $root.'/app/Services/RuntimeSettings.php';
    \App\Services\RuntimeSettings::update(['section'=>'design','primary'=>'#123abc',
        'accent'=>'#987fed','logo'=>'/assets/logo.svg']);
    $stored=\App\Services\RuntimeSettings::read();
    if ($stored['design']['primary']!=='#123abc'
        || \App\Services\RuntimeSettings::overrides($stored)['design']['palette']['accent']!=='#987fed') {
        throw new RuntimeException('Theme settings were not persisted.');
    }
    $file=\App\Services\RuntimeSettings::path();
    if (!is_file($file) || (fileperms($file)&0077)!==0
        || str_starts_with(realpath($file),realpath($root.'/public').'/')) {
        throw new RuntimeException('Private config permissions or path invalid.');
    }
    $reject=static function(array $form,string $message): void {
        try { \App\Services\RuntimeSettings::update($form); }
        catch (InvalidArgumentException) { return; }
        throw new RuntimeException('Unsafe settings accepted: '.$message);
    };
    $reject(['section'=>'design','primary'=>'blue','accent'=>'#123abc','logo'=>''],'invalid color');
    $reject(['section'=>'design','primary'=>'#123abc','accent'=>'#333333','logo'=>'/assets/../../.env'],'traversal');
    $reject(['section'=>'stripe','enabled'=>'1'],'Stripe no keys');
    $reject(['section'=>'google','enabled'=>'1'],'Google no keys');
    $reject(['section'=>'mail','default'=>'smtp','port'=>'587','host'=>'','from_address'=>'',
        'username'=>'','password'=>''],'SMTP no credentials');
    \App\Services\RuntimeSettings::update(['section'=>'seo','title_separator'=>' | ','default_description'=>'Nouvelle description']);
    \App\Services\RuntimeSettings::update(['section'=>'members','enabled'=>'1','registration_enabled'=>'0']);
    \App\Services\RuntimeSettings::update(['section'=>'google','enabled'=>'0','client_id'=>'demo-client','client_secret'=>'demo-secret']);
    $new=\App\Services\RuntimeSettings::read();
    if (($new['google']['enabled']??true)!==false ||
        ($new['google']['client_secret']??'')!=='demo-secret' ||
        \App\Services\RuntimeSettings::overrides($new)['auth']['guards']['frontend']['registration_enabled']!==false) {
        throw new RuntimeException('Module settings not stored as intended.');
    }
    echo "[OK] Installation presets, private runtime settings, modules, security and CSS in generated app.\n";
} finally {
    WebInstallEngine::removeDirectory($root);
}
