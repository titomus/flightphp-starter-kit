# Flight Starter Kit 2.0 : application client évolutive via ChatGPT

## Ce que livre le starter (et ce qu'il ne livre pas)

Le kit génère une application **FlightPHP 3 MVC indépendante**, sur le domaine et
l'hébergement du client. Il contient nativement le protocole MCP, une authentification
OAuth liée à l'administrateur, un registre d'outils métier versionnable et un atelier
de développement autonome.

Il **ne contient aucun code spécifique au catalogue, à l'épicerie ou à un autre
client**. Les exemples (Atelier Catalogue, Le Comptoir) ne sont pas dans la
génération générique.

Le dépôt GitHub contient la **fabrique**. Le code propre à chaque client, ses
identifiants, ses données et ses sauvegardes restent chez ce client.

## Installation type chez un client

1. Adapter `installer/config.php` (nom, domaine HTTPS, backoffice, design).
2. Générer l'application FlightPHP avec MCP et l'atelier inclus.
3. Installer Composer, appliquer les migrations, créer un administrateur avec
   un mot de passe unique, configurer le domaine et HTTPS.
4. Connecter ChatGPT à `https://domaine-client.fr/mcp` via OAuth 2.1 + PKCE.
5. Accorder seulement `business:read` au départ, puis explicitement
   `business:write` ou `app:deploy` si nécessaire.
6. Activer l'atelier par une configuration privée, **désactivée par défaut**.
7. Demander une évolution. ChatGPT lit le projet, prépare un ensemble de
   modifications, vérifie, déploie et conserve un instantané restaurable.
8. Pour toute migration de base ou modification de dépendances, préparer aussi
   un plan de restauration des données et une vérification d'intégration.

## Architecture cible

```text
app/
  Controllers/
    Business/           # écrans métier propres au client
  Models/
    Business/
  Services/
    Business/
  Views/
    business/
  routes/
    business.php        # routes métier explicites
    mcp.php             # endpoints OAuth / MCP protégés
  Database/
    Migrations/         # migrations versionnées
  Mcp/
    DynamicOAuth.php    # fournisseur OAuth
    HttpEndpoint.php    # transporte JSON-RPC/MCP
    Server.php          # dispatcher MCP
    tools.php           # outils génériques
    business_tools.php  # outils métier propres au client
    CodeWorkspace.php   # déploiement/snapshots, non modifiable par MCP
  storage/
    atelier/
      drafts/           # projets de patch
      releases/         # snapshots de déploiement
public/
  index.php
  assets/
    business.css       # modifiable via MCP sans build Vite
    business.js
```

## Architecture MVC et discipline de développement

- Les routes appellent des contrôleurs, jamais de requêtes SQL métier directes.
- Les contrôleurs valident les requêtes, les services portent les règles
  métier, les modèles représentent l'accès aux données.
- Les outils MCP **appellent les mêmes services** que les écrans : pas de
  duplication de règles commerciales entre UI et IA.
- Les migrations se font via le Migrator du framework, jamais avec un
  `CREATE TABLE` dans un constructeur de service.
- Le framework conserve Vite pour ses assets de base ; les CSS/JS métier sont isolés dans `public/assets/business.css` et `business.js` pour permettre leur déploiement sans compilation.
- Les fichiers secrets, OAuth, cœur FlightPHP, migration runner et moteur de
  déploiement sont protégés par une liste d'autorisation stricte.
- Le moteur ne constitue pas une sandbox : un développeur autorisé à publier du
  PHP dispose de permissions très sensibles. Ne jamais donner `app:deploy` à
  un simple utilisateur métier.

## Séparation des droits

| Droit | Capacités |
| --- | --- |
| `business:read` | outils métier en lecture |
| `business:write` | changements métier, validation humaine explicite |
| `app:evolve` | soumettre une demande d'évolution, sans exécution de code |
| `app:deploy` | lire/préparer/déployer/restaurer des fichiers autorisés |

Chaque jeton est lié à une identité administrateur et une ressource.
Désactiver `app:deploy` au niveau de l'environnement bloque toute publication,
même si un ancien jeton OAuth est toujours valide.

## Déploiement et récupération

- Chaque patch consigne les chemins, empreintes SHA-256 avant/après, identité
  administrative et liste des modifications.
- Prévisualisation et validation avant écriture, contrôle de conflit juste
  avant déploiement, sauvegarde privée **avant** les écritures.
- Activation par fichiers temporaires + rename sous verrou.
- Historique des versions et rollback avec garde contre la perte de
  modifications plus récentes.
- Commande CLI de secours indépendante des routes/MCP.
- **Attention** : le remplacement de plusieurs fichiers n'est pas une
  transaction atomique ; un crash exige parfois une restauration manuelle.
- Sauvegarde séparée obligatoire pour la base de données et les uploads.
- Une migration de base ne se restaure pas en remettant seulement le PHP.

## Étapes de réalisation

- [x] Retour d'expérience réel : atelier Catalogue avec OAuth, MCP et rollback.
- [x] Branche isolée de développement de la v2.
- [x] Génération générique avec OAuth PKCE, DCR, MCP sur route Flight.
- [x] Registre métier générique et extension `business_tools.php` (à enrichir pour chaque client).
- [x] Atelier de fichiers autonome, isolé des composants sensibles (version bêta).
- [x] Routes métier distinctes, fichiers statiques métier séparés et migrations du framework disponibles.
- [ ] Étendre l'atelier aux migrations avec prévisualisation et sauvegarde des données.
- [x] Tests CI de génération, installation indépendante, découverte OAuth, permissions, patch et rollback.
- [ ] Vérification de configuration cPanel/HTTPS, sécurité et sauvegarde SQLite.
- [ ] Publication de la v2 sur `main` **uniquement après validation**.

## Critère de réussite commercial

Installer le même kit sur deux domaines clients indépendants et développer
une fonctionnalité métier différente sur chacun, directement depuis ChatGPT,
sans accéder à GitHub après l'installation.

Le résultat doit survivre à une déconnexion, à une requête erronée et à un
retour arrière, sans exposer de code ou de données du client A au client B.
