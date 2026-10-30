# Déployer Atelier Catalogue sur o2switch

**Cible :** https://test-github.zootz.fr

## Paramètres vérifiés

- Serveur : `nice.o2switch.net` (SSH sur port 22, API cPanel sur port 2083).
- Identifiant cPanel : `titomus`.
- Application : `/home/titomus/test-github.zootz.fr`.
- Racine web configurée dans cPanel : `/home/titomus/test-github.zootz.fr/public`.
- Code source : branche `demo/artisan-catalog` du dépôt `titomus/flightphp-starter-kit`.

Le workflow de build `.github/workflows/build-artisan-demo.yml` fonctionne :
il exécute le véritable installateur FlightPHP, installe Composer, construit
les fichiers frontend avec npm, migre SQLite et teste le catalogue + MCP.
L'archive est publiée dans les artifacts de GitHub Actions.

## Solution immédiate sans jeton API : installation via cPanel

Si l'outil de création des jetons d'API de cPanel est inaccessible, **ne pas
utiliser le workflow SSH** : il attend un jeton API pour autoriser l'IP éphémère
du runner GitHub. FTPS chiffré exige également une exception d'IP sur o2switch.

Le workflow `Build artisan mini SaaS` fournit une archive prête à extraire :

1. Ouvrir **Actions > Build artisan mini SaaS**, sélectionner la dernière
   exécution réussie et télécharger l'artifact `atelier-catalogue-generated`.
2. Dans **cPanel > Gestionnaire de fichiers**, aller dans
   `/home/titomus/test-github.zootz.fr`. Vérifier que le dossier ne contient
   rien à conserver avant de continuer. **Ne pas supprimer d'autres fichiers.**
3. Téléverser l'archive ZIP et utiliser **Extract** dans ce même répertoire.
   Si l'extraction crée un sous-dossier supplémentaire, déplacer uniquement
   son contenu dans `test-github.zootz.fr` pour obtenir
   `test-github.zootz.fr/app/`, `vendor/`, `public/`, etc.
   La racine web configurée reste `test-github.zootz.fr/public`.
4. Dans le Gestionnaire de fichiers, activer **Afficher les fichiers masqués**.
   Vérifier que `public/.htaccess` et `.env.example` sont bien présents.
5. Copier `.env.example` en `.env` (hors du dossier `public`) et configurer :
   - `APP_URL=https://test-github.zootz.fr`
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `ADMIN_EMAIL=<adresse privée>`
   - `ADMIN_PASSWORD=<mot de passe fort, jamais publié dans GitHub>`
   - `MCP_OAUTH_CLIENT_ID=`, `MCP_OAUTH_CLIENT_SECRET=` et `MCP_OAUTH_REDIRECT_URI=` vides pour ce premier essai.
   Conserver `DB_DATABASE=app/storage/database.sqlite` pour SQLite.
6. Ouvrir **cPanel > Terminal** et exécuter :
   ```bash
   cd /home/titomus/test-github.zootz.fr
   php -v
   php -m | grep -Ei 'pdo_sqlite|mbstring'
   php app/Console/console.php migrate
   php app/Console/console.php seed
   ```
7. Vérifier `https://test-github.zootz.fr/api/health` puis
   `https://test-github.zootz.fr/admin/login`. Se connecter et ouvrir
   `/catalog` pour tester le CSV d'exemple.

L'archive générée contient les dépendances Composer et les assets frontend,
mais **pas la base SQLite temporaire issue des tests GitHub**, ni de fichier
`.env` privé. Les fichiers cachés nécessaires sont inclus.

Ce déploiement manuel évite d'avoir à publier des mots de passe serveur
dans GitHub. Il sert de premier lancement ; nous pourrons ensuite mettre
en place un déploiement automatique dès que la méthode d'authentification
cPanel sera disponible ou qu'une alternative sécurisée sera choisie.

## Mettre à jour une installation cPanel déjà fonctionnelle

L'application peut déjà être en service sur ce sous-domaine. Dans ce cas :

1. Vérifier la dernière exécution GitHub Actions réussie de `Build artisan mini SaaS`,
   puis télécharger son artifact `atelier-catalogue-generated`.
2. Sauvegarder **avant l'extraction** `/home/titomus/test-github.zootz.fr/.env`
   et le fichier `app/storage/database.sqlite` (SQLite). Une copie de la base
   doit être faite à froid ou via une sauvegarde SQLite cohérente.
3. Dans cPanel, extraire l'archive à la racine de l'application en remplaçant
   uniquement les fichiers de code existants. L'archive ne contient **ni .env,
   ni fichier SQLite de tests**. Ne jamais supprimer `app/storage/`.
4. Vérifier que `app/Mcp/OAuthStore.php`, `app/Controllers/McpOAuthController.php`
   et `public/mcp.php` figurent bien dans les fichiers mis à jour.
5. Dans le Terminal cPanel :

   ```bash
   cd /home/titomus/test-github.zootz.fr
   php app/Console/console.php migrate
   ```

6. Confirmer que `/api/health`, `/admin/login` et `/catalog` fonctionnent toujours,
   puis ouvrir `/.well-known/oauth-authorization-server`.

Le MCP est désactivé tant que ses trois paramètres OAuth ne sont pas renseignés
dans `.env`. Le compte administrateur existant et ses données restent inchangés.

### Préparer la connexion ChatGPT (lecture seule)

