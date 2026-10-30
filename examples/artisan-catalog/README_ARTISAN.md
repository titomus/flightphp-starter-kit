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

## MCP : mode developpement uniquement

Ajouter dans `.env` :

```env
MCP_BEARER_TOKEN=REMPLACER_PAR_UN_SECRET_ALEATOIRE_DE_64_CARACTERES_HEXA
MCP_ALLOW_WRITES=false
```

Generer un jeton avec `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. L'endpoint de demonstration est `POST /mcp.php`, avec en-tete `Authorization: Bearer ...`.

Les outils de lecture et de previsualisation sont disponibles avec le jeton. L'outil d'import n'est activable qu'avec `MCP_ALLOW_WRITES=true` et exige `confirmed=true`. Cette double protection **ne remplace pas** une authentification utilisateur et des permissions par tenant ; ne l'activer que dans un environnement de test isole.

L'implementation actuelle est un prototype de transport JSON-RPC MCP. Il faudra parfaire le support Streamable HTTP et le modele OAuth pour connecter ce logiciel a des clients MCP externes en production.

## Architecture

`app/Services/CatalogService.php` est la couche metier unique. `CatalogCsv.php` sait lire un CSV ; `CatalogController.php` gere l'interface et la session de confirmation ; `app/Mcp/tools.php` expose explicitement les memes operations. L'agent de developpement GitHub conserve un autre perimetre d'autorisations et n'est pas accessible depuis le serveur MCP metier.

## Origine

Genere depuis la branche `demo/artisan-catalog` du depot `titomus/flightphp-starter-kit` avec la recette `examples/artisan-catalog/build.php`. Aucun serveur de production n'est cree par GitHub Actions.
