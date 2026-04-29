# Flight PHP Starter Kit

A batteries-included installer that scaffolds a production-ready **Flight PHP** application with MVC, authentication, Stripe billing, OAuth, SEO, queue worker, backoffice, and a configurable design system — all generated from a single config file.

---

## What gets generated

Running the installer creates a complete application structure:

```
app/
├── config/           # app, auth, billing, design, mail, seo, social_auth …
├── Console/          # CLI kernel + queue worker + code generators
├── Controllers/      # HomeController, AccountController, BillingController, Auth/*
├── Database/
│   ├── Migrations/   # jobs, admin_users, users, subscriptions, billing_events
│   └── Seeds/
├── Jobs/             # JobInterface, ExampleJob, SendMailJob
├── Middleware/       # CSRF, auth, guest, throttle, security headers
├── Models/           # BaseModel, FrontendUser, User
├── Queues/           # QueueManager
├── routes/           # web.php, api.php, admin_generated.php
├── Services/         # Auth, Config, Database, Mailer, SeoManager,
│                     # StripeBilling, GoogleOAuth, GithubOAuth, View …
├── storage/          # cache/, logs/, queue/
├── Support/          # helpers.php, Env.php
└── Views/            # layouts, home, auth, account, billing, admin
public/
resources/
├── css/app.css       # Tailwind + design tokens (CSS variables)
└── js/app.js
composer.json         # auto-generated with all required dependencies
package.json          # Vite 5 + Tailwind CSS 3
.env.example
```

---

## Features

### Core
- **Flight PHP** — lightweight, fast, no magic
- **MVC** — clean controller / model / view separation
- **SQLite** database (swappable)
- **Migrations** + **seeder** via CLI
- **Queue worker** with database driver

### Authentication
- Session-based admin auth (throttled, CSRF-protected)
- Session-based frontend auth with registration
- **Google OAuth** (optional)
- **GitHub OAuth** (optional)

### Billing
- **Stripe Checkout** with multi-plan support (monthly / yearly / lifetime)
- Webhook endpoint to synchronise subscription state
- `subscriptions` table + `billing_events` journal

### Developer experience
- **Vite 5** + **Tailwind CSS 3** frontend pipeline
- **SEO** — meta tags, Open Graph, sitemap, robots.txt
- **Email** — log driver or SMTP via Symfony Mailer
- **Security headers** middleware out of the box
- **CRUD backoffice generator** via CLI (`make:backoffice`)
- **Dry-run mode** — preview what the installer would create without touching the filesystem

### Design presets
Choose a visual preset in `installer/config.php` and the generated CSS + views adapt automatically:

| Preset | Style | Layout |
|---|---|---|
| `saas` | Dark blue/teal, Space Grotesk | Contained 1180px |
| `outil_metier` | Light teal/amber, IBM Plex | Full-width 1440px |
| `blog` | Warm beige/rust, Fraunces | Contained 940px |

All presets generate CSS custom properties (`--theme-primary`, `--theme-background`, etc.) so every component reacts to the chosen theme. Individual tokens can be overridden without touching a preset.

---

## Requirements

- PHP 8.1+
- Composer
- Node.js 18+ and npm (for the frontend pipeline)

---

## Installation

**1. Clone the installer**

```bash
git clone https://github.com/titomus/flightphp-starter-kit.git my-project
cd my-project
```

**2. Configure**

Edit `installer/config.php` to set your project name, enabled features, design preset, Stripe keys, OAuth credentials, etc.

```php
'project_name' => 'My App',
'design' => [
    'preset' => 'saas', // 'saas' | 'outil_metier' | 'blog'
],
'stripe' => [
    'enabled' => true,
    'publishable_key' => 'pk_live_...',
    'secret_key' => 'sk_live_...',
],
'social_auth' => [
    'google' => ['enabled' => true, 'client_id' => '...', 'client_secret' => '...'],
    'github' => ['enabled' => true, 'client_id' => '...', 'client_secret' => '...'],
],
```

**3. Run the installer**

```bash
# Preview first (no files written)
php install-flight-starter.php --dry-run --keep-installer

# Generate the app
php install-flight-starter.php
```

The installer will:
- Create all directories and files
- Run `composer install` automatically
- Remove itself when done (unless `--keep-installer` is passed)

**4. Bootstrap the database**

```bash
php app/Console/console.php migrate
php app/Console/console.php seed
```

**5. Start the frontend pipeline**

```bash
npm install
npm run dev   # development with HMR
npm run build # production build
```

**6. Serve**

```bash
php -S localhost:8000 -t public
```

---

## CLI commands

```bash
php app/Console/console.php migrate              # Run pending migrations
php app/Console/console.php migrate:rollback     # Rollback last migration
php app/Console/console.php seed                 # Run database seeder
php app/Console/console.php queue:work           # Start the queue worker
php app/Console/console.php queue:work --once    # Process one job then stop
php app/Console/console.php make:backoffice posts title:string content:text  # Generate CRUD
```

---

## Environment variables

Copy `.env.example` to `.env` and fill in your values:

```env
APP_NAME="My App"
APP_URL=http://localhost:8000

DB_DATABASE=app/storage/database.sqlite

STRIPE_PUBLISHABLE_KEY=pk_...
STRIPE_SECRET_KEY=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=

GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

MAIL_DRIVER=log   # log | smtp
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=
SMTP_PASSWORD=
```

---

## Design customisation

All visual tokens live in `app/config/design.php` (generated) and `resources/css/app.css` as CSS custom properties. To override a single token without changing the preset, use `overrides` in `installer/config.php` before generating:

```php
'design' => [
    'preset' => 'saas',
    'overrides' => [
        'palette' => ['primary' => '#7c3aed'],
        'effects' => ['radius' => '12px'],
    ],
],
```

---

## Tech stack

| Layer | Package |
|---|---|
| Framework | `flightphp/core` |
| Billing | `stripe/stripe-php` |
| Google OAuth | `google/apiclient` |
| GitHub OAuth | `league/oauth2-github` |
| Email | `symfony/mailer` + `symfony/mime` |
| Frontend | Vite 5, Tailwind CSS 3 |

---

## License

MIT
