# MCP natif dans Flight Starter Kit 2.0 (bêta)

## Principe

Chaque application installée chez un client possède **son propre serveur MCP**,
sa base SQLite, son administrateur, ses outils métier et ses sauvegardes.

Le MCP est une interface **vers les services métier** de FlightPHP. Il ne
remplace pas les contrôleurs, les services ni les migrations du framework.

## Connexion ChatGPT

1. Personnaliser `installer/config.php` : domaine, projet, mot de passe
   administrateur unique (au moins 16 caractères). Le back-office est requis.
2. Générer l'application et installer les dépendances.
3. `php app/Console/console.php migrate` puis `seed`.
4. Configurer HTTPS et `APP_URL=https://domaine-client.fr` dans `.env`.
5. Se connecter en administrateur dans le navigateur.
6. Dans ChatGPT, déclarer un MCP distant avec l'URL
   `https://domaine-client.fr/mcp`.
7. Autoriser les permissions nécessaires sur l'écran de consentement.

Le serveur fournit :
- `/.well-known/oauth-authorization-server` et
  `/.well-known/oauth-protected-resource/mcp`.
- Enregistrement dynamique OAuth, PKCE, rotation des tokens et consentement.
- Endpoint MCP routé par FlightPHP : `POST /mcp`.
- `app_info` et les outils définis dans `app/Mcp/business_tools.php`.
- Outils de demande d'évolution, lecture/préparation/validation/déploiement,
  historique et rollback pour les utilisateurs autorisés.

## Permissions

| Permission | Usage |
| --- | --- |
| `business:read` | Lire les données métier via les services |
| `business:write` | Opérations métier explicitement autorisées |
| `app:evolve` | Soumettre des demandes d'évolution |
| `app:deploy` | Préparer et gérer du code métier versionné |

Les outils dépendent de l'identité OAuth : **ne jamais accepter un admin_id
ou tenant_id fourni par les arguments des outils**.

Les opérations de publication nécessitent aussi
`ATELIER_CODE_UPDATES_ENABLED=true` dans le `.env` privé. L'installateur
le configure à `false` par défaut. La prévisualisation précède toujours
l'activation ; le déploiement et la restauration exigent confirmation.

## Ajouter une fonctionnalité métier

1. Créer un contrôleur sous `app/Controllers/Business/`.
2. Écrire les règles dans `app/Services/Business/`.
3. Ajouter la route Flight dans `app/routes/business.php`.
4. Utiliser les vues sous `app/Views/business/`.
5. Déclarer un outil MCP dans `app/Mcp/business_tools.php`,
   **qui invoque le même service** que le contrôleur.
6. Placer le CSS/JS propre au client sous `public/assets/business.css` et
   `public/assets/business.js`, chargés automatiquement par le layout.
7. Si le changement exige une migration SQLite, créer un fichier dans
   `app/Database/Migrations/` et appliquer la migration par la console.
   **Les migrations ne sont pas encore déployées automatiquement par MCP.**

Exemple :

```php
if (in_array(\App\Mcp\DynamicOAuth::READ, $allowedScopes, true)) {
    return [
        'orders_summary' => [
            'description' => 'Résumé des commandes du client connecté',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
            'handler' => static fn (array $args): array =>
                (new \App\Services\Business\OrdersService())->summary(),
        ],
    ];
}
return [];
```

Le code précédent est illustratif : `OrdersService` doit d'abord être créé.

## Sauvegardes et limitations

Le moteur place ses brouillons et snapshots sous
`app/storage/atelier/`, en dehors du dossier public. Le dossier doit être
inscriptible par PHP, ne doit pas être exposé par le serveur HTTP et doit
être sauvegardé à l'extérieur.

Restauration CLI si le MCP est indisponible :

```bash
php app/Console/atelier_rollback.php <release_id> <admin_id>
# Uniquement en dernier recours, si un déploiement multifichier s'est interrompu :
php app/Console/atelier_rollback.php <release_id> <admin_id> --emergency
```

**Important :** modifier du PHP via MCP est une capacité de déploiement
privilégiée, pas un bac à sable. Le moteur limite les dossiers modifiables,
mais ne peut pas empêcher du code PHP délibérément malveillant à l'intérieur
d'un fichier autorisé. N'accordez jamais `app:deploy` à un utilisateur non
administrateur. Une compromission du compte ou du token aurait des
conséquences sur le serveur.

Le moteur vérifie la syntaxe PHP et les empreintes SHA-256, mais
**ne prouve pas le bon fonctionnement métier** des modifications.
Une série d'écritures n'est pas entièrement atomique.
La sauvegarde des fichiers ne restaure pas SQLite ni les pièces jointes.
Prévoir une sauvegarde de la base, du stockage et une revue technique
pour les installations clients réelles.

Le registre OAuth actuel nécessite encore une revue production :
limitations d'enregistrement et contrôles anti-abus, révocation depuis
l'interface admin, gestion du cycle de vie des accès et tests sur un second
hébergement client.

Voir [docs/FLIGHT_STARTER_V2.md](FLIGHT_STARTER_V2.md) pour le périmètre produit
et les critères de livraison.
