<?php
declare(strict_types=1);

namespace CodeGraft;

use InvalidArgumentException;

/**
 * Shared owner control panel: embedded in WP admin or standalone admin login.
 * Changes are immediately applied by MCP handlers from the vault policy.
 */
final class Admin
{
    public static function handle(Vault $vault, array $post): array
    {
        $action = (string)($post['action'] ?? '');
        if ($action === 'rotate_token') {
            return ['message' => 'Jeton MCP renouvelé. Le précédent cesse immédiatement de fonctionner.',
                'token' => $vault->rotateToken()];
        }
        if ($action === 'revoke_token') {
            $vault->revokeToken();
            return ['message' => 'Jeton révoqué. Tous les appels MCP distants sont maintenant refusés.'];
        }
        if ($action !== 'save_policy') throw new InvalidArgumentException('Action admin inconnue.');
        $existing = $vault->policy();
        $rules = [];
        foreach (Policy::OPERATIONS as $key => $label) {
            $rules[$key] = ($post['permission'][$key] ?? '') === '1';
        }
        $existing['permissions'] = $rules;
        $existing['enabled'] = ($post['enabled'] ?? '') === '1';
        foreach (['read_dirs', 'write_dirs', 'extra_denied'] as $field) {
            $input = $post[$field] ?? '';
            if (!is_string($input) || strlen($input) > 5000) {
                throw new InvalidArgumentException('Règles trop longues.');
            }
            $existing[$field] = array_values(array_filter(array_map('trim',
                preg_split('/\r?\n/', $input) ?: []),
                static fn (string $value): bool => $value !== ''));
        }
        $vault->savePolicy($existing);
        return ['message' => 'Droits enregistrés et appliqués immédiatement. Aucune reconnexion ChatGPT nécessaire.'];
    }

