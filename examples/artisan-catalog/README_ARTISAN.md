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

## Connexion à ChatGPT sans formulaire technique (OAuth DCR)

Le serveur MCP utilise l'inscription dynamique des clients OAuth (**DCR**) :
l'utilisateur n'a pas besoin de créer ni de saisir de Client ID, Client Secret
ou callback URL. ChatGPT inscrit lui-même un client public, puis l'administrateur
se connecte normalement et approuve les autorisations demandées.

- URL MCP : `https://test-github.zootz.fr/mcp.php`
- Authentification : OAuth, méthode d'inscription **Dynamic Client Registration (DCR)**
- URL de découverte : `/.well-known/oauth-authorization-server`
- Enregistrement : `POST /oauth/register`
- Consentement : `/oauth/authorize`
- Jetons et renouvellement : `/oauth/token`

Seuls les callbacks HTTPS de `chatgpt.com` sur des chemins autorisés sont
acceptés dans cette démonstration. L'administrateur doit approuver l'accès.
Les codes d'autorisation sont liés à PKCE S256, expirent après cinq minutes
et sont à usage unique ; les jetons sont hachés dans SQLite.

### Autorisations et outils MCP

| Permission | Outils |
| --- | --- |
| `catalog:read` | `catalog_list`, `catalog_preview_import` |
| `catalog:write` | `catalog_commit_import` (nécessite `confirmed=true`) |
| `app:evolve` | `app_request_change`, `app_get_change_request`, `app_list_change_requests` |

`app_info` est disponible pour tout client authentifié.

L'importation utilise exactement le même `CatalogService` que l'interface web.
L'opération `app_request_change` **enregistre seulement une demande d'évolution**
dans la base de données avec son identifiant. Ce n'est pas encore un développeur
automatique. Un moteur de développement séparé devra récupérer la demande,
travailler dans un bac à sable, ouvrir une revue et proposer une mise en ligne.

**Aucun compte GitHub n'est nécessaire pour l'artisan.** Le code source
est administré par le service qui fournit le SaaS, et non par son utilisateur.

### Sécurité et limites de cette démonstration

Il s'agit d'une **démo mono-tenant**. Ne pas l'ouvrir à des clients externes :
les règles de quotas, de révocation des connexions, d'audit approfondi, de
validation transactionnelle des imports, de rate limiting DCR et d'isolation
multi-tenant restent à renforcer avant toute commercialisation.

Le MCP public ne contient jamais d'outil d'exécution de commandes, d'édition
arbitraire de fichiers, ni d'accès aux identifiants GitHub ou au déploiement.

## Architecture

`app/Services/CatalogService.php` est la couche metier unique. `CatalogCsv.php` sait lire un CSV ; `CatalogController.php` gere l'interface et la session de confirmation ; `app/Mcp/tools.php` expose explicitement les memes operations. L'agent de developpement GitHub conserve un autre perimetre d'autorisations et n'est pas accessible depuis le serveur MCP metier.

## Origine

Genere depuis la branche `demo/artisan-catalog` du depot `titomus/flightphp-starter-kit` avec la recette `examples/artisan-catalog/build.php`. Aucun serveur de production n'est cree par GitHub Actions.