Dans **cPanel → Terminal**, générer deux valeurs aléatoires indépendantes avec :

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Les inscrire uniquement dans le `.env` hébergé :

```dotenv
MCP_OAUTH_CLIENT_ID=<première_valeur>
MCP_OAUTH_CLIENT_SECRET=<seconde_valeur>
MCP_OAUTH_REDIRECT_URI=<callback_HTTPS_exact_fourni_par_ChatGPT>
```

La callback OAuth est fournie par l'interface de création du plugin personnalisé
ChatGPT. Ne pas l'inventer. Dans ChatGPT, sélectionner l'authentification OAuth
avec identifiants clients statiques. L'endpoint MCP est
`https://test-github.zootz.fr/mcp.php`.

Les paramètres ne doivent jamais être copiés dans une conversation ou dans Git.
Les trois outils proposés sont en lecture seule : liste, aperçu et informations
de l'application. Un compte admin doit approuver la connexion explicitement.

## Avant le premier déploiement

Créer **cinq secrets** dans le dépôt GitHub, via
`Settings > Secrets and variables > Actions > New repository secret` :

| Nom | Valeur attendue |
| --- | --- |
| `O2_CPANEL_API_TOKEN` | Jeton généré dans cPanel > Gérer les jetons d'API |
| `O2_SSH_PRIVATE_KEY` | Clé SSH privée dédiée à GitHub Actions, avec retours à la ligne complets |
| `O2_SSH_KNOWN_HOSTS` | Ligne SSH `nice.o2switch.net ...` de la clé hôte du serveur, empreinte vérifiée |
| `ARTISAN_ADMIN_EMAIL` | Adresse de connexion à l'administration de la démo |
| `ARTISAN_ADMIN_PASSWORD` | Mot de passe aléatoire de 20 à 128 caractères (lettres, chiffres et `_!@#%+=:.,-`) |

**Ne partager aucun de ces secrets dans ChatGPT ni les commiter dans Git.**

Clé SSH : créer une paire **dédiée**, de préférence Ed25519, puis importer et
autoriser sa clé publique dans cPanel > Accès SSH > Gérer les clés SSH.
Placer la clé privée seulement dans le secret GitHub `O2_SSH_PRIVATE_KEY`.
Ne pas réutiliser une clé personnelle servant à d'autres services.

Clé de l'hôte : l'entrée de `O2_SSH_KNOWN_HOSTS` ressemble à :

```text
nice.o2switch.net ssh-ed25519 AAAA...clé_publique_serveur...
```

La clé peut être relevée avec `ssh-keyscan -t ed25519 nice.o2switch.net`,
mais **l'empreinte doit être vérifiée par un canal de confiance** avant
de l'accepter. Une sortie `ssh-keyscan` seule n'est pas une vérification.

API o2switch : la documentation officielle détaille
`/execute/SshWhitelist/list`, `add` et `remove` :
https://faq.o2switch.fr/cpanel/outils/exception-parefeu/

## Déploiement : fonctionnement

Workflow : `.github/workflows/deploy-artisan-o2switch.yml`.

1. Vérification de la présence des cinq secrets.
2. Génération complète de l'application et tests métier locaux.
3. Détection de l'IP publique du runner GitHub et ajout temporaire à la liste
   SSH chez o2switch avec le jeton API cPanel.
4. Connexion SSH par clé, avec vérification de la clé d'hôte épinglée.
5. Refus d'écraser un répertoire existant non géré.
6. Création initiale de `.env` (si absent) avec les secrets d'administration.
7. Copie rsync **sans suppression**, préservant `.env`, `app/storage/` et SQLite.
8. Migrations, création du compte administrateur et contrôle HTTPS.
9. Retrait des seules exceptions SSH créées pour le runner (directions in et out),
   même en cas d'erreur ultérieure.

La base SQLite, les fichiers privés et les autres autorisations SSH ne sont pas
supprimés. La base du runner n'est jamais envoyée en production.

L'environnement initial ne contient aucune identité OAuth MCP : **l'accès MCP reste indisponible** jusqu'à configuration explicite. L'import CSV reste accessible via le BackOffice authentifié.

## Déclenchement volontaire

Ce workflow n'est PAS lancé à chaque commit de code. Il démarre :

- sur une demande manuelle `workflow_dispatch`, si GitHub expose l'action ;
- ou lors de la création/modification explicite de
  `examples/artisan-catalog/deploy-request.txt` sur la branche
  `demo/artisan-catalog`.

Ce fichier de déclenchement ne doit être créé **qu'après** configuration des
secrets. Il peut être ajouté depuis cette conversation via le connecteur GitHub.

## Points à surveiller

- Sur o2switch, le dossier `public` doit être vide pour la première installation.
  Sinon le workflow s'arrête sans toucher aux fichiers existants.
- Le CLI PHP distant doit être en version 8.1+ avec `pdo_sqlite` et `mbstring`.
- Le domaine doit servir HTTPS avec un certificat valide.
- Le serveur MCP généré est toujours un prototype, sans authentification
  multi-utilisateur ni transport Streamable HTTP complet.
- Les changements futurs du starter kit restent dans une pull request, séparée
  de la branche de démonstration.

**État au 30/10/2026 :** workflow prêt et écrit dans GitHub ; aucun déploiement
sur o2switch tant que les secrets ne sont pas configurés et le déclenchement demandé.