    public static function render(Vault $vault, string $postUrl, string $csrfField, ?string $message = null, ?string $token = null, array $mcpEndpoints = []): string
    {
        $policy = $vault->policy();
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $checked = static fn (bool $on): string => $on ? ' checked' : '';
        $operationRows = '';
        foreach (Policy::OPERATIONS as $key => $label) {
            $isChecked = ($policy['permissions'][$key] ?? false) === true;
            $operationRows .= '<label class="cg-permission"><span><strong>' . $esc($label)
                . '</strong><small>' . $esc($key) . '</small></span><input type="checkbox" name="permission['
                . $esc($key) . ']" value="1"' . $checked($isChecked) . '></label>';
        }
        $notice = '';
        if ($message !== null) {
            $notice = '<p class="cg-notice" role="status">' . $esc($message) . '</p>';
        }
        if ($token !== null) {
            $notice .= '<div class="cg-token"><strong>Copiez maintenant votre jeton MCP. Il ne sera plus affiché.</strong>'
                . '<textarea readonly rows="2" autocomplete="off">' . $esc($token) . '</textarea>'
                . '<small>Conservez ce secret dans les paramètres de votre client MCP. Le renouveler invalide les connexions utilisant l’ancien.</small></div>';
        }
        // Public endpoint URLs are informational and never change the stable MCP tool schema.
        $endpointRows = '';
        foreach ($mcpEndpoints as $label => $url) {
            if (!is_string($label) || !is_string($url) || $url === ''
                || strlen($url) > 500 || str_contains($url, '<')) {
                continue;
            }
            $endpointRows .= '<div class="cg-endpoint"><strong>' . $esc($label)
                . '</strong><code>' . $esc($url) . '</code></div>';
        }
        if ($endpointRows === '') {
            $endpointRows = '<small>Choisissez un point d’entrée MCP lors de l’installation.</small>';
        }
        $dirFields = '';
        foreach ([
            'read_dirs' => ['Dossiers autorisés en lecture', 'Un dossier relatif par ligne. Ex. app/Controllers ou wp-content/themes/mon-theme'],
            'write_dirs' => ['Dossiers autorisés en écriture', 'Laissez vide tant que vous n’avez pas besoin de déployer du code.'],
            'extra_denied' => ['Exclusions supplémentaires', 'Les secrets, le cœur CodeGraft et les dossiers système restent interdits même s’ils ne figurent pas ici.'],
        ] as $name => [$title, $hint]) {
            $dirFields .= '<label class="cg-field"><strong>' . $esc($title) . '</strong><small>' . $esc($hint)
                . '</small><textarea rows="4" name="' . $esc($name) . '" spellcheck="false">'
                . $esc(implode("\n", $policy[$name] ?? [])) . '</textarea></label>';
        }
        $enabled = ($policy['enabled'] ?? false) === true;
        $state = $enabled ? 'Moteur autorisé' : 'Moteur suspendu';
        $body = '<div class="cg-panel"><style>' . self::style() . '</style>'
            . '<header class="cg-top"><div><p class="cg-eyebrow">CODEGRAFT / ADMINISTRATION</p><h1>Les droits de votre IA, <em>sous contrôle.</em></h1>'
            . '<p>Les outils MCP restent identiques. Toutes les restrictions prennent effet au prochain appel, sans recharger ChatGPT.</p></div>'
            . '<div class="cg-status">' . $esc($state) . '</div></header>'
            . $notice
            . '<div class="cg-columns"><main><form method="post" action="' . $esc($postUrl) . '">'
            . $csrfField
            . '<input type="hidden" name="action" value="save_policy">'
            . '<section class="cg-card"><div class="cg-card-heading"><div><span class="cg-step">01 / ACTIVATION</span>'
            . '<h2>Interrupteur général</h2><p>En cas d’urgence, coupez tous les outils en un clic.</p></div></div>'
            . '<label class="cg-switchline"><span><strong>Autoriser les commandes MCP</strong><small>Lecture comme écriture, selon les droits détaillés ci-dessous.</small></span>'
            . '<input type="checkbox" name="enabled" value="1"' . $checked($enabled) . '></label></section>'
            . '<section class="cg-card"><div class="cg-card-heading"><span class="cg-step">02 / CAPACITÉS</span><h2>Opérations autorisées</h2>'
            . '<p>Activer une capacité ne lève jamais les restrictions de chemins.</p></div><div class="cg-permissions">'
            . $operationRows . '</div></section>'
            . '<section class="cg-card"><div class="cg-card-heading"><span class="cg-step">03 / PÉRIMÈTRE</span>'
            . '<h2>Où l’IA peut-elle travailler ?</h2><p>Chaque chemin est relatif à la racine du site. Les symlinks et dossiers sensibles sont bloqués.</p></div>'
            . $dirFields . '</section>'
            . '<button class="cg-primary" type="submit">Enregistrer les autorisations ↗</button>'
            . '</form></main>'
            . '<aside><section class="cg-card cg-side"><span class="cg-step">CONNEXION MCP</span><h2>Un seul connecteur.</h2>'
            . '<p>Configurez votre client IA une seule fois avec l’URL MCP et son jeton d’accès. Modifier les droits ne modifie pas la liste des outils.</p>'
            . '<div class="cg-endpoints">' . $endpointRows . '</div>'
            . '<p><small>Préférez l’URL sans extension .php. L’URL de secours sert aux hébergements sans réécriture ou aux anciens liens.</small></p>'
            . '<div class="cg-code">cg_capabilities<br>cg_list_files<br>cg_search<br>cg_read_files<br>cg_prepare_patch<br>cg_validate_patch<br>cg_deploy_patch<br>cg_rollback_patch<br>cg_history</div>'
            . '<form method="post" action="' . $esc($postUrl) . '">' . $csrfField
            . '<button class="cg-secondary" name="action" value="rotate_token" type="submit">Générer / renouveler le jeton</button>'
            . '<button class="cg-danger" name="action" value="revoke_token" type="submit">Révoquer la connexion</button></form>'
            . '</section><section class="cg-card cg-side"><span class="cg-step">SÉCURITÉ</span><h2>Par défaut, tout est bloqué.</h2>'
            . '<p>Le déploiement exige une autorisation, des dossiers explicitement sélectionnés, des empreintes SHA-256 et une confirmation.</p>'
            . '<p>Les sauvegardes sont conservées en dehors du site public et les versions peuvent être restaurées si aucun fichier n’a divergé.</p>'
            . '<small>Dernière modification : ' . $esc((string)($policy['updated_at'] ?? 'Jamais')) . '</small></section></aside></div>'
            . '<footer class="cg-footer">CodeGraft v0.1 · Adaptateur ' . $esc($vault->adapter)
            . ' · Coffre privé actif</footer></div>';
        return $body;
    }

