<?php
$e=static fn($x):string=>htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$v=static fn($s,$k,$d='')=>$saved[$s][$k]??$d;
$modules=['members'=>'Membres','stripe'=>'Stripe','google'=>'Connexion Google','github'=>'Connexion GitHub'];
?>
<section class="panel-card p-8" style="max-width:1200px">
  <p class="eyebrow">ADMINISTRATION</p>
  <h1 class="display-title text-3xl">Modules & réglages</h1>
  <p class="muted-copy mt-3">Paramètres modifiables sans CodeGraft. Les secrets ne sont jamais réaffichés.</p>
  <?php if(!empty($notice)):?><p role="status" class="notice mt-3"><?=$e($notice)?></p><?php endif;?>
  <?php foreach(['design'=>'Apparence','seo'=>'SEO','mail'=>'Email / SMTP']+$modules as $section=>$label): ?>
    <form method="post" action="<?=$e($adminPath)?>/settings" autocomplete="off" class="surface-card p-6 mt-6">
      <?=csrf_field()?>
      <input type="hidden" name="section" value="<?=$e($section)?>">
      <h2 class="text-xl font-bold"><?=$e($label)?></h2>
      <?php if($section==='design'):?>
        <p class="muted-copy">Sans recompilation des assets.</p>
        <?php foreach(['primary'=>'Primaire','accent'=>'Accent'] as $k=>$label): ?>
          <label style="display:inline-block;margin:14px 20px 10px 0"><?=$e($label)?>
            <input type="color" name="<?=$e($k)?>" value="<?=$e($v('design',$k,config('design.palette.'.$k,'#2563eb')))?>">
          </label>
        <?php endforeach;?>
        <label style="display:block">Chemin logo dans public/assets
          <input name="logo" placeholder="/assets/logo.svg" maxlength="190" value="<?=$e($v('design','logo',config('branding.logo','')))?>" style="display:block;width:100%">
        </label>
      <?php elseif($section==='seo'):?>
        <label style="display:block;margin:12px 0">Séparateur des titres
          <input name="title_separator" maxlength="10" value="<?=$e($v('seo','title_separator',config('seo.title_separator',' | ')))?>" style="width:100%;display:block">
        </label>
        <label style="display:block">Description par défaut
          <textarea name="default_description" maxlength="300" rows="3" style="width:100%;display:block"><?=$e($v('seo','default_description',config('seo.default_description','')))?></textarea>
        </label>
      <?php elseif($section==='mail'):?>
        <label>Mode <select name="default"><option value="log" <?=$v('mail','default',config('mail.default','log'))==='log'?'selected':''?>>Journal</option>
            <option value="smtp" <?=$v('mail','default',config('mail.default','log'))==='smtp'?'selected':''?>>SMTP</option></select></label>
        <?php foreach(['from_address'=>'Email expéditeur','from_name'=>'Nom expéditeur','host'=>'Serveur SMTP','username'=>'Utilisateur SMTP','port'=>'Port SMTP'] as $key=>$label):?>
          <label style="display:block;margin:12px 0"><?=$e($label)?>
            <input name="<?=$e($key)?>" value="<?=$e($v('mail',$key,$key==='port'?587:''))?>" style="width:100%;display:block" maxlength="190">
          </label>
        <?php endforeach;?>
        <label style="display:block">Mot de passe SMTP
          <input type="password" name="password" autocomplete="new-password" placeholder="Laisser vide pour conserver" maxlength="500" style="display:block;width:100%">
        </label>
      <?php elseif($section==='members'):?>
        <label style="display:block;margin:12px 0"><input type="checkbox" name="enabled" value="1" <?=$v('members','enabled',$states['members'])?'checked':''?>> Espace membres actif</label>
        <label><input type="checkbox" name="registration_enabled" value="1" <?=$v('members','registration_enabled',config('auth.guards.frontend.registration_enabled',false))?'checked':''?>> Inscriptions publiques</label>
      <?php else:?>
        <p class="muted-copy">Activer uniquement après avoir renseigné les identifiants. Les champs secrets vides conservent l'ancienne valeur.</p>
        <label style="display:block;margin:12px 0"><input type="checkbox" name="enabled" value="1" <?=$v($section,'enabled',$states[$section])?'checked':''?>> Activer <?=$e($label)?></label>
        <?php foreach($section==='stripe'?['publishable_key','secret_key','webhook_secret']:['client_id','client_secret'] as $key):
          $secret=str_contains($key,'secret');
          $current=(string)$v($section,$key,config($section==='stripe'?'billing.providers.stripe.'.$key:'social_auth.providers.'.$section.'.'.$key,''));
        ?>
          <label style="display:block;margin:12px 0"><?=$e(str_replace('_',' ',$key))?>
            <input type="<?=$secret?'password':'text'?>" name="<?=$e($key)?>" value="<?=$secret?'':$e($current)?>"
                placeholder="<?=$secret&&$current!==''?'Déjà configuré':''?>" autocomplete="off" maxlength="500" style="display:block;width:100%">
          </label>
        <?php endforeach;?>
      <?php endif;?>
      <label style="display:block;margin:20px 0 10px">Mot de passe propriétaire
        <input type="password" name="owner_password" required autocomplete="current-password" style="display:block;width:100%;max-width:360px">
      </label>
      <button class="button-primary" type="submit">Enregistrer <?=$e($label)?></button>
    </form>
  <?php endforeach;?>
  <div class="surface-card p-6 mt-6">
    <h2 class="text-xl font-bold">MCP & CodeGraft</h2>
    <p class="muted-copy">MCP : <?=$states['mcp']?'installé':'non installé'?>. Le module MCP doit être choisi pendant la génération du Starter.</p>
    <?php if($states['mcp']):?><a href="<?=$e($adminPath)?>/mcp">Gérer CodeGraft</a><?php endif;?>
  </div>
</section>
