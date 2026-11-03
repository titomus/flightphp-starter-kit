# One ZIP installer + admin MCP controls

Status: implementation specification for `feature/one-zip-installer-admin-mcp`. This is **not** a shipped feature.

## Audit of existing implementation
- `main` and `feature/web-installer-v2-1` already include `web-installer/install.php`, `WebInstallRecipe`, `WebInstallEngine`, prebuilt runtime assets and a smoke test.
- The current installer fetches a GitHub Release at runtime and asks for a token, release tag, SHA-256, and private unlock secret.
- `feature/flight-starter-v2` already includes OAuth MCP scopes `business:read`, `business:write`, `app:evolve`, `app:deploy`; PHP writes also require the server-side `ATELIER_CODE_UPDATES_ENABLED` gate.
- Do not reimplement MCP or the generator. Refactor the packaging/installation UX and add an admin authorization layer.

## Target distribution
Produce exactly one versioned, self-contained `flight-starter-install-vX.Y.Z.zip` Release asset. Inside:
- `public/install.php` (entry point), private installer engine/recipe, precompiled `vendor`, compiled frontend assets, integrity manifest, and templates.
- No runtime GitHub downloads, Composer, Node, or API credentials.
- ZIP contains no real credentials, user database, or client-specific URLs.
- Place the project directory outside the public document root; document root must point to `public/`.
- Installer must work on o2switch-like shared hosting using only PHP >=8.2 and documented extensions.

## User journey
1. Download one ZIP, extract into an empty application directory.
2. Visit `https://domain/install.php`.
3. Installer performs prerequisites and write-permission checks and explains how to correct document-root misconfiguration.
4. Select project profile (SaaS, métier, blog, vitrine), optional members/MCP/Stripe, project name, admin email/password.
5. Show progress and resumable stages: verify package, stage files, generate, migrate, seed, activate. Never overwrite an existing installation.
6. After success, disable/remove installer, set private installation lock, show admin login and MCP URL.

## Security requirements
- Install bootstrap must resist public takeover: one-time secret generated server-side and read via hosting file manager, or a secure CLI-generated secret; no unauthenticated setup on publicly accessible fresh domains.
- CSRF, session binding, rate limiting, same-origin form, secure cookies, HTTPS, escaping, error logs without secrets.
- Validate ZIP integrity using a manifest embedded in the trusted release; refuse tampering and path traversal.
- Protect `.env`, DB, runtime, backups, vendor, and installer source outside web root.
- Atomic activation, idempotent recovery, rollback on failure, no partial public app, safe cleanup.
- Never store admin password in clear text after setup; password hash only.
- Do not allow silent auto-enabling of code deployment.

## Admin UI
Add `Administration > IA & MCP` to generated apps:
- MCP enabled status, copyable URL, OAuth connection guidance and granted scopes.
- Separate toggles for `app:evolve` and `app:deploy` (deploy requires evolve).
- Default: deploy disabled. Require admin reauthentication and explicit confirmation before enabling.
- Enforce authorizations server-side for every MCP tool call. Scopes granted at OAuth authorization are an upper bound, not a substitute for server-side permissions.
- Preserve `ATELIER_CODE_UPDATES_ENABLED` as a separate server-level emergency off switch. If disabled, show `Déploiement verrouillé par le serveur`, not a misleading working toggle.
- Backups mandatory before deployment, immutable audit trail and tested rollback. No disabling backups through UI for PHP deployments.
- Existing installations must migrate safely, without resetting current OAuth grants or user data.

## Acceptance tests
- Two clean o2switch installations from one ZIP, no terminal, no GitHub token, no network download.
- Installation succeeds with existing `public/.well-known` or `cgi-bin` where harmless, without deleting them.
- Interrupted install can safely resume; second install on populated project is rejected.
- Test with PHP 8.2, 8.3, and newer compatible PHP versions.
- MCP OAuth discovery and ChatGPT authorization pass; read/write/evolve/deploy permission matrix tested.
- Deploy rejected until both server-level gate and admin approval are active.
- Backup, deployment and rollback verified on a disposable app.
- No installation credentials, staging files or writable install endpoint remain accessible after completion.

## Release process
Implement on feature branch, run smoke and integration tests, produce prerelease ZIP and verify its SHA-256. Only merge to main and publish a stable release after successful shared-hosting installation tests.
