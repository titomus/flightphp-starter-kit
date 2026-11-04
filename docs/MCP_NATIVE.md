# MCP natif dans Flight Starter Kit V3

> **Documentation courante :** [MCP Native Actions](MCP_NATIVE_ACTIONS.md) pour le registre métier unifié ; [CodeGraft intégré](V3_CODEGRAFT.md) pour l'évolution du code.

## Deux moteurs, une seule URL OAuth

Une application générée avec MCP activé expose \`https://votre-domaine/mcp\`. Le transport utilise OAuth 2.1 avec PKCE et clients enregistrés dynamiquement.

- **MCP métier / Native Actions** : les actions définies dans \`app/Actions/business.php\` sont accessibles en web ET en MCP avec un seul handler, un schéma JSON et un contrôle d'accès commun. Les outils s'appellent \`business_{nom}\`.
- **CodeGraft** : 9 outils \`cg_*\` de lecture et de déploiement de code, avec permissions indépendantes, sauvegarde et restauration.
- **Évolutions** : \`app_request_change\` et outils de suivi des demandes, sous scope \`app:evolve\`.

Ces fonctions n'accordent pas les mêmes droits. Un scope \`app:deploy\` ne donne pas automatiquement accès aux données métier et \`business:write\` ne donne pas accès au code.

## Développer une fonctionnalité

Créer un service métier (par ex. \`app/Services/Business/StockService.php\`) et son contrat dans \`app/Actions/business.php\`. Le moteur produit :
- la route générique \`POST /business/actions/{nom}\` avec session/CSRF ;
- l'outil MCP \`business_{nom}\` avec scope \`business:read\` ou \`business:write\`.

L'administration \`/admin/mcp\` liste les actions et la parité. Lancer \`php app/Console/check_mcp_parity.php\` pour contrôler le contrat. Un contrat sans ACL, schéma strict ou jumeau MCP est rejeté.

Les anciens outils définis dans \`app/Mcp/business_tools.php\` sont encore chargés pour compatibilité, mais ne sont **pas** convertis ni contrôlés automatiquement.

**Limite d'authentification actuelle :** l'OAuth MCP générique associe encore ses jetons à un administrateur, pas à un compte membre. Les fonctionnalités réservées aux utilisateurs/tenants nécessitent une évolution d'identité OAuth liée à ces comptes avant de fonctionner depuis ChatGPT. Ne jamais accepter \`admin_id\`, \`user_id\` ou \`tenant_id\` fournis dans les paramètres d'un outil pour contourner cette limitation.

## CodeGraft et sécurité

CodeGraft n'est pas un moyen de modifier l'authentification, les routes système ou le transport MCP. Le moteur bloque ces chemins et relit les permissions au moment de chaque appel.

Le droit de publier du PHP, \`app:deploy\`, exige le verrou serveur, l'accord explicite du propriétaire et les autorisations CodeGraft. Il ne remplace pas une revue de sécurité, des tests métier ni une sauvegarde de la base.

[Consulter la documentation détaillée des Native Actions](MCP_NATIVE_ACTIONS.md).
