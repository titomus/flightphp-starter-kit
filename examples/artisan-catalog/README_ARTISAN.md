# Atelier Catalogue : mini-SaaS artisan

Application de demonstration generee depuis le FlightPHP Starter Kit. Ce repertoire est un projet autonome avec SQLite, BackOffice, catalogue, import CSV et outils MCP.

## Lancement local

Prerequis : PHP 8.1+, Composer, extension PDO SQLite.

```bash
composer install
cp .env.example .env
php app/Console/console.php migrate
php -S localhost:8000 -t public public/router.php
```

**Avant la premiere creation de compte admin** : remplacer la valeur `ADMIN_PASSWORD` dans `.env` par un mot de passe fort et l'adresse `ADMIN_EMAIL` par votre adresse. Puis executer :

```bash
php app/Console/console.php seed
```

Se connecter sur http://localhost:8000/admin/login et ouvrir http://localhost:8000/catalog.

**Ne pas exposer cette demo sur Internet telle quelle** : elle est mono-tenant, n'inclut pas de provisionnement client, et le serveur MCP est experimental.

## Fonctionnalites

- Affichage du catalogue SQLite (`catalog_items`).
- Import CSV en deux temps : previsualisation, erreurs, confirmation explicite.
- Creation ou mise a jour des references par SKU.
- Import d'un exemple dans `example-data/references.csv`.
- MCP : `catalog_list`, `catalog_preview_import`, `catalog_commit_import`.
- Meme service PHP `App\Services\CatalogService` pour le web et le MCP.

## MCP privé : connexion ChatGPT en lecture seule

Le MCP utilise désormais OAuth (code d'autorisation avec PKCE S256) et **non plus**
le jeton partagé `MCP_BEARER_TOKEN`. Les paramètres suivants doivent être définis
dans `.env` pour l'activer :

```dotenv
MCP_OAUTH_CLIENT_ID=identifiant-client-prive
MCP_OAUTH_CLIENT_SECRET=secret-client-prive-de-64-caracteres-ou-plus
MCP_OAUTH_REDIRECT_URI=https://URL-EXACTE-DE-RAPPEL-CHATGPT
```

Les valeurs doivent être configurées sur le serveur, jamais dans le dépôt Git.
Pour obtenir un identifiant et un secret aléatoires, exécuter deux fois
`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` dans le Terminal cPanel.
Ne communiquer ces secrets à personne et ne pas les afficher dans des captures.

**Le callback doit être l'URL exacte indiquée par le formulaire de connexion OAuth
du plugin ChatGPT**, pas une adresse devinée. L'application refuse les redirections
différentes de celle enregistrée.

Les métadonnées publiques ne contiennent aucun secret :
- `/.well-known/oauth-authorization-server` : découverte OAuth.
- `/.well-known/oauth-protected-resource/mcp.php` : ressource protégée.
- `/oauth/authorize` : écran d'autorisation réservée à un admin connecté.
- `/oauth/token` : échange de jeton et renouvellement.
- `/mcp.php` : endpoint MCP Streamable HTTP (réponses JSON et HTTP 405 pour GET SSE).

Dans ChatGPT : Plugins > ajouter un serveur MCP personnalisé, URL
`https://test-github.zootz.fr/mcp.php`, authentification OAuth avec
les identifiants statiques ci-dessus, puis effectuer la connexion. Avant de
valider, se connecter à `/admin/login` dans le navigateur et autoriser
explicitement l'accès sur l'écran OAuth.

**Outils MCP disponibles :** `app_info`, `catalog_list`,
`catalog_preview_import`. Aucun outil d'écriture MCP n'est publié :
l'importation réelle se fait uniquement via le BackOffice avec confirmation.
Les jetons d'accès expirent au bout d'une heure et les jetons de renouvellement
sont révoqués après rotation, avec durée maximale de 30 jours.

La démo reste mono-tenant. Avant tout usage sur plusieurs comptes clients,
il faudra ajouter un véritable fournisseur d'identité multi-tenant, une
séparation des ressources, des scopes et des limites d'utilisation.

## Architecture

`app/Services/CatalogService.php` est la couche metier unique. `CatalogCsv.php` sait lire un CSV ; `CatalogController.php` gere l'interface et la session de confirmation ; `app/Mcp/tools.php` expose explicitement les memes operations. L'agent de developpement GitHub conserve un autre perimetre d'autorisations et n'est pas accessible depuis le serveur MCP metier.

## Origine

Genere depuis la branche `demo/artisan-catalog` du depot `titomus/flightphp-starter-kit` avec la recette `examples/artisan-catalog/build.php`. Aucun serveur de production n'est cree par GitHub Actions.
