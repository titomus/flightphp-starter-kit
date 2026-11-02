# Publier Flight Starter Kit sur GitHub

## Le bon lien pour un abonné

Ne communiquez pas `Code → Download ZIP` pour une installation : ce bouton récupère le **code source** de la branche affichée.

Pour installer par formulaire, il faut une **Release** qui contient :
- `install.php` : le lanceur à déposer dans `public/`.
- `flight-starter-runtime.zip` : dépendances `vendor` et thèmes Vite déjà compilés.
- `flight-starter-runtime.zip.sha256` : empreinte pour contrôler l'intégrité du paquet.

La page des versions est : https://github.com/titomus/flightphp-starter-kit/releases

Après la publication d'une version non préliminaire, l'adresse https://github.com/titomus/flightphp-starter-kit/releases/latest pointera automatiquement sur **la dernière stable**. Les préversions bêta ne sont pas retenues par `/releases/latest`.

## Règles de version

- `main` = code source de référence, actuellement **bêta technique**, pas un paquet installable.
- `feature/...` = nouvelles fonctions non diffusées.
- `demo/...` = laboratoires et preuves de concept séparés.
- `v2.1.0-beta.1` = préversion destinée aux tests sur des sites vierges.
- `v2.1.0` = future stable uniquement après tests et revue de sécurité.

## Publication bêta depuis GitHub (sans console)

1. Vérifier que `main` contient la version voulue et que **Actions** est vert.
2. Aller dans **Actions → Flight Starter Kit 2.1 web installer → Run workflow**.
3. Sélectionner `main`, puis choisir un numéro **inédit**, par exemple `v2.1.0-beta.1`.
4. Le workflow compile Composer et Vite, vérifie le ZIP, installe un vrai site temporaire, contrôle ses routes HTTP et OAuth, et publie les trois pièces jointes dans une Release marquée **Pre-release**.
5. Télécharger l'archive de cette release et valider réellement le formulaire sur o2switch. Corriger les problèmes et créer une nouvelle version bêta, **sans remplacer silencieusement un ZIP déjà utilisé**.

À ce stade il n'y a **pas de stable automatique** : la publication stable restera une décision explicite après validation terrain. Ne pas annoncer une bêta comme une version prête à installer chez un client.

## Visibilité

Le dépôt Flight Starter Kit est **public**. Une Release publique est accessible par n'importe qui avec le lien. Pour limiter les téléchargements à des abonnés payants, distribuer plutôt les archives via un espace membre ; GitHub public ne distingue pas automatiquement les abonnés du reste des visiteurs.

## Ce que GitHub garde

Les pièces jointes d'une Release sont persistantes. Les artefacts des tests GitHub Actions sont temporaires et peuvent expirer. Pour des liens commerciaux, **préférer Releases**, pas les artefacts Actions.

Aucune archive n'est publiée par cette documentation seule. Il faut déclencher la publication bêta.
