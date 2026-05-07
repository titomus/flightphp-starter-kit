# Suggestions rapides

Liste de chantiers interessants a traiter bientot. L'idee est de privilegier les changements courts qui rendent le starter plus fiable, plus agreable a installer et plus simple a maintenir.

## Priorite haute

### 1. Exposer `npm.auto_build` dans `installer/config.php`

Aujourd'hui `FlightStarterInstaller` lit `config['npm']['auto_build']`, mais la cle n'est pas presente dans la config par defaut. Ajouter une section explicite evite l'effet cache.

Proposition:

```php
'npm' => [
    'auto_build' => true,
],
```

### 2. Ajouter une option CLI `--no-npm`

On a `--no-composer`, mais pas encore l'equivalent frontend. Ce serait utile en CI, en debug ou quand Node n'est pas installe.

Comportement attendu:

```bash
php install-flight-starter.php --no-npm
```

La generation garderait `package.json`, `resources/`, `vite.config.js`, etc., mais ignorerait `npm install` et `npm run build`.

### 3. Ajouter une commande de verification apres install

Une commande console type `doctor` pourrait valider les points qui nous ont deja coute du temps:

- `vendor/autoload.php` existe
- `public/manifest.json` existe
- manifest contient une entree CSS
- les assets references existent
- `.env` existe
- SQLite est accessible
- routes `/api/health`, `/sitemap.xml`, `/robots.txt` repondent si serveur lance

### 4. Generer un test smoke minimal

Le dossier `tests/` existe, mais il reste vide. Ajouter un premier smoke test apporterait de la confiance.

Exemples utiles:

- `vite_tags()` rend bien `/assets/...`
- `SeoManager::robots()` contient le sitemap
- `View::render()` ne double pas le layout
- `Config` charge les fichiers attendus

## Priorite moyenne

### 5. Ajouter une commande `install:finish`

Une fois l'app generee, une commande pourrait enchainer:

```bash
copy .env.example .env
php app/Console/console.php migrate
php app/Console/console.php seed
```

Ou au moins afficher un resume clair des etapes restantes selon ce qui existe deja.

### 6. Rendre les dependances optionnelles selon features - fait

Le `composer.json` genere est maintenant conditionnel pour Google, GitHub, Stripe et Symfony Mailer. Reste a surveiller si on veut aller plus loin et ne plus generer certains services quand leur feature est desactivee.

- Stripe seulement si billing active
- Google seulement si Google OAuth actif
- GitHub seulement si GitHub OAuth actif
- Mailer toujours present ou conditionnel selon choix mail

### 7. Ajouter des presets plus orientes produit

Les trois presets actuels posent la base. On pourrait ajouter:

- `minimal` pour outil interne ultra sobre
- `crm` pour dashboards denses
- `marketplace` pour catalogue/offres
- `docs` pour documentation produit

### 8. Ameliorer le CRUD generator - premiere passe faite

Le CRUD genere maintenant index/create/edit/update/delete, une migration, pagination, flash messages, validation `required` et plusieurs types de champs. Restent des pistes pour aller plus loin:

- validation par type de champ
- recherche et filtres
- tri colonnes
- vues show/detail
- contraintes uniques
- upload de fichiers

### 9. Ajouter une config SEO par pages

`SeoManager::forPage()` lit deja `seo.pages.*`, mais la config generee pourrait fournir un exemple clair:

```php
'pages' => [
    'home' => [
        'title' => 'Accueil',
        'description' => '...',
    ],
],
```

### 10. Ajouter une page erreur propre

Pour une preview plus professionnelle:

- 404 layout serveur
- 500 en mode production
- rendu JSON pour `/api/*`

### 11. Ajouter des composants HTMX/Alpine prets a l'emploi

HTMX et AlpineJS sont maintenant installables via `frontend.htmx` et `frontend.alpine`. Pour rendre l'option tout de suite utile, on pourrait ajouter quelques composants generes:

- recherche backoffice avec `hx-get` et debounce
- pagination CRUD partielle sans reload complet
- modales Alpine pour confirmation/suppression
- dropdowns et menus utilisateur Alpine
- toast notifications Alpine pour les flash messages
- exemples de formulaires inline HTMX avec retour serveur partiel

## Priorite basse

### 12. Ajouter un mode CI pour l'installer

Un flag `--ci` pourrait impliquer:

- `--no-interaction`
- sortie compacte
- codes de retour stricts
- pas d'auto-suppression sauf demande explicite

### 13. Ajouter une validation de configuration avant generation

Avant d'ecrire les fichiers, verifier:

- namespace PHP valide
- chemins commencant par `/`
- preset design existant
- price IDs manquants si Stripe active
- callbacks OAuth coherents avec `base_url`

### 14. Ajouter une sortie JSON pour `--dry-run`

Utile pour debug ou interface graphique:

```bash
php install-flight-starter.php --dry-run --json
```

### 15. Ajouter un petit changelog

Un `CHANGELOG.md` aiderait a suivre les decisions deja prises:

- suppression du double layout HTML
- auto-build npm
- router PHP integre
- assets propres en `/assets`
- design presets

## Dette technique observee

- La config frontend lit `VITE_BUILD_DIR`, mais la valeur recommandee est vide pour sortir en `/assets`.
- L'auto-build npm est implemente mais pas expose comme option CLI.
- Les tests automatises manquent encore.
- La suppression de dossiers contenant `vendor/google/*` peut etre penible sous Windows/Dropbox a cause des chemins longs et verrous systeme.
- Le README genere dans les apps est plus court que le README de l'installer; il faudra probablement l'aligner ensuite.

## Prochaine mini-iteration conseillee

1. Ajouter `npm.auto_build` + `--no-npm`.
2. Ajouter un smoke test de generation dans un dossier temporaire.
3. Ajouter une verification que le HTML contient `/assets/*.css` et jamais `/build/`.
4. Ajouter un exemple `seo.pages.home`.
5. Ajouter edition/suppression au CRUD generator.
