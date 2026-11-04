# Flight Starter Kit

**De l'idée à une application web installée. Avec FlightPHP, une installation simple et CodeGraft intégré.**

[![Validation V3](https://github.com/titomus/flightphp-starter-kit/actions/workflows/flight-v3-one-zip.yml/badge.svg?branch=main)](https://github.com/titomus/flightphp-starter-kit/actions/workflows/flight-v3-one-zip.yml)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![FlightPHP](https://img.shields.io/badge/Framework-FlightPHP-0d9488)
![Statut](https://img.shields.io/badge/Statut-b%C3%AAta%20technique-orange)

> **Un seul ZIP. Un installateur web. Votre application et son atelier IA.** Pas de Composer, de Node.js, de compte GitHub ni de clé GitHub à renseigner sur l'hébergement.

## Télécharger la version installable

### [Télécharger Flight Starter Kit V3 (ZIP autonome)](https://github.com/titomus/flightphp-starter-kit/releases/download/v3.0.0-beta.2/flight-starter-v3.0.0-beta.2.zip)

[Page de la version V3 et somme de contrôle SHA-256](https://github.com/titomus/flightphp-starter-kit/releases/tag/v3.0.0-beta.2) · [Toutes les versions](https://github.com/titomus/flightphp-starter-kit/releases)

**Important :** utilisez le ZIP de la Release, pas `Code → Download ZIP`, qui ne contient que les sources. La branche `main` est la référence officielle du développement. La publication V3 est une **bêta de test** et ne doit pas écraser un projet existant.

## À quoi sert Flight Starter Kit ?

Vous avez besoin d'un SaaS, d'un outil métier, d'un blog ou d'un site vitrine. Flight Starter Kit fournit une base PHP indépendante, avec les fonctions transversales déjà prévues, puis un atelier CodeGraft pour demander des évolutions à ChatGPT.

| Projet | Base fournie | À construire ensuite |
|---|---|---|
| **SaaS** | Membres, authentification, admin, intégration Stripe optionnelle | Votre produit, les règles métier, l'isolation multi-entreprises si nécessaire |
| **Outil métier** | MVC, routes, contrôleurs, back-office, MCP | Stocks, devis, factures, clients et autres besoins métier |
| **Blog / média** | Preset éditorial, SEO et structure PHP | Éditeur, gestion éditoriale, contenus et fonctions CMS |
| **Site vitrine** | Pages et présentation éditoriale | Contenus et composants propres au site |

**Ce n'est pas un CMS fini ni un SaaS multi-tenant prêt à vendre.** C'est une fondation, pas une application métier complète.

### Ce qui est déjà intégré

- **FlightPHP / MVC** : routes web/API, contrôleurs, services, vues, middleware.
- **Administration et identité** : compte propriétaire, authentification, protection CSRF et limitations de requêtes.
- **Fonctions web** : SEO, email/SMTP, tâches, migrations et générateurs.
- **Commercial** : comptes membres, Stripe Checkout et webhooks en option.
- **Interface** : Vite, Tailwind, HTMX, Alpine.js et presets pour SaaS, métier ou éditorial.
- **Installation autonome** : runtime PHP et assets précompilés dans la Release.
- **CodeGraft natif** : développement piloté par IA, permissions configurables, validation, historique et restauration.
- **MCP Native Actions** : une action métier déclarée une fois génère son endpoint web et son outil MCP, avec la même autorisation et le même service.

## Installation sur o2switch / hébergement PHP

**Prérequis :** PHP 8.2+, HTTPS, PDO SQLite, mbstring, ZIP, OpenSSL, FileInfo et droits d'écriture PHP adaptés.

1. Télécharger le **ZIP V3** ci-dessus et le décompresser dans un **dossier de projet neuf et vide**.
2. Faire pointer le document root du domaine vers **`votre-projet/public/`**, jamais sur la racine du projet.
3. Ouvrir **`https://votre-domaine.fr/install.php`**.
4. Lire le secret de déverrouillage dans **`.flight-install-access`** à la racine privée du projet.
5. Dans le formulaire, choisir le type de projet, ses options, le domaine HTTPS et le compte propriétaire.
6. Terminer l'installation, puis se connecter sur **`/admin/login`**.
7. Si l'option MCP a été activée, ouvrir **`/admin/mcp`** pour configurer CodeGraft.

L'installateur vérifie son paquet local, génère l'application, prépare la base SQLite et crée le compte propriétaire. Il refuse d'écraser une application déjà installée et verrouille l'installation après succès.

[Guide d'installation détaillé](docs/V3_INSTALLATION.md) · [Architecture CodeGraft intégrée](docs/V3_CODEGRAFT.md)

## CodeGraft : développer votre application depuis ChatGPT

**Une seule connexion MCP sur `https://votre-domaine.fr/mcp`**, protégée par OAuth. Le moteur CodeGraft embarqué expose neuf commandes stables :

| Explorer | Modifier et sécuriser |
|---|---|
| `cg_capabilities` : capacités et droits | `cg_prepare_patch` : préparer une modification multifichiers |
| `cg_list_files` : parcourir les fichiers | `cg_validate_patch` : vérifier avant publication |
| `cg_search` : chercher dans le code | `cg_deploy_patch` : publier après autorisation |
| `cg_read_files` : lire les fichiers autorisés | `cg_rollback_patch` : restaurer |
| | `cg_history` : voir les versions |

L'interface **Administration → CodeGraft** sert à définir les dossiers en lecture et écriture, activer chaque opération, gérer les clients OAuth, consulter les déploiements et le journal d'activité.

**Les permissions sont relues à chaque appel MCP**, sans recharge du connecteur ChatGPT. L'écriture est désactivée par défaut. Un déploiement exige l'accord du propriétaire, la permission OAuth correspondante et le verrou serveur `ATELIER_CODE_UPDATES_ENABLED=true` dans le `.env` privé.

Les secrets, le framework central, l'authentification, les migrations et CodeGraft lui-même restent protégés. L'écriture distante de PHP reste sensible : les contrôles syntaxiques et le rollback **ne remplacent pas** les tests fonctionnels ni la sauvegarde de la base.

## MCP Native Actions : une fonctionnalité, deux interfaces

Chaque nouvelle action métier se déclare dans `app/Actions/business.php` : nom, paramètres, permissions, traitement et identité/tenant. Le Starter génère automatiquement les deux accès :

- **Web :** `POST /business/actions/{nom}`, avec session authentifiée et protection CSRF.
- **MCP :** `business_{nom}`, accessible avec les scopes OAuth métier adaptés.

Les deux appellent la **même fonction PHP**, sans dupliquer la logique dans `app/Mcp/tools.php`. L'administration CodeGraft affiche les actions et leur statut de parité ; la commande `php app/Console/check_mcp_parity.php` valide leurs contrats.

Ce moteur ne transforme pas automatiquement des routes PHP arbitraires : pour conserver la parité, les nouvelles fonctionnalités doivent être définies dans le registre. **Actuellement l'OAuth MCP du Starter authentifie les administrateurs uniquement**. Les actions propres aux membres ou à une entreprise demandent encore une authentification MCP membre/tenant liée côté serveur ; faute de liaison sûre, elles sont refusées.

[Guide d'utilisation de MCP Native Actions](docs/MCP_NATIVE_ACTIONS.md)

## Quelle différence avec CodeGraft autonome ?

| | **Flight Starter Kit** | **[CodeGraft](https://github.com/titomus/CodeGraft)** |
|---|---|---|
| Pour qui ? | Nouvelle application à créer | Site PHP ou WordPress existant |
| Framework | FlightPHP fourni | Aucun framework imposé |
| Installation | ZIP + installateur web | Plugin WordPress ou paquet PHP autonome |
| MCP et évolutions | CodeGraft déjà intégré | CodeGraft ajouté à votre site |

**Inutile d'installer CodeGraft une deuxième fois** sur une application créée par le Starter Kit V3.

## État du projet

- **Source de vérité :** [branche `main`](https://github.com/titomus/flightphp-starter-kit/tree/main).
- **Distribution :** [Releases officielles](https://github.com/titomus/flightphp-starter-kit/releases) avec archive installable et SHA-256.
- **Tests :** génération, installation autonome, OAuth, moteur CodeGraft et cycle patch → déploiement → rollback automatisés dans GitHub Actions.
- **Limites :** la V3 reste une bêta à valider en conditions réelles sur o2switch ; les mises à niveau d'installations V2/V3 préexistantes et les migrations de base ne sont pas automatisées.

[Documentation V3](docs/V3_INSTALLATION.md) · [Sécurité et intégration CodeGraft](docs/V3_CODEGRAFT.md) · [Releases](https://github.com/titomus/flightphp-starter-kit/releases)

---

*Un outil pour créer des produits, pas collectionner les fichiers de configuration.*
