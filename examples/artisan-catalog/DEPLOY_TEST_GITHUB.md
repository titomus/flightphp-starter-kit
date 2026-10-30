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
   - `MCP_BEARER_TOKEN=` vide, `MCP_ALLOW_WRITES=false` pour ce premier essai.
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

L'environnement initial contient `MCP_BEARER_TOKEN` vide et
`MCP_ALLOW_WRITES=false` : **l'accès MCP public est volontairement désactivé**
pour cette première publication. Les fonctions d'import CSV restent utilisables
dans l'interface administrateur. Nous brancherons ensuite une authentification
MCP adaptée aux utilisateurs réels.

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
