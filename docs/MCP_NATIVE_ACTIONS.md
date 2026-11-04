# MCP Native Actions · Une fonctionnalité web = un outil MCP

> Disponible dans la branche officielle \`main\` de Flight Starter Kit à partir du cycle V3 beta.2. Les Releases précédentes ne contiennent pas ce moteur.

## Le principe

**Une seule action déclarée = deux accès générés :**

- \`POST /business/actions/{nom}\`, accessible via une session web authentifiée, avec CSRF et JSON ;
- \`business_{nom}\`, outil MCP créé automatiquement lors de \`tools/list\`, pour les OAuth disposant du scope adapté.

Les deux utilisent **la même fonction PHP**, les mêmes règles d'autorisation et le même schéma de validation des entrées. Il n'y a aucun second contrôleur MCP à maintenir.

Le transport MCP / OAuth du Starter et CodeGraft (développement du code) restent des composants distincts. Ajouter une action ne requiert aucune modification de \`app/Mcp/tools.php\`, \`app/routes/mcp.php\` ou \`app/routes/business.php\`.

## Créer une action métier

1. Développer le traitement dans un service PHP : \`app/Services/Business/StockService.php\`.
2. Ajouter **un seul contrat** dans \`app/Actions/business.php\`.
3. Consommer l'URL web depuis le frontend et/ou appeler l'outil MCP qui apparaît automatiquement.
4. Lancer \`php app/Console/check_mcp_parity.php\` pour contrôler le registre et le schéma des paramètres.

### Exemple : consulter le stock

Contenu à placer dans \`app/Actions/business.php\`, après avoir créé \`StockService::totalForOwner()\` :

\`\`\`php
<?php
declare(strict_types=1);

use App\Actions\ActionContext;
use App\Services\Business\StockService;

return [
    'stock_count' => [
        'description' => 'Obtenir le nombre de produits en stock pour le compte connecté',
        'mode' => 'read',
        'web' => true,
        'mcp' => true,
        'tenant_scoped' => false,
        'inputSchema' => [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ],
        'authorize' => static fn (ActionContext $actor, array $args): bool =>
            $actor->actorType === 'admin',
        'handler' => static fn (ActionContext $actor, array $args): array =>
            ['count' => (new StockService())->totalForOwner($actor->actorId)],
    ],
];
\`\`\`

**Côté web** : \`POST /business/actions/stock_count\`, body \`{}\`, avec une session authentifiée, en-tête \`Content-Type: application/json\` et \`X-CSRF-Token\` issu de \`csrf_token()\` généré par le serveur. Les requêtes cross-origin sont rejetées lorsqu'un en-tête Origin est présent. L'outil MCP correspondant se nomme automatiquement **\`business_stock_count\`**, avec le schéma exact de l'action.

**Côté MCP** : une identité OAuth validée et le scope \`business:read\` permettent de découvrir l'outil. Son exécution passe aussi par la fonction \`authorize\`. Pour une action en écriture (\`mode: write\`), le scope requis est \`business:write\`.

**Attention :** l'exemple est pédagogique et suppose que le service existe. Ne pas utiliser une méthode qui consulte des données multi-comptes sans restriction côté SQL.

## Le contrat est volontairement strict

Chaque action doit déclarer :

| Champ | Fonction |
|---|---|
| Nom \`snake_case\` | Nom stable de l'action, utilisé en web et avec préfixe \`business_\` en MCP |
| \`description\` | Description visible dans le catalogue MCP |
| \`mode\` | \`read\` ou \`write\`, détermine le scope OAuth |
| \`web\` et \`mcp\` | Tous deux obligatoirement à \`true\` |
| \`tenant_scoped\` | Obligatoire. Si \`true\`, le tenant doit être lié à l'identité côté serveur |
| \`inputSchema\` | JSON Schema strict avec \`additionalProperties: false\` ; schémas imbriqués limités |
| \`authorize\` | Fonction de contrôle d'accès **obligatoire** ; aucune permission implicite |
| \`handler\` | Fonction PHP métier commune aux deux canaux, renvoie un tableau |

Les actions incomplètes échouent **fermées** : une fonctionnalité déclarée sans jumeau MCP, sans ACL ou avec des paramètres non validés est refusée par le registre.

**Ce n'est pas un robot qui convertit magiquement toutes les méthodes PHP**. Les fonctions créées en dehors de ce registre ne sont pas inspectées, et les anciens outils manuels de \`app/Mcp/business_tools.php\` ne participent pas au contrôle de parité. Pour tenir la promesse de parité, les développeurs et les agents IA doivent créer **toutes les nouvelles actions métier** avec ce contrat.

## Identité, tenant et sécurité

- **Web** : utilisateur issu de la session authentifiée \`admin\` ou \`frontend\`, jamais de l'argument JSON.
- **MCP** : identité provenant du jeton OAuth vérifié par \`DynamicOAuth\`, et pas du corps de requête. L'actuel fournisseur Flight OAuth ne représente **que les administrateurs**, pas les comptes membres.
- **Tenant** : pour \`tenant_scoped: true\`, \`ActionContext::requireTenant()\` exige un tenant lié à l'identité authentifiée côté serveur. Si aucun tenant fiable n'est associé, l'action est refusée. Un \`tenant_id\` fourni dans les arguments du MCP ne saurait remplacer cette liaison.
- **Autorisations** : une fonction \`authorize\` est exécutée pour les deux canaux. Il faut aussi filtrer les accès SQL selon \`actorId\` ou \`requireTenant()\`.
- **Écritures** : vérification stricte des inputs et scope \`business:write\`, mais il reste nécessaire de prévoir une confirmation métier pour les actions à impact fort (achat, destruction de données, transfert).
- **Web** : seuls les POST JSON avec session et CSRF sont admis. Ne jamais utiliser un jeton de développeur CodeGraft comme jeton d'accès aux données métier.

Cela signifie qu'une action d'un **membre d'un SaaS multi-tenant** sera bien déclarée et visible dans le registre, mais **ne sera pas encore utilisable par ce membre via OAuth MCP**. Une évolution de l'authentification doit d'abord relier ses jetons au membre et à son entreprise de façon sûre. La parité du code est en place ; la parité d'accès pour chaque type d'utilisateur doit encore être complétée.

## Administration et contrôle

L'onglet **CodeGraft / IA & MCP** du back-office affiche une section **MCP Native Actions** : nom, mode, outil MCP et statut de parité. Le contrôleur de cette page lit le registre, sans exposer les closures ni les données privées.

Le contrôle est exécuté en CI sur un vrai projet généré. Les tests vérifient que le même handler répond côté web et côté MCP, et qu'il refuse :
- une permission OAuth manquante ;
- un acteur non autorisé ;
- un champ supplémentaire ou une entrée invalide ;
- une action \`web\` sans MCP ;
- une action sans autorisation explicite ;
- une action tenant sans identité tenant reliée au serveur.

Les fichiers structurants de la couche ActionRegistry et les routes sont protégés contre les modifications via CodeGraft. Le fichier métier \`app/Actions/business.php\`, lui, peut être autorisé en écriture dans l'interface CodeGraft pour développer de nouvelles fonctionnalités.

## Compatibilité

Les anciens outils métier manuels de \`app/Mcp/business_tools.php\` restent chargés pour ne pas casser les projets existants. Les **nouvelles** fonctionnalités doivent passer par \`app/Actions/business.php\`.

Les installations antérieures à cette version ne sont pas migrées automatiquement. Réinstaller un nouveau ZIP sur un site en production **n'est pas** une procédure de mise à jour.
