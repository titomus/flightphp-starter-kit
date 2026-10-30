# Déploiement de la démonstration

**URL cible :** https://test-github.zootz.fr

Le workflow `Build artisan mini SaaS` exécute réellement l'installateur puis publie
un artifact GitHub Actions nommé `atelier-catalogue-generated` (durée : 14 jours).
Cet artifact est le projet PHP autonome, **pas un site déjà hébergé**.

## Hébergement attendu

- PHP 8.1+ avec extensions `pdo_sqlite` et `mbstring`.
- Un accès SSH/SFTP autorisant l'installation du code.
- Un dossier privé pour l'application (notamment `.env`, `vendor/` et `app/storage/`).
- La racine web de `test-github.zootz.fr` doit pointer vers le sous-dossier `public/` de l'application.
- HTTPS et permissions d'écriture pour `app/storage/`.

## Première mise en service

1. Décompresser l'artifact dans le dossier privé du sous-domaine.
2. Vérifier que le document root est exactement `<application>/public`, et non `<application>`.
3. Copier `.env.example` vers `.env` et définir :
   - `APP_URL=https://test-github.zootz.fr`
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `ADMIN_EMAIL` et `ADMIN_PASSWORD` avec des valeurs privées fortes **avant tout seeding**.
   - `MCP_BEARER_TOKEN` aléatoire si l'endpoint MCP doit être testé.
   - `MCP_ALLOW_WRITES=false` (mode lecture seule).
4. Lancer `composer install --no-dev --optimize-autoloader` si les dépendances ne sont pas déjà présentes.
5. Lancer `php app/Console/console.php migrate`, puis `php app/Console/console.php seed`.
6. Ouvrir `https://test-github.zootz.fr/admin/login` puis `/catalog` pour importer le fichier de démonstration.

## Mise à jour automatisée via GitHub

Il reste à raccorder GitHub Actions à l'hébergement. Paramètres manquants :
l'hébergeur SSH, le chemin absolu de l'application sur le serveur et la méthode
d'authentification. Ne jamais ajouter d'identifiants, de clés privées ni de `.env`
au dépôt : utiliser les GitHub Actions Secrets.

Le déploiement ne devra pas écraser `.env` ou `app/storage/`, et ne doit pas
fusionner automatiquement la PR du starter kit. La capacité MCP métier à modifier
des données restera distincte de la capacité GitHub de modifier le code.

**État actuel :** workflow de construction et tests fonctionnels opérationnels,
déploiement sur le domaine non encore effectué.
