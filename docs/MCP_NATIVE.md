# MCP natif dans le FlightPHP Starter Kit

## Principe

Le MCP ne remplace pas les fonctions métier. Le code métier reste dans les services PHP
et peut être appelé aussi bien par l'interface web, une API REST ou un outil MCP.
Une fonctionnalité CSV, si l'application en propose une, fonctionne exactement comme
une autre fonction métier.

## Installer

Configurer `installer/config.php` :

```php
'mcp' => [
    'enabled' => true,
],
```

Puis lancer l'installateur normalement. La génération ajoute :

- `app/Mcp/Server.php` : serveur JSON-RPC avec `initialize`, `ping`, `tools/list` et `tools/call`.
- `app/Mcp/tools.php` : registre explicite des capacités exposées, modifiable par le développeur.
- `public/mcp.php` : point d'entrée HTTP POST qui exige un jeton Bearer.

Par défaut, `mcp.enabled` vaut `false` et aucun fichier MCP n'est généré.

Dans le projet généré, définir `MCP_BEARER_TOKEN` dans `.env` avec une valeur aléatoire
d'au moins 32 caractères, puis publier l'application sous HTTPS. Exemple de génération :

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Le test de génération se lance depuis la racine du dépôt source :

```bash
php tests/mcp-smoke.php
```

## Ajouter une fonction métier au MCP

Exemple illustratif : si `App\\Services\\CatalogService` existe et propose une opération
de prévisualisation d'import, un développeur peut la déclarer dans `app/Mcp/tools.php` :

```php
'catalog_preview_import' => [
    'description' => 'Preview a catalog import without changing records.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['rows'],
    ],
    'handler' => static function (array $args): array {
        // Example only: implement the service and validate the payload first.
        return (new \\App\\Services\\CatalogService())->previewImport($args['rows']);
    },
],
```

ChatGPT peut analyser le CSV et fournir les lignes structurées à cet outil. L'import
n'est ni obligatoire ni intégré en dur au serveur MCP. Le logiciel reste maître
de la validation, des règles de doublons et des autorisations.

**Attention :** le prototype utilise un secret d'application global. Ne pas ajouter
d'outils de mutation multi-utilisateur avec ce seul mécanisme : chaque appel doit
être lié à une identité authentifiée et à un tenant, avec permissions spécifiques.

## Evolution du code par IA : périmètre séparé

Un agent de développement autorisé peut inspecter le dépôt GitHub, créer une branche,
modifier des fichiers, exécuter CI et ouvrir une pull request. Ce processus est
distinct des outils MCP accessibles aux utilisateurs finaux du SaaS.

- Les outils métier n'exposent jamais shell, SQL générique ou écriture arbitraire de fichiers.
- Le code modifié passe par une branche, des tests et une revue avant déploiement.
- Les clés GitHub et la capacité de déploiement ne sont jamais accessibles depuis le MCP métier.

## Limites actuelles

Ceci est un socle **expérimental**, pas un serveur MCP production prêt à connecter à
n'importe quel client. Il prend en charge un transport JSON-RPC HTTP POST minimal.
La compatibilité complète Streamable HTTP, la négociation des versions du protocole,
les sessions, l'authentification OAuth, les permissions par utilisateur/tenant,
la limitation des appels et les tests d'intégration avec des clients réels restent
à réaliser avant une exposition publique.

Les tests locaux automatisés et la validation GitHub CI ne remplacent pas ces essais.
