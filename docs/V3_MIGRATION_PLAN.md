# Flight Starter Kit V3 — consolidation plan

## Existing baseline
- Main already includes V2.1 web installer and V2 native MCP.
- Branches to compare and eventually retire: feature/flight-starter-v2, feature/mcp-native, feature/web-installer-v2-1. Retain demo/artisan-catalog unless separately approved.
- Never delete branches before merging and verifying the new release.

## Package size
Published V2.1 beta runtime ZIP: 53,789,532 bytes (~51.3 MiB).
The build script packages a single Composer vendor tree and three compiled Vite theme directories (saas, outil_metier, blog), plus installer source.
Composer already runs --no-dev; node_modules are excluded.
A per-path compressed/uncompressed audit of the release archive is required before removing packages. Do not remove PHP packages solely based on name.

## V3 goals
1. One self-contained downloadable ZIP with installer and precompiled runtime.
2. No GitHub API, PAT, SHA input, Node or Composer required on client hosting.
3. Installer consumes local package and handles resumable stages, progress, HTTPS, document-root checks, safe cleanup and install lock.
4. Admin > IA & MCP page with permissions evolve/deploy, backups and rollback.
5. Preserve server-level deploy kill switch and enforce all permissions server-side.
6. Migrate existing installs safely, without modifying their data.

## Work sequence
A. Inventory branches and compare changes, document source of truth.
B. Instrument ZIP file-size report grouped by dependency and theme, compare safe pruning candidates.
C. Refactor installer to read local runtime, then build one ZIP.
D. Add admin MCP settings and permission tests.
E. Test clean install on shared hosting, OAuth connection, deploy, backup and rollback.
F. Publish V3 prerelease, migrate test site, merge into main after validation.
G. Retire superseded feature branches only after release and smoke checks pass.

## Release gate
Do not call the experimental ZIP installable until the local-runtime installer has been implemented and tested end to end.
