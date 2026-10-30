# Flight PHP Starter Kit

Installer configurable pour generer une application Flight PHP complete: MVC, auth, backoffice, queue, Stripe, OAuth, SEO, email, Vite, Tailwind, HTMX, AlpineJS et design presets.

Le but du projet est de partir d'un seul dossier d'installation, d'ajuster `installer/config.php`, puis de generer une base d'application directement exploitable sans recoller les memes fondations a chaque nouveau projet.

## Etat actuel

- Installer mono-entree: `php install-flight-starter.php`
- Configuration centrale: `installer/config.php`
- Generation par catalogues: core projet, runtime, MVC, console, frontend, backoffice
- Auto-install Composer avec fallback `composer.phar`
- Dependances Composer optionnelles selon les features activees au moment de generer
- Auto-build frontend avec `npm install` puis `npm run build`
- Auto-suppression de l'installer apres succes, sauf option `--keep-installer`
- Assets Vite generes proprement dans `public/assets/`
- Manifest Vite genere dans `public/manifest.json`
- Router PHP integre via `public/router.php` pour servir les fichiers statiques et router les URLs dynamiques comme `/sitemap.xml`
- Design system base sur presets et CSS variables

## MCP optionnel (experimental)

Le starter kit peut generer un point d'entree MCP HTTP et un registre explicite
d'outils metier. La logique metier reste dans les services PHP partages par
l'interface web, l'API et le MCP. L'import CSV n'est qu'un exemple possible.

Dans `installer/config.php`, activer :

```php
'mcp' => [
    'enabled' => true,
],
```

L'installateur genere `app/Mcp/Server.php`, `app/Mcp/tools.php` et
`public/mcp.php`. Le MCP est desactive par defaut.