    private static function style(): string
    {
        return <<<'CSS'
.cg-panel{--cg-ink:#203f31;--cg-deep:#163828;--cg-mint:#e6efde;--cg-line:#e0e8dd;font:14px/1.6 system-ui,-apple-system,sans-serif;color:var(--cg-ink);padding:24px 15px 55px;max-width:1420px;margin:auto}
.cg-panel *{box-sizing:border-box}.cg-panel h1,.cg-panel h2{font-family:Georgia,serif;letter-spacing:-.045em;line-height:1.15;font-weight:500}.cg-panel h1{font-size:clamp(30px,3.6vw,48px);margin:9px 0 10px}.cg-panel h1 em{color:#75a17b}.cg-panel h2{font-size:25px;margin:8px 0 9px}.cg-panel p{color:#819386;font-size:13px;margin:0 0 16px}.cg-panel small{display:block;color:#94a496;font-size:11px;line-height:1.5}.cg-top{display:flex;justify-content:space-between;align-items:center;gap:28px;margin:0 0 26px}.cg-top>div:first-child{max-width:740px}.cg-eyebrow,.cg-step{font-size:10px!important;letter-spacing:.16em;color:#6f9876!important;font-weight:850}.cg-status{font-size:11px;font-weight:800;background:var(--cg-mint);padding:10px 15px;border-radius:99px;white-space:nowrap}.cg-columns{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start}.cg-columns main,.cg-columns aside{min-width:0}.cg-columns form{display:grid;gap:17px}.cg-card{background:white;border:1px solid var(--cg-line);border-radius:15px;padding:27px;margin-bottom:17px;box-shadow:0 5px 28px #133e2310}.cg-card-heading{margin-bottom:20px}.cg-permissions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.cg-permission,.cg-switchline{display:flex;align-items:center;justify-content:space-between;gap:15px;background:#f7faf6;border:1px solid #e7eee4;border-radius:10px;padding:13px 15px;cursor:pointer}.cg-permission strong,.cg-switchline strong{display:block;font-size:12px}.cg-permission input,.cg-switchline input{accent-color:#2b6c44;width:18px;height:18px;flex:none}.cg-field{display:block;margin:16px 0}.cg-field strong{display:block;font-size:12px;margin-bottom:3px}.cg-field textarea,.cg-token textarea{width:100%;margin:10px 0 0;border:1px solid #cbdaca;background:#fafcf9;border-radius:9px;padding:12px;font:12px/1.5 ui-monospace,monospace;resize:vertical;color:#254832}.cg-panel button{cursor:pointer;min-height:42px;border-radius:9px;font:700 12px system-ui;padding:12px 16px}.cg-primary{background:var(--cg-deep);color:white;border:0;width:max-content}.cg-secondary{background:#edf3e9;border:1px solid #d6e4d1;color:var(--cg-deep)}.cg-danger{background:#fff2ef;border:1px solid #e9d4cd;color:#a04f42}.cg-side form{display:flex;flex-direction:column;gap:9px}.cg-endpoints{margin:18px 0}.cg-endpoint{margin:0 0 11px;background:#f4f8f2;border:1px solid #e0eadb;padding:10px;border-radius:8px}.cg-endpoint strong{display:block;font-size:10px;margin-bottom:6px}.cg-endpoint code{font:11px/1.5 ui-monospace,monospace;overflow-wrap:anywhere;display:block;color:#24543b}.cg-side p{line-height:1.75}.cg-code{font:12px/1.9 ui-monospace,monospace;background:#f1f5ef;padding:16px;border-radius:10px;margin:12px 0 20px;color:#41614a}.cg-notice,.cg-token{background:#e6f3df!important;border:1px solid #b4d5aa;padding:15px 18px;border-radius:10px;color:#315a3c!important}.cg-token{margin-bottom:20px}.cg-token strong{font-size:13px}.cg-footer{margin-top:28px;font-size:11px;color:#8ba28d}
@media(max-width:980px){.cg-columns{grid-template-columns:1fr}.cg-top{flex-direction:column;align-items:flex-start}}@media(max-width:570px){.cg-permissions{grid-template-columns:1fr}.cg-card{padding:18px}.cg-panel{padding:14px 4px 38px}.cg-top{gap:10px}}
CSS;
    }
}
