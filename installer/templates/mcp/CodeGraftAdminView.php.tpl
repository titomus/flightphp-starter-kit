<?php
$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$permissions = $policy['permissions'] ?? [];
$on = !empty($policy['enabled']);
?>
<section class="panel-card p-8" style="max-width:1440px">
    <p class="eyebrow">ADMINISTRATION / CODEGRAFT</p>
    <h1 class="display-title text-3xl">Atelier IA & MCP</h1>
    <p class="muted-copy mt-3">Le moteur CodeGraft complet est intégré à FlightPHP. Les neuf commandes restent stables : les droits sont relus à chaque appel, sans reconnecter ChatGPT.</p>
    <?php if (!empty($notice)): ?><p class="notice notice--success mt-5" role="status"><?= $esc($notice) ?></p><?php endif; ?>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">État du moteur</h2>
        <p class="mt-3"><strong>CodeGraft :</strong> <?= $on ? 'Activé' : 'Suspendu' ?></p>
        <p><strong>Verrou .env :</strong> <?= $serverEnabled ? 'Autorise les mises à jour' : 'Bloqué' ?></p>
        <p><strong>Approbation propriétaire :</strong> <?= $approved ? 'Accordée' : 'Révoquée' ?></p>
        <p><strong>Déploiement effectif :</strong> <?= $serverEnabled && $approved && $on && !empty($permissions['deploy']) ? 'Disponible pour les jetons app:deploy' : 'Bloqué' ?></p>
        <p class="mt-3"><strong>URL MCP :</strong> <code><?= $esc($mcpUrl) ?></code></p>
        <p class="muted-copy mt-2">Une connexion OAuth valide avec app:deploy reste obligatoire. Aucun jeton CodeGraft parallèle n’est nécessaire.</p>
    </div>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">1. Permissions et dossiers</h2>
        <p class="muted-copy mb-4">Le véritable moteur CodeGraft protège les secrets, l’administration, le cœur OAuth et les dépendances. Les dossiers en écriture sont vides par défaut.</p>
        <form method="post" action="<?= $esc($adminPath) ?>/mcp" class="space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_policy">
            <label style="display:flex;gap:12px;align-items:center;margin:12px 0">
                <input type="checkbox" name="enabled" value="1" <?= $on ? 'checked' : '' ?>>
                <strong>Activer les commandes CodeGraft</strong>
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
                <?php foreach ($operations as $key => $label): ?>
                <label style="display:flex;justify-content:space-between;gap:14px;align-items:center;padding:12px;border:1px solid #d5e1df;border-radius:10px">
                    <span><?= $esc($label) ?></span>
                    <input type="checkbox" name="permission[<?= $esc($key) ?>]" value="1" <?= !empty($permissions[$key]) ? 'checked' : '' ?>>
                </label>
                <?php endforeach; ?>
            </div>
            <?php foreach ([
                'read_dirs' => ['Dossiers autorisés en lecture', 'Un chemin relatif par ligne'],
                'write_dirs' => ['Dossiers autorisés en écriture', 'Commencez par app/Controllers/Business, app/Services/Business, app/Views/business et public/assets'],
                'extra_denied' => ['Exclusions supplémentaires', 'Une exclusion par ligne, en plus des protections permanentes'],
            ] as $field => [$label, $hint]): ?>
                <label style="display:block;margin-top:14px">
                    <strong><?= $esc($label) ?></strong>
                    <small style="display:block;color:#607080"><?= $esc($hint) ?></small>
                    <textarea name="<?= $esc($field) ?>" rows="4" style="width:100%;border:1px solid #cbd5d1;padding:12px;border-radius:8px;font:13px monospace;margin-top:8px"><?= $esc(implode("\n", $policy[$field] ?? [])) ?></textarea>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="button-primary">Enregistrer les droits CodeGraft</button>
        </form>
    </div>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">2. Autorisation du propriétaire</h2>
        <p class="muted-copy">Deux validations indépendantes : le verrou serveur dans .env et votre accord ici. Changer une autorisation n’altère jamais .env.</p>
        <form method="post" action="<?= $esc($adminPath) ?>/mcp" class="space-y-4 mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_deploy">
            <label><input type="checkbox" name="deployment_approved" value="1" <?= $approved ? 'checked' : '' ?>> J'autorise les déploiements et restaurations MCP</label>
            <div><label>Mot de passe propriétaire <input type="password" name="owner_password" required autocomplete="current-password" style="display:block;width:100%;max-width:400px;border:1px solid #cbd5d1;padding:10px;border-radius:8px"></label></div>
            <div><label>Pour activer, saisir AUTORISER <input type="text" name="confirmation" placeholder="AUTORISER" autocomplete="off" style="display:block;width:100%;max-width:400px;border:1px solid #cbd5d1;padding:10px;border-radius:8px"></label></div>
            <button type="submit" class="button-primary">Mettre à jour l’autorisation</button>
        </form>
    </div>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">3. Connexions OAuth</h2>
        <p class="muted-copy">Révocation immédiate des jetons connus. Le client peut demander une nouvelle autorisation ultérieurement.</p>
        <?php if (empty($clients)): ?><p>Aucune connexion enregistrée.</p><?php else: ?>
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
            <thead><tr><th align="left">Client</th><th align="left">Retour OAuth</th><th align="left">Jetons actifs</th><th>Révocation</th></tr></thead>
            <tbody><?php foreach ($clients as $client): ?><tr style="border-top:1px solid #dde6e2">
                <td style="padding:10px"><?= $esc(substr($client['client_id'], 0, 24)) ?>…</td>
                <td style="padding:10px;overflow-wrap:anywhere"><?= $esc($client['redirect_uri']) ?></td>
                <td style="padding:10px"><?= (int) $client['active_tokens'] ?></td>
                <td style="padding:10px">
                    <form method="post" action="<?= $esc($adminPath) ?>/mcp">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="revoke_oauth">
                        <input type="hidden" name="client_id" value="<?= $esc($client['client_id']) ?>">
                        <input type="password" name="owner_password" placeholder="Mot de passe" required style="width:130px;border:1px solid #cbd5d1;padding:8px;border-radius:8px">
                        <button type="submit">Révoquer</button>
                    </form>
                </td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">4. Historique des déploiements</h2>
        <?php if (empty($history)): ?><p class="muted-copy">Aucune version CodeGraft déployée.</p><?php else: ?>
        <div style="overflow-x:auto"><table style="width:100%">
        <thead><tr><th align="left">Version</th><th align="left">Statut</th><th align="left">Fichiers</th><th>Restauration</th></tr></thead>
        <tbody><?php foreach ($history as $release): ?><tr style="border-top:1px solid #dde6e2">
            <td style="padding:9px"><code><?= $esc($release['id'] ?? '') ?></code></td>
            <td><?= $esc($release['status'] ?? '') ?></td>
            <td><?= $esc(implode(', ', $release['paths'] ?? [])) ?></td>
            <td><?php if (($release['status'] ?? '') === 'deployed'): ?>
                <form method="post" action="<?= $esc($adminPath) ?>/mcp">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rollback">
                    <input type="hidden" name="release_id" value="<?= $esc($release['id'] ?? '') ?>">
                    <input type="password" name="owner_password" required placeholder="Mot de passe" style="width:130px;border:1px solid #cbd5d1;padding:8px;border-radius:8px">
                    <input type="text" name="confirmation" required placeholder="RESTAURER" style="width:110px;border:1px solid #cbd5d1;padding:8px;border-radius:8px">
                    <button type="submit">Restaurer</button>
                </form>
            <?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <p class="muted-copy mt-3">Les restaurations refusent les conflits SHA-256. Les migrations SQL ne sont pas couvertes par cette restauration de fichiers.</p>
    </div>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">5. Journal des outils MCP CodeGraft</h2>
        <p class="muted-copy">Métadonnées uniquement : heure, outil, administrateur, résultat et durée. Ni code source, ni paramètres, ni jetons ne sont enregistrés.</p>
        <?php if (empty($audit)): ?><p>Aucune opération CodeGraft enregistrée depuis l’installation de cette version.</p><?php else: ?>
        <div style="overflow-x:auto"><table style="width:100%">
            <thead><tr><th align="left">Heure UTC</th><th align="left">Outil</th><th align="left">Administrateur</th><th align="left">Résultat</th><th align="left">Durée</th></tr></thead>
            <tbody><?php foreach ($audit as $event): ?><tr style="border-top:1px solid #dde6e2">
                <td style="padding:9px"><?= $esc($event['at'] ?? '') ?></td>
                <td><?= $esc($event['tool'] ?? '') ?></td>
                <td><?= (int) ($event['admin_id'] ?? 0) ?></td>
                <td><?= $esc($event['outcome'] ?? '') ?></td>
                <td><?= (int) ($event['ms'] ?? 0) ?> ms</td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="surface-card p-6 mt-6">
        <h2 class="text-xl font-bold">6. MCP Native Actions · Parité métier</h2>
        <p class="muted-copy mt-2">Chaque action déclarée dans <code>app/Actions/business.php</code> est accessible via le même service PHP depuis l'interface web et un outil MCP. Les contrôles d'identité, de tenant et de portée sont appliqués à chaque requête.</p>
        <p class="mt-3"><strong><?= (int) ($businessActionParity['paired'] ?? 0) ?></strong> action(s) web + MCP validées</p>
        <?php if (!empty($businessActionParity['error'])): ?>
            <p class="mt-3" style="color:#b91c1c"><strong>Contrat métier invalide :</strong> <?= $esc($businessActionParity['error']) ?>. Corrigez app/Actions/business.php ; aucune action invalide ne sera exposée.</p>
        <?php endif; ?>
        <?php if (empty($businessActionParity['actions'])): ?>
            <p class="muted-copy mt-3">Aucune action métier enregistrée. Déclarez la première action dans <code>app/Actions/business.php</code>. Le MCP est créé automatiquement, sans modifier <code>tools.php</code>.</p>
        <?php else: ?>
            <div style="overflow-x:auto;margin-top:12px">
                <table style="width:100%;border-collapse:collapse">
                    <thead><tr><th align="left">Action</th><th align="left">Mode</th><th align="left">Outil MCP</th><th align="left">Parité</th></tr></thead>
                    <tbody>
                    <?php foreach ($businessActionParity['actions'] as $entry): ?>
                        <tr style="border-top:1px solid #dde6e2">
                            <td style="padding:10px"><strong><?= $esc($entry['name']) ?></strong><small style="display:block"><?= $esc($entry['description']) ?></small></td>
                            <td><?= $esc($entry['mode']) ?></td>
                            <td><code><?= $esc($entry['tool']) ?></code></td>
                            <td>Web + MCP ✓</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="muted-copy mt-3">Vérification CLI : <code>php app/Console/check_mcp_parity.php</code>. Les anciens outils définis manuellement dans <code>business_tools.php</code> restent utilisables, mais ne bénéficient pas de ce contrôle de parité.</p>
    </div>
</section>
