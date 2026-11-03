# Flight Starter Kit V3 (préversion)

## Installation sur o2switch

1. Télécharger l'artifact ZIP V3 d'une exécution GitHub Actions réussie.
2. Décompresser **tout** le ZIP dans un répertoire dédié au projet.
3. Faire pointer le document root du domaine vers le sous-répertoire `public/`.
4. Activer HTTPS et PHP 8.2+ avec PDO SQLite, mbstring, ZIP, OpenSSL et FileInfo.
5. Ouvrir `https://votre-domaine/install.php`.
6. Lire le code créé dans `.flight-install-access` **à la racine privée**, puis déverrouiller.
7. Remplir le formulaire. Aucun jeton GitHub, terminal, Composer ou Node.js n'est nécessaire sur le serveur.

L'installateur refuse d'écraser un site existant. Il génère les fichiers applicatifs, les migrations et le compte propriétaire, puis supprime le paquet privé `.flight-package/` et son propre fichier public après réussite.

**Ne jamais configurer le domaine pour servir la racine du projet** : seuls les fichiers sous `public/` doivent être accessibles via HTTP.

## Déploiement MCP

Le déploiement de code nécessite simultanément :
- un accès OAuth valide avec le scope `app:deploy` ;
- l'approbation du propriétaire depuis `/admin/mcp` ;
- `ATELIER_CODE_UPDATES_ENABLED=true` dans le `.env` privé ;
- la validation de la proposition et `confirmed=true` lors du déploiement.

La permission du propriétaire est désactivée par défaut. Le retrait de la case dans l'administration révoque les nouveaux déploiements, même si le jeton OAuth possède encore le scope `app:deploy`. La lecture des fichiers métier et les demandes d'évolution sont des permissions distinctes. Les sauvegardes et restaurations du moteur MCP existant restent à valider sur un site de test.

## Vérifications avant fusion

- Exécution GitHub Actions V3 réussie avec un artifact ZIP.
- Installation sur un nouveau sous-domaine HTTPS o2switch.
- Test connexion administrateur, écran IA & MCP, activation/révocation et contrôle d'un déploiement refusé.
- Test déploiement confirmé, sauvegarde puis restauration.
- Vérification du poids de chaque paquet avec `python3 scripts/audit-v3-zip.py dist/flight-starter-v3.0.0-alpha.1.zip`.
- Aucune fusion dans `main`, suppression de branche ou migration d'application existante avant validation.

## Limites

Cette V3 est une préversion. Le ZIP peut être lourd parce qu'il contient un ensemble de dépendances Composer partagé et trois variantes de thèmes précompilées. Ne pas retirer une dépendance avant de confirmer qu'elle n'est utilisée par aucune des variantes.