Definir `MCP_BEARER_TOKEN` avec un secret aleatoire d'au moins 32 caracteres
avant tout essai via HTTP. Utiliser HTTPS. Le prototype prend en charge les
principales methodes JSON-RPC MCP mais **pas encore** une implementation
production complete (OAuth, permissions multi-tenant, transport Streamable HTTP
integral et tests d'interoperabilite).

Test automatisé : `php tests/mcp-smoke.php`.

Voir [docs/MCP_NATIVE.md](docs/MCP_NATIVE.md) pour le raccord des services
metier et la separation entre MCP utilisateur et agent de developpement.

## Prerequis

- PHP 8.1 ou plus recent
- Node.js 18 ou plus recent
- npm
- Composer dans le PATH, ou un `composer.phar` dans le projet ou le dossier parent

Sur Windows, si Composer n'est pas dans le PATH, le plus simple est de placer `composer.phar` a la racine avant de lancer l'installer:

```powershell
Invoke-WebRequest -Uri "https://getcomposer.org/composer.phar" -OutFile "composer.phar"
```

## Installation rapide

```bash
git clone https://github.com/titomus/flightphp-starter-kit.git my-project
cd my-project
php install-flight-starter.php
```

Par defaut, l'installer:

- genere tous les fichiers applicatifs
- cree les dossiers runtime
- cree le fichier SQLite vide
- lance `composer install --no-interaction` si possible
- lance `npm install`
- lance `npm run build`
- supprime `install-flight-starter.php` et `installer/` si l'installation reussit

Ensuite:

```bash
copy .env.example .env       # Windows PowerShell
# ou: cp .env.example .env   # macOS/Linux

php app/Console/console.php migrate
php app/Console/console.php seed
php -S localhost:8000 -t public public/router.php
```

L'application est disponible sur `http://localhost:8000`.

## Commandes installer

```bash
php install-flight-starter.php --help
php install-flight-starter.php --dry-run
php install-flight-starter.php --dry-run --keep-installer
php install-flight-starter.php --force
php install-flight-starter.php --no-composer
php install-flight-starter.php --with-backoffice
php install-flight-starter.php --without-backoffice
php install-flight-starter.php --keep-installer
```

Options:

| Option | Effet |
|---|---|
| `--dry-run` | Affiche les fichiers/dossiers prevus sans ecrire |
| `--force` | Ecrase les fichiers existants si le contenu differe |
| `--no-composer` | Ignore `composer install` |
| `--with-backoffice` | Force la generation du backoffice |
| `--without-backoffice` | Desactive la generation du backoffice |
| `--keep-installer` | Conserve `install-flight-starter.php` et `installer/` |
| `--help`, `-h` | Affiche l'aide |

## Configuration principale

Tout se regle dans `installer/config.php` avant generation.

Sections disponibles:

| Section | Role |
|---|---|
| `project_name`, `namespace`, `base_url`, `timezone` | Identite du projet genere |
| `database` | Driver et chemin SQLite par defaut |
| `queue` | Driver, table, sleep, nombre d'essais |
| `backoffice` | Activation, chemin admin, identifiants seed |
| `auth` | Auth admin: paths, session, throttling |
| `frontend_auth` | Auth publique: login, register, account |
| `mail` | Log mail ou SMTP Symfony Mailer |
| `seo` | Meta par defaut, sitemap, robots |
| `stripe` | Checkout, webhook, plans, price IDs |
| `social_auth` | Google OAuth et GitHub OAuth |
| `design` | Preset visuel et overrides de tokens |
| `composer` | Auto-install Composer |
| `frontend` | Vite, Tailwind, HTMX, AlpineJS, build dir, dev server |
| `features` | Activation frontend/backoffice |
| `installer` | Auto-suppression apres succes |

## Structure generee

```text
app/
  Console/
    Kernel.php
    console.php
  Controllers/
    Admin/
    Auth/
    AccountController.php
    BillingController.php
    HomeController.php
    SeoController.php
  Database/
    Migrations/
    Seeds/
  Jobs/
    ExampleJob.php
    JobInterface.php
    SendMailJob.php
  Middleware/
    AuthenticateMiddleware.php
    GuestMiddleware.php
    SecurityHeadersMiddleware.php
    ThrottleRequestsMiddleware.php
    VerifyCsrfMiddleware.php
  Models/
    BaseModel.php
    FrontendUser.php
    User.php
  Queues/
    QueueManager.php
  Services/
    Auth.php
    Config.php
    CrudGenerator.php
    Database.php
    Generator.php
    GithubOAuth.php
    GoogleOAuth.php
    Mailer.php
    MiddlewarePipeline.php
    Migrator.php
    SeoManager.php
    StripeBilling.php
    View.php
  Support/
    Env.php
    helpers.php
  config/
    app.php
    auth.php
    backoffice.php
    billing.php
    database.php
    design.php
    frontend.php
    mail.php
    queue.php
    seo.php
    social_auth.php
  routes/
    admin_generated.php
    api.php
    web.php
  storage/
  Views/
public/
  assets/
  .htaccess
  index.php
  manifest.json
  router.php
resources/
  css/app.css
  js/app.js
tests/
composer.json
package.json
postcss.config.js
tailwind.config.js
vite.config.js
.env.example
README.md
```

## Fonctionnalites generees

### Core applicatif

- Front controller Flight dans `public/index.php`
- Router de developpement dans `public/router.php`
- Routes web, API et admin separees
- Service `Config` avec lecture `.env`
- Service `View` avec layout serveur
- Helpers globaux pour `config()`, `env()`, `auth()`, `seo()`, `vite_tags()`, CSRF, paths et redirects
- Middleware pipeline reutilisable

### Dependances Composer optionnelles

Le `composer.json` genere reste leger par defaut. Seuls `php` et `flightphp/core` sont toujours requis.

Packages ajoutes selon la config au moment de lancer l'installer:

| Package | Condition |
|---|---|
| `google/apiclient` | `social_auth.enabled=true` et Google OAuth active |
| `league/oauth2-client` + `league/oauth2-github` | `social_auth.enabled=true` et GitHub OAuth active |
| `stripe/stripe-php` | `stripe.enabled=true` |
| `symfony/mailer` + `symfony/mime` | `mail.default=smtp` |

Le `composer.json` genere contient aussi une section `suggest` pour rappeler les packages a installer si une feature est activee plus tard via `.env`.

### Frontend Vite/Tailwind/HTMX/AlpineJS

- `vite.config.js` genere avec `publicDir: false`
- Build vers `public/`
- Assets hashes dans `public/assets/`
- Manifest dans `public/manifest.json`
- `vite_tags()` injecte des URLs propres: `/assets/app-xxxx.css` et `/assets/app-xxxx.js`
- Tailwind 3 avec `resources/css/app.css`
- HTMX et AlpineJS activables via `installer/config.php`
- `resources/js/app.js` importe le CSS et expose HTMX/Alpine si actives

Configuration par defaut:

```php
'frontend' => [
  'vite' => true,
  'tailwind' => true,
  'htmx' => true,
  'alpine' => true,
  'build_dir' => '',
  'dev_server' => '',
],
```

Quand `htmx` est actif, `package.json` inclut `htmx.org` et l'objet est expose sur `window.htmx`. Quand `alpine` est actif, `package.json` inclut `alpinejs`, expose `window.Alpine` et lance `Alpine.start()`.

### Design system

Trois presets sont disponibles:

| Preset | Usage | Layout |
|---|---|---|
| `saas` | Produit SaaS sombre, bleu/teal | Contenu 1180px |
| `outil_metier` | Interface metier claire, teal/amber | Pleine largeur 1440px |
| `blog` | Editorial/blog chaleureux | Contenu 940px |

Le CSS genere expose des tokens:

- `--theme-primary`
- `--theme-accent`
- `--theme-background`
- `--theme-surface`
- `--theme-surface-alt`
- `--theme-text`
- `--theme-muted`
- `--theme-border`
- `--theme-radius`
- `--theme-shadow`
- `--theme-background-image`
- `--theme-content-width`
- `--theme-font-display`
- `--theme-font-body`

Exemple d'override avant generation:

```php
'design' => [
    'preset' => 'outil_metier',
    'overrides' => [
        'palette' => [
            'primary' => '#2563eb',
        ],
        'effects' => [
            'radius' => '14px',
        ],
    ],
],
```

### Authentification

- Auth admin session-based
- Auth frontend session-based
- Registration frontend optionnelle
- CSRF sur formulaires sensibles
- Throttling login
- Middlewares `AuthenticateMiddleware` et `GuestMiddleware`
- Seeds admin par defaut: `admin@example.com` / `admin123`

### OAuth

Providers prevus:

- Google OAuth via `google/apiclient`
- GitHub OAuth via `league/oauth2-github`

Les providers sont desactives tant que les credentials ne sont pas fournis.

### Stripe

- Page pricing generee
- Checkout Stripe multi-plan
- Plans par defaut: monthly, yearly, lifetime
- Webhook Stripe
- Table `subscriptions`
- Table `billing_events`
- Mise a jour basique du statut billing utilisateur

### SEO

- Service `SeoManager`
- Meta description et robots
- Canonical
- Open Graph
- Twitter cards
- JSON-LD optionnel
- Route `/sitemap.xml`
- Route `/robots.txt`

Important en developpement: utiliser `public/router.php` avec le serveur PHP integre, sinon les routes avec extension comme `/sitemap.xml` peuvent etre cherchees comme fichiers statiques.

```bash
php -S localhost:8000 -t public public/router.php
```

### Console, migrations et queue

Commandes principales:

```bash
php app/Console/console.php migrate
php app/Console/console.php rollback
php app/Console/console.php seed
php app/Console/console.php queue:dispatch App\Jobs\ExampleJob '{"message":"hello"}'
php app/Console/console.php queue:dispatch App\Jobs\SendMailJob '{"to":"hello@example.com","subject":"Welcome","html":"<h1>Hello</h1>"}'
php app/Console/console.php queue:work
php app/Console/console.php queue:work --once
```

Generators:

```bash
php app/Console/console.php make:controller ReportsController
php app/Console/console.php make:model Report
php app/Console/console.php make:migration create_reports_table
php app/Console/console.php make:job SendDigestJob
php app/Console/console.php make:backoffice posts title:string content:text
```

### Backoffice

Si active, l'installer genere:

- Dashboard admin
- Login/logout admin
- Table `admin_users`
- Model `User`
- Layout admin tokenise par le design system
- Seeder admin
- CRUD generator qui etend `app/routes/admin_generated.php`

URL par defaut: `/admin`.

Le CRUD generator cree maintenant:

- controller admin dedie
- migration SQL pour la table
- index pagine
- formulaire create/edit partage
- routes create, store, edit, update et delete
- actions edit/delete dans le tableau
- flash messages succes/erreur
- validation `required` simple
- champs `string`, `text`, `integer`, `float`, `boolean`, `date`, `datetime`, `select`

Exemples de definitions:

```bash
php app/Console/console.php make:backoffice posts title:string:required content:text published:boolean status:select:draft,published
php app/Console/console.php migrate
```

## Variables d'environnement

Le fichier `.env.example` genere couvre:

- `APP_*`
- `DB_*`
- `QUEUE_*`
- `ADMIN_*`
- `AUTH_*`
- `FRONTEND_AUTH_*`
- `MAIL_*`
- `SEO_*`
- `STRIPE_*`
- `SOCIAL_AUTH_*`
- `GOOGLE_*`
- `GITHUB_*`
- `FRONTEND_VITE`
- `FRONTEND_TAILWIND`
- `FRONTEND_HTMX`
- `FRONTEND_ALPINE`
- `VITE_DEV_SERVER`
- `VITE_BUILD_DIR`

Pour une installation locale minimale:

```env
APP_URL=http://localhost:8000
DB_DRIVER=sqlite
DB_DATABASE=app/storage/database.sqlite
FRONTEND_VITE=true
FRONTEND_TAILWIND=true
FRONTEND_HTMX=true
FRONTEND_ALPINE=true
VITE_BUILD_DIR=
```

## Workflow recommande

1. Modifier `installer/config.php`
2. Lancer un dry-run

```bash
php install-flight-starter.php --dry-run --keep-installer
```

3. Lancer la generation en gardant l'installer si tu iteres encore

```bash
php install-flight-starter.php --keep-installer
```

4. Tester rapidement

```bash
copy .env.example .env
php app/Console/console.php migrate
php app/Console/console.php seed
php -S localhost:8000 -t public public/router.php
```

5. Quand le starter est bon, lancer sans `--keep-installer` pour un projet final propre

```bash
php install-flight-starter.php
```

## Verification rapide

Apres installation et serveur lance:

```bash
curl http://localhost:8000/api/health
curl http://localhost:8000/sitemap.xml
curl http://localhost:8000/robots.txt
```

Dans le HTML de la home, les assets doivent ressembler a:

```html
<link rel="stylesheet" href="/assets/app-xxxx.css">
<script type="module" src="/assets/app-xxxx.js"></script>
```

Il ne doit pas y avoir de double `<html>`, `<head>` ou `<body>`.

## Points connus

- Composer peut etre absent du PATH sur Windows; dans ce cas utiliser `composer.phar`.
- Les packages Google peuvent creer des chemins tres longs dans `vendor/` sous Windows/Dropbox.
- Les dependances optionnelles sont decidees au moment de generer. Si une feature est activee plus tard via `.env`, il faut installer le package suggere correspondant.
- L'installer a une option `--no-composer`, mais l'auto-build npm reste pilote par `config['npm']['auto_build']`; cette cle n'est pas encore exposee explicitement dans `installer/config.php`.
- Les tests automatises du starter genere restent a structurer.

## Licence

MIT
