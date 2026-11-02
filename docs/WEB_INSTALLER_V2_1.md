# Flight Starter Kit 2.1 : installation par formulaire

État : **bêta intégrée dans main**, à tester sur un hébergement de démonstration avant tout client réel.

## Une seule entrée

Le fichier web-installer/install.php se dépose dans le dossier public/ d'un site neuf. Il permet de créer un projet FlightPHP en quelques étapes, **sans Composer, Node.js ou Git côté hébergeur**.

Ce fichier est un lanceur. L'installation elle-même réutilise le générateur déjà existant dans installer/FlightStarterInstaller.php. Les dépendances PHP et les assets Vite sont compilés sur GitHub Actions, puis regroupés dans une distribution versionnée.

## Les choix du formulaire

Type de projet :

- SaaS : thème SaaS, membres suggérés, MCP suggéré.
- Outil métier : thème de gestion, membres suggérés, MCP suggéré.
- Blog / média : thème éditorial, membres et MCP facultatifs.
- Site vitrine : thème éditorial par défaut, membres et MCP facultatifs.

Options indépendantes : comptes membres, MCP, Stripe. Stripe nécessite des comptes membres. MCP nécessite le back-office propriétaire, installé par défaut. La base SQLite est actuellement le seul choix sûr pour l'installateur web, en raison des migrations existantes.

**Important :** les profils blog et vitrine génèrent pour l'instant une application et un habillage adaptés, pas un système CMS complet. Le profil SaaS ne crée pas automatiquement une isolation multi-entreprises métier. Ces modules restent à développer dans le Starter Kit générique.

## Architecture

- web-installer/install.php : authentification initiale, formulaire, téléchargement de GitHub Release, SHA-256 obligatoire, extraction ZIP sécurisée et verrouillage final.
- installer/WebInstallRecipe.php : validation stricte des entrées et transformation des options en configuration du générateur FlightPHP.
- installer/WebInstallEngine.php : génération en répertoire privé, copie des dépendances précompilées, création de la base, migrations, compte propriétaire et publication.
- scripts/build-web-distribution.sh : GitHub Actions construit un vendor PHP compatible avec les options et trois déclinaisons Vite compilées.
- tests/web-installer-smoke.php : tests d'options et d'installation de bout en bout.
- .github/workflows/flight-web-installer-v2-1.yml : tests, paquet ZIP et somme de contrôle ; publication en prerelease sur tag validé.

## Sur un hébergement o2switch

1. Créer un domaine HTTPS avec document root pointant sur un dossier vide de type « mon-projet/public ». Le parent du dossier doit pouvoir être écrit par PHP. **Ne pas pointer le domaine sur le parent**, car il héberge les fichiers secrets, la base et vendor.
2. Déposer le fichier web-installer/install.php sous le nom « public/install.php », sans aucun autre fichier du Starter Kit.
3. Ouvrir l'adresse HTTPS suivie de /install.php.
4. Avec le gestionnaire de fichiers de l'hébergement, ouvrir le secret créé automatiquement dans le parent, sous le nom .flight-install-access. Le coller dans le formulaire de déverrouillage. Il n'est jamais affiché sur une page publique.
5. Choisir le profil, les modules, le domaine, l'email propriétaire et un mot de passe unique de 16 caractères minimum.
6. Fournir un jeton GitHub à accès lecture temporaire sur le dépôt privé, le nom du tag de release et le SHA-256 exact du ZIP officiel.
7. Lancer l'installation. Elle télécharge la release depuis api.github.com via HTTPS, vérifie l'archive, génère l'application et copie vendor ainsi que les assets Vite.
8. Elle applique les migrations SQLite et crée le compte propriétaire. Le mot de passe en clair servant au seed est ensuite effacé des fichiers générés ; seule son empreinte sécurisée subsiste en base.
9. L'application est activée à la fin, le script d'installation est supprimé si les droits le permettent, et un verrou privé interdit toute réinstallation.
10. Se connecter à /admin/login. Si MCP a été sélectionné, connecter ChatGPT sur /mcp via OAuth. Les écritures de code à distance restent désactivées par défaut.

## Distribution GitHub

La CI fabrique une archive nommée « flight-starter-runtime.zip » contenant les répertoires installer/, runtime/vendor/ et runtime/build/saas/, outil_metier/, blog/. Elle génère également le fichier « flight-starter-runtime.zip.sha256 » et publie les artefacts de test dans GitHub Actions.

Une **branche de développement ou un artefact Actions ne suffisent pas** : pour que le bootstrap télécharge depuis l'API Release GitHub, il faut une release publiée avec le ZIP et une empreinte officielle. Le premier tag de bêta utilisera une version de type v2.1.0-beta.1 ; la version v2.1.0 stable attend les tests réels.

### Vérifications après installation

- /api/health fonctionne sans erreur PHP.
- /admin/login permet de se connecter avec le propriétaire créé.
- Le CSS et le JS sont chargés depuis le manifeste Vite de public/build.
- .env, la base SQLite et vendor restent hors du dossier public.
- /mcp et les métadonnées OAuth sont disponibles seulement si MCP activé.
- Le code peut être déployé à distance uniquement si l'administrateur active explicitement ATELIER_CODE_UPDATES_ENABLED.

## Limites actuelles de la version bêta

- PHP 8.2 minimum, cURL, ZIP, OpenSSL, FileInfo, mbstring et PDO SQLite requis.
- L'installation se fait dans une requête HTTP longue ; certains mutualisés imposent un découpage en plusieurs étapes HTTP avec reprise. Ce découpage n'est pas encore implémenté.
- Le paquet assemble une sélection étendue de dépendances PHP. Il est plus volumineux qu'un package minimal.
- Le système de migrations SQLite, les modules métier et les évolutions multi-utilisateurs par MCP doivent encore évoluer pour les cas clients avancés.
- Aucune installation ne doit écraser une application déjà existante.
- En cas d'échec technique, inspecter les dossiers privés .flight-stage-* ou .flight-download-* et les nettoyer de manière contrôlée, sans supprimer d'autres dossiers.
- Les changements du frontend qui nécessitent Node.js doivent être recompilés sur GitHub ; les fichiers CSS et JavaScript métier accessibles au MCP n'en ont pas besoin.

## Développement et validation

Commandes de test :

    php tests/web-installer-smoke.php
    bash scripts/build-web-distribution.sh
    mkdir -p /tmp/flight-web-test
    unzip -q dist/flight-starter-runtime.zip -d /tmp/flight-web-test
    php tests/web-installer-smoke.php /tmp/flight-web-test

Objectif de validation commerciale : deux installations indépendantes sur domaines o2switch vierges, avec création du propriétaire et évolution d'une fonctionnalité via MCP, sans terminal après installation.
