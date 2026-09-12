# Vérification locale des dix améliorations

Date : 2026-09-12. Branche : feature/saas-advanced-workflows.

## Résultats observés

| Contrôle | Résultat |
|---|---|
| Tests PHP autonomes (13 fichiers, sans base) | 64 tests, 2 065 assertions, tous réussis |
| Tests JavaScript | 98 tests, tous réussis |
| Pint sur le projet | Réussi |
| Compilation des vues Blade | Réussie |
| Syntaxe PHP, incluant les vues compilées | 1 238 fichiers contrôlés, aucune erreur |
| Build Vite | Réussi, 79 modules |
| Diff Git | Aucun défaut signalé par diff --check |
| Catalogue arabe | 4 278 entrées ; couverture des messages littéraux et parité des paramètres vérifiées |
| Migrations découvertes | 105, dont sept nouvelles |

Les tests autonomes couvrent notamment TOTP, les coûts calendaires en centimes, les
formateurs, les contrats de sortie IA, les protections de confirmation, le garde de base
de tests et les traductions. Les tests JavaScript couvrent aussi les cadres d'annotation,
la sérialisation du brouillon, les dates du planning et les messages français/arabe.

## Validation restant nécessaire

Les **36 nouveaux tests d'intégration PostgreSQL n'ont pas été exécutés dans cet
environnement**. Aucune instance de test utilisable n'était disponible. La base de
développement et le garde PostgreSQL n'ont pas été contournés.

La CI doit donc encore valider les migrations, contraintes composites, transactions,
autorisations interentreprises/interagences, consentements, concurrence de réservation
et écritures immuables. Le workflow comprend le filtre des nouvelles classes et la suite
complète. Ces contrôles sont nécessaires avant fusion.

Le build et la compilation ne constituent pas une recette visuelle sur téléphone.
La capture réelle, la reprise après coupure et les écrans RTL doivent être vérifiés dans
l'environnement de recette, ainsi que les services SMTP/CMI selon leurs guides existants.

Le [guide d'utilisation](../operations/advanced-saas-workflows.md) et
l'[ADR 0020](../adr/0020-advanced-saas-workflows.md) décrivent le périmètre livré.
