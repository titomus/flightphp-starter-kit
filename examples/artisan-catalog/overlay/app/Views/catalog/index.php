<style>
:root{color-scheme:light}body{background:#f5f7f6!important;color:#192b27!important;font-family:system-ui,-apple-system,sans-serif}
.catalog{max-width:1100px;margin:2rem auto;padding:0 1.4rem;color:#1f342e}
.catalog a{color:#247a62}.catalog h1{font-size:2.5rem;line-height:1.15;font-weight:800;margin:.2rem 0 1rem}.catalog h2{font-size:1.25rem;font-weight:750}
.catalog .muted{color:#627771}.catalog .eyebrow{letter-spacing:.12em;font-size:.8rem;font-weight:750;color:#2f866b;text-transform:uppercase}
.catalog .panel{background:white;border:1px solid #dce7e2;border-radius:20px;padding:1.8rem;margin:1.2rem 0;box-shadow:0 6px 20px rgba(28,62,48,.035)}
.catalog .intro{display:flex;gap:2rem;justify-content:space-between;align-items:center;flex-wrap:wrap}
.catalog .button{display:inline-block;border:0;border-radius:10px;padding:.75rem 1.1rem;font-weight:700;cursor:pointer;background:#167b5f;color:white;text-decoration:none}
.catalog .button.alt{background:#eef4f1;color:#25453b}.catalog .button:disabled{background:#bec7c3;cursor:not-allowed}
.catalog input[type=file]{padding:.9rem;border:1px dashed #93b9a9;border-radius:12px;display:block;width:100%;margin:.85rem 0;background:#f9fcfa}
.catalog .table-wrap{overflow:auto}.catalog table{border-collapse:collapse;width:100%;text-align:left;min-width:590px}
.catalog th{font-size:.76rem;text-transform:uppercase;letter-spacing:.07em;color:#697c75}
.catalog td,.catalog th{border-bottom:1px solid #e6eeea;padding:.85rem .6rem}
.catalog .pill{border-radius:20px;padding:.25rem .6rem;font-size:.75rem;font-weight:700;background:#e2f5eb;color:#247453}
.catalog .pill.update{background:#fff1da;color:#986121}
.catalog .alert{border-radius:11px;padding:1rem 1.2rem;margin:1rem 0;background:#e5f6ed;color:#1b6747}
.catalog .alert.error{background:#fff0eb;color:#a33b22}.catalog .actions{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.catalog pre{white-space:pre-wrap;background:#f4f7f5;border-radius:9px;padding:1rem;color:#364b43;font-size:.85rem}
</style>
<div class="catalog">
  <p class="eyebrow">Atelier · Démonstration FlightPHP + MCP</p>
  <div class="intro">
    <div>
      <h1>Vos références, sans la corvée.</h1>
      <p class="muted">Catalogue artisan · SQLite · Import CSV · Outils métier MCP</p>
    </div>
    <a href="/admin" class="button alt">Administration</a>
  </div>

  <?php if ($message): ?><div class="alert"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <section class="panel">
    <h2>Importer votre catalogue</h2>
    <p class="muted">Un fichier CSV avec les colonnes <code>reference, designation, prix_ht, stock</code>. Jusqu'à 500 lignes et 2 Mo par import.</p>
    <form action="/catalog/preview" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="file" name="catalog_file" accept=".csv,text/csv" required>
      <button class="button" type="submit">Analyser le fichier</button>
    </form>
  </section>

  <?php if (is_array($preview)): ?>
  <section class="panel">
    <h2>Prévisualisation</h2>
    <p class="muted"><?= (int) $preview['counts']['create'] ?> créations · <?= (int) $preview['counts']['update'] ?> mises à jour · <?= count($preview['errors']) ?> erreurs</p>
    <?php if ($preview['errors']): ?>
      <div class="alert error">
        <?php foreach ($preview['errors'] as $item): ?>
          <div>Ligne <?= (int) $item['line'] ?> : <?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Action</th><th>Référence</th><th>Désignation</th><th>Prix HT</th><th>Stock</th></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $item): ?>
          <tr>
            <td><span class="pill <?= $item['action'] === 'update' ? 'update' : '' ?>"><?= $item['action'] === 'update' ? 'Modifier' : 'Créer' ?></span></td>
            <td><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= number_format($item['price_cents'] / 100, 2, ',', ' ') ?> €</td>
            <td><?= (int) $item['stock'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="actions" style="margin-top:1.3rem">
      <form action="/catalog/commit" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="button" <?= !$preview['valid'] ? 'disabled' : '' ?>>Confirmer l'import</button>
      </form>
      <form action="/catalog/cancel" method="post">
        <?= csrf_field() ?>
        <button class="button alt" type="submit">Annuler</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="intro">
      <h2>Catalogue actuel</h2>
      <span class="pill"><?= count($items) ?> références affichées</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Référence</th><th>Désignation</th><th>Prix HT</th><th>Stock</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= number_format((int) $item['price_cents'] / 100, 2, ',', ' ') ?> €</td>
            <td><?= (int) $item['stock'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="4" class="muted">Aucune référence pour le moment. Importez votre premier fichier CSV.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <p class="muted">Démo mono-utilisateur. Les agents IA utilisent les mêmes services via MCP, avec autorisations d'écriture séparées.</p>
</div>
