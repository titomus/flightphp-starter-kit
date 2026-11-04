# Publier Flight Starter Kit

## Un seul téléchargement officiel

- **[Flight Starter Kit V3 v3.0.0-beta.2](https://github.com/titomus/flightphp-starter-kit/releases/tag/v3.0.0-beta.2)**
- **[ZIP autonome installable](https://github.com/titomus/flightphp-starter-kit/releases/download/v3.0.0-beta.2/flight-starter-v3.0.0-beta.2.zip)**
- **[Empreinte SHA-256](https://github.com/titomus/flightphp-starter-kit/releases/download/v3.0.0-beta.2/flight-starter-v3.0.0-beta.2.zip.sha256)**

Pour installer, communiquer **la Release GitHub**. `Code → Download ZIP` ne contient que les sources ; les artefacts GitHub Actions expirent et ne constituent pas un lien de distribution pérenne.

## Organisation du dépôt

- `main` : **seule branche de développement active** et référence du code.
- **Releases** : versions téléchargeables, immuables et associées à un tag.
- `archive/2026-11-04/...` : tags conservant les anciennes pointes de branches, pour récupération historique sans multiplier les branches actives.
- `v2.1.0-beta.1` : ancienne Release conservée pour traçabilité, **pas** la version à installer pour un nouveau projet.

## Cycle d'une nouvelle Release

1. Modifier le code sur `main` et vérifier que le workflow `Flight Starter V3 one ZIP validation` réussit.
2. Incrémenter **ensemble** la version dans `scripts/build-v3-one-zip.sh`, `.github/workflows/flight-v3-one-zip.yml` et les liens de `README.md`.
3. Le workflow compile les dépendances et les assets, exécute les tests de génération et d'installation, vérifie l'intégrité et le poids du ZIP, puis crée **une Release préliminaire** depuis `main` si le tag n'existe pas.
4. Vérifier les deux pièces jointes (ZIP et SHA-256) sur la page de Release et tester sur un sous-domaine o2switch vierge.
5. Ne jamais remplacer silencieusement une archive déjà diffusée. Utiliser un **nouveau numéro de version**.

Les préversions bêta ne sont pas forcément accessibles via `/releases/latest`, qui privilégie les versions stables. Pour les utilisateurs, privilégier le lien de Release indiqué sur la page d'accueil GitHub.

## Niveau de confiance

La publication V3 est une bêta technique validée par CI, pas une version prête à écraser les sites existants. Les tests réels sur o2switch, OAuth et restauration restent nécessaires pour une stable.

Le dépôt est **public**. Une Release publique est accessible à tous. Pour distribuer seulement aux abonnés, utiliser un espace membre ou un canal de livraison privé plutôt qu'un lien GitHub public.
