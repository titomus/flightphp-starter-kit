# Atelier autonome - modifications PHP sans GitHub

Le catalogue FlightPHP propose maintenant un moteur de code versionne, par MCP et OAuth.

## Installation initiale (derniere archive manuelle)

1. Deployer les nouveaux fichiers de l'archive sur o2switch, sans toucher a .env, a la base SQLite et aux fichiers deja modifies.
2. Verifier que app/Mcp/CodeWorkspace.php, app/Mcp/business_tools.php et app/Console/atelier_rollback.php existent.
3. Activer volontairement dans .env : ATELIER_CODE_UPDATES_ENABLED=true
   Cette permission est inactive par defaut.
4. Reconnecter Atelier Catalogue dans ChatGPT a https://test-github.zootz.fr/mcp
   pour accorder le nouveau droit OAuth app:deploy.
5. Faire une premiere modification benigne et verifier le retour arriere.

## Cycle de modification 100% MCP

- dev_list_files : chemins modifiables et empreintes SHA-256.
- dev_read_file : lecture du fichier et SHA-256 actuel.
- dev_prepare_patch : prepare une nouvelle version complete des fichiers,
  avec empreintes d'origine. Aucune modification active.
- dev_validate_patch : syntaxe PHP et verification de conflit.
- dev_deploy : exige confirmation explicite et conserve un snapshot prive.
- dev_history : historique des versions.
- dev_rollback : restaure les originaux, apres confirmation.

Le fichier app/Mcp/business_tools.php permet de declarer de nouveaux outils
MCP metier sans modifier le serveur et les authentifications.
Les classes metier supplementaires peuvent etre creees dans
app/Services/Extensions/NomDeClasse.php.

Le systeme limite les modifications aux fichiers :
- app/Services/CatalogService.php
- app/Services/CatalogCsv.php
- app/Controllers/CatalogController.php
- app/Views/catalog/index.php
- app/Mcp/business_tools.php
- app/Services/Extensions/NomDeClasse.php (classes metier seulement).

Les secrets, l'authentification, le framework et le moteur de deploiement
ne sont pas modifiables par les outils MCP. Les changements portant sur
les routes centrales, les dependances et le schema SQLite necessitent
encore un deploiement classique.

## Restauration independante du MCP

Les snapshots sont hors du dossier public :
app/storage/atelier/releases et app/storage/atelier/drafts.

Si l'application ne repond plus, utiliser SSH/cPanel :
php app/Console/atelier_rollback.php <release_id> <admin_id>

Cette commande ne charge pas le bootstrap FlightPHP et n'a pas besoin
du serveur MCP. Elle refusera la restauration si des fichiers ont
ete modifies apres la version indiquee. Une copie externe de SQLite
reste indispensable pour les migrations de base.

## Securite et limites connues

Le droit app:evolve ne suffit PAS : app:deploy est exige pour acceder
aux outils de code. Les operations d'ecriture necessitent egalement
ATELIER_CODE_UPDATES_ENABLED=true. Le deploiement demande confirmed=true.

ATTENTION : modifier du code PHP equivaut a permettre une execution de
code au niveau du processus PHP. Le moteur n'est PAS une sandbox contre
un administrateur compromis ou contre du code malveillant.
N'accorder app:deploy qu'a un administrateur de confiance.

La verification avant deploiement analyse la syntaxe PHP via TOKEN_PARSE
et l'empreinte SHA-256 ; elle ne garantit pas le comportement fonctionnel.
Les fichiers sont ecrits via temporaire/rename sous un verrou unique.
Une mise a jour multifichier n'est pas completement atomique et un crash
au milieu peut necessiter une recuperation par terminal.
Les snapshots de fichiers ne restaurent pas les changements de la base.

Premier cas d'usage propose : demande #1 - alertes de stock bas.
