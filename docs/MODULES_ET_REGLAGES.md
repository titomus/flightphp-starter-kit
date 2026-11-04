# Flight Starter Kit : installateur et modules administrables

## Philosophie

Le **ZIP installable** contient les dépendances PHP et les thèmes Vite précompilés. L'installateur reste rapide, mais propose un volet optionnel de personnalisation. L'administration permet ensuite de configurer les modules sans éditer le code source ni lancer CodeGraft.

## À l'installation

- **Projet** : SaaS, outil métier, blog ou vitrine (ces deux derniers sont des bases, pas des CMS finis).
- **Structure** : comptes membres, MCP + CodeGraft (le MCP doit être choisi à la création).
- **Intégrations à préparer** : Stripe, Google Login, GitHub Login, SMTP. Les dépendances sont déjà dans le runtime distribué mais aucun fournisseur n'est activé sans ses identifiants.
- **Apparence** : choisir parmi les trois thèmes compilés (SaaS, Gestion, Éditorial), puis facultativement les couleurs primaire et secondaire. Cela s'applique via les variables CSS à l'exécution et ne nécessite pas Node.js.
- **Sécurité** : le domaine doit être HTTPS et pointer vers \`public/\`. Compte propriétaire et mot de passe uniques.

Le choix « Préparer Stripe » n'ouvre pas les paiements : cela nécessite plus tard les clés, les prix réels et l'activation dans l'administration. Idem pour Google/GitHub et l'envoi SMTP.

## Dans l'administration : /admin/settings

Un menu **Modules & réglages** permet de modifier :

- **Apparence** : couleurs principale et secondaire, chemin d'une image de logo déjà déposée dans \`public/assets/\` (pas d'upload ni de modification de police dans cette bêta).
- **SEO** : description par défaut et séparateur des titres.
- **Comptes membres** : activation et ouverture/fermeture des inscriptions. Les comptes existants ne sont pas supprimés.
- **Email** : mode journal ou SMTP, expéditeur, hôte, port, identifiant et mot de passe. Le mode SMTP ne s'active que si la configuration est complète et la dépendance présente.
- **Stripe** : clés publique/secrète, secret webhook, offres mensuelle/annuelle/unique à activer, vrais \`price_...\` et montant affiché en euros. Une offre désactivée n'est pas présentée ni utilisable au paiement.
- **Google / GitHub** : Client ID, Client Secret et activation après vérification de leur présence. L'URL de redirection doit toujours être configurée chez le fournisseur.
- **CodeGraft** : lien vers l'administration MCP dédiée. Les autorisations de déploiement restent séparées des modules métier.

Chaque sauvegarde demande le **mot de passe du propriétaire** et passe par la protection CSRF du Starter. Les changements s'appliquent sur les **nouvelles requêtes**, sans remplacer les classes du framework.

## Secrets et stockage

Les réglages se trouvent dans **\`app/storage/config/runtime.json\`**, hors document root, avec permission de fichier \`0600\`. Le système n'écrit pas les clés API dans des vues HTML ou dans les logs, et les champs secrets ne sont pas préremplis.

**Attention :** il s'agit d'un stockage privé en clair sur le disque du serveur, pas d'un coffre chiffré. La sécurité dépend des permissions de l'hébergement et de sa configuration. Le dossier \`app/storage/\` ne doit jamais être accessible publiquement. Inclure ce fichier dans les sauvegardes privées, pas dans les archives publiques de l'application.

Les changements sont limités à une liste blanche de clés. CodeGraft ne peut pas modifier le gestionnaire de configuration ni le coffre privé ; les composants d'authentification restent protégés.

## Limites et précautions

1. La sélection du thème **complet** (structure/typographies précompilées) est une décision de départ ; l'admin sait changer les couleurs mais ne recompilera pas Vite.
2. Stripe utilise de vrais Price IDs ; **aucune validation réseau Stripe n'a lieu lors de la saisie**. Vérifier que les montants de la page de pricing correspondent réellement à ceux du tableau de bord Stripe, et tester en mode test avant les paiements réels.
3. Les intégrations sociales nécessitent leur configuration OAuth côté Google/GitHub (URL de retour et consentement).
4. Le module MCP n'est pas installable après coup depuis l'admin. S'il manque, une mise à niveau technique sera requise.
5. Les nouvelles installations bénéficient du gestionnaire. **Ne pas décompresser ce ZIP sur une V3 existante** : aucune migration in situ n'a encore été publiée.
6. La bêta est testée en CI mais reste à valider sur un vrai sous-domaine o2switch : session admin, clés, retour OAuth, paiement Stripe test et mail SMTP test.

[Documentation MCP Native Actions](MCP_NATIVE_ACTIONS.md) | [Téléchargements officiels](https://github.com/titomus/flightphp-starter-kit/releases)
