# Installer Flight Starter Kit V3

> **Version actuelle :** [v3.0.0-beta.1](https://github.com/titomus/flightphp-starter-kit/releases/tag/v3.0.0-beta.1), bêta technique pour un nouveau site de test.

## Télécharger

**[ZIP autonome V3](https://github.com/titomus/flightphp-starter-kit/releases/download/v3.0.0-beta.1/flight-starter-v3.0.0-beta.1.zip)** · [SHA-256](https://github.com/titomus/flightphp-starter-kit/releases/download/v3.0.0-beta.1/flight-starter-v3.0.0-beta.1.zip.sha256)

Il ne faut pas utiliser `Code → Download ZIP` ni un artefact d'une branche : ces téléchargements ne constituent pas la distribution officielle.

## Installation sur o2switch et hébergement PHP

1. Télécharger le ZIP de la Release et le décompresser **entièrement** dans un nouveau dossier de projet.
2. Configurer le domaine pour que son document root pointe sur **`projet/public/`**, jamais sur la racine.
3. Activer HTTPS et PHP 8.2+ avec PDO SQLite, mbstring, ZIP, OpenSSL et FileInfo.
4. Ouvrir `https://votre-domaine/install.php`.
5. Lire le secret créé dans **`.flight-install-access`**, à la racine privée du projet, et déverrouiller.
6. Choisir le profil de l'application et les options (membres, Stripe et/ou MCP), puis créer le propriétaire.
7. Après installation, se connecter à `/admin/login`. L'installateur supprime ses fichiers temporaires et bloque une seconde installation.

Pas de Composer, Node.js ou clé d'accès GitHub à saisir sur l'hébergement. L'installateur ne remplace jamais une application préexistante.

## CodeGraft natif dans la V3

Lorsque MCP est activé, ouvrir **`/admin/mcp`** :
- Choisir précisément les dossiers lisibles et modifiables et les outils autorisés.
- Vérifier l'URL OAuth sur **`https://votre-domaine/mcp`**.
- Autoriser les déploiements seulement après validation de la configuration et une nouvelle authentification propriétaire.
- Consulter les connexions OAuth, journaux, versions et restaurations.

**Trois verrous pour publier :** scope OAuth `app:deploy`, validation du propriétaire dans `/admin/mcp`, et `ATELIER_CODE_UPDATES_ENABLED=true` dans le `.env` privé. L'atelier relit les autorisations à chaque appel.

Le coffre privé CodeGraft doit impérativement rester en dehors de tout document root. Si l'hébergement emploie plusieurs racines web, définir `CODEGRAFT_VAULT_DIR` avec un chemin absolu privé.

[Documentation CodeGraft intégrée](V3_CODEGRAFT.md)

## Validation avant mise en production

Les tests automatisés GitHub Actions ont réussi : génération, installation depuis le paquet autonome, validations PHP et cycle préparation → déploiement → rollback CodeGraft.

**À tester sur un sous-domaine jetable avant production :** parcours d'installation HTTP réel sur o2switch, OAuth ChatGPT, permissions, changements multifichiers, restauration et sauvegarde de la base. La bêta n'est pas une mise à niveau automatique de l'ancienne V3 : ne pas décompresser sur l'installation existante.

Voir la [présentation principale](../README.md) et les [Releases officielles](https://github.com/titomus/flightphp-starter-kit/releases).
