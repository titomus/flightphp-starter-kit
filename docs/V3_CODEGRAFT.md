# Flight Starter Kit V3 : CodeGraft natif (expérimental)

## Ce qui est réellement intégré
Le Starter V3 embarque le **cœur CodeGraft** (Policy, Vault, Workspace, Mcp, Admin), copié depuis \`titomus/CodeGraft\`, et non une réécriture de quatre fichiers métier.

La fabrique produit :
- \`app/CodeGraft/\` : moteur CodeGraft embarqué, protégé contre les modifications à distance ;
- \`app/Mcp/CodeGraftFactory.php\` : adaptation à l’application FlightPHP ;
- \`/mcp\` : endpoint OAuth 2.1 existant du Starter, avec 9 commandes \`cg_*\` ;
- \`/admin/mcp\` : administration propriétaire CodeGraft (sans compte admin CodeGraft supplémentaire).

Les outils \`cg_capabilities\`, \`cg_list_files\`, \`cg_search\`, \`cg_read_files\`, \`cg_prepare_patch\`, \`cg_validate_patch\`, \`cg_deploy_patch\`, \`cg_rollback_patch\` et \`cg_history\` proviennent des définitions du véritable moteur CodeGraft. Les outils métier Flight existants restent séparés.

Le code n'expose plus les anciens outils \`dev_*\` sur \`/mcp\` pour les nouvelles installations V3.

## Droits, activation et supervision
1. Installer la V3 sur un domaine de démonstration neuf, HTTPS, document root \`project/public\`.
2. Se connecter à \`/admin/login\`, puis ouvrir **CodeGraft** (\`/admin/mcp\`).
3. Activer le moteur, cocher les opérations de lecture, recherche, création, validation et historique nécessaires.
4. Configurer explicitement les dossiers autorisés. Par exemple :
   - Lecture : \`app/Controllers/Business\`, \`app/Services/Business\`, \`app/Models/Business\`, \`app/Views/business\`, \`public/assets\`.
   - Écriture : mêmes dossiers sauf ceux qu'il n'est pas nécessaire de modifier.
5. Pour permettre un déploiement, conserver \`ATELIER_CODE_UPDATES_ENABLED=true\` dans le \`.env\` privé, valider la permission \`deploy\` dans la politique CodeGraft puis réauthentifier le propriétaire et taper \`AUTORISER\` sur l'écran des déploiements.
6. La connexion ChatGPT reste sur \`https://domaine/mcp\`, avec le scope OAuth \`app:deploy\`. La politique CodeGraft est relue à chaque appel : modifier les autorisations ne change pas le catalogue d'outils.
7. La page affiche les clients OAuth enregistrés, la révocation de leurs jetons, les versions publiées, les restaurations et le journal d'appels CodeGraft (métadonnées uniquement).

**Aucun secret, argument de commande ou contenu source n'est copié dans le journal d'appels.** L'historique des déploiements n'apparaît que lorsque l'opération \`history\` est autorisée dans la politique CodeGraft.

## Coffre privé
Le Vault de CodeGraft **ne doit jamais être publié sur le web**. Par défaut, il est créé à côté du répertoire du projet et porte un nom privé déterministe. Pour un hébergement à structure différente, définir \`CODEGRAFT_VAULT_DIR\` avec un chemin ABSOLU sous un répertoire privé du compte d'hébergement. S'assurer qu'il ne soit accessible par aucun document root, y compris un site voisin du compte cPanel. L'installeur présume que la racine du domaine est strictement \`project/public\`.

Les sauvegardes de fichiers CodeGraft ne sont pas des sauvegardes de la base de données. Les changements de migrations, de framework, d'authentification et de dépendances restent hors du périmètre d'écriture en ligne.

## Protections permanentes
CodeGraft interdit les secrets, .git, vendor, node_modules, son propre moteur, le contrôleur des permissions, OAuth, les routes d'authentification, le cœur du framework et les migrations. Les autres dossiers doivent être explicitement approuvés par le propriétaire. Une autorisation d'écriture PHP reste un pouvoir sensible, et **n'est pas une sandbox**.

Les tentatives d'écriture sont protégées par comparaison SHA-256, contrôles de syntaxe, sauvegarde préalable, confirmation et rollback. Un déploiement complet sur plusieurs fichiers n'est pas atomique : les tests fonctionnels et une sauvegarde base + fichiers restent nécessaires.

## Validation
- \`tests/v3-codegraft-native.php\` : présence du cœur, de la supervision et des protections.
- \`tests/v3-codegraft-flow.php\` : cycle complet du moteur sur un projet jetable (préparation, validation, déploiement, lecture, version, rollback, révocation).
- GitHub Actions V3 : installation web depuis le bundle précompilé, contrôle SHA-256 et test de taille.
- **Reste nécessaire :** essai OAuth et déploiement réels sur un sous-domaine o2switch jetable avant de publier une release stable.

La branche \`feature/v3-codegraft-native\` n'est pas fusionnée dans main et ne doit pas écraser une application déjà installée. Les versions existantes ne sont pas migrées automatiquement.
