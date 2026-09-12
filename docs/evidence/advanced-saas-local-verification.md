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
| Catalogue arabe | 4 293 entrées ; couverture des messages littéraux et parité des paramètres vérifiées |
| Migrations découvertes | 105, dont sept nouvelles |

Les tests autonomes couvrent notamment TOTP, les coûts calendaires en centimes, les
formateurs, les contrats de sortie IA, les protections de confirmation, le garde de base
de tests et les traductions. Les tests JavaScript couvrent aussi les cadres d'annotation,
la sérialisation du brouillon, les dates du planning et les messages français/arabe.

## Vérification PostgreSQL en CI

Les **38 nouveaux tests d'intégration PostgreSQL n'ont pas été exécutés dans cet
environnement**. Aucune instance de test utilisable n'était disponible. La base de
développement et le garde PostgreSQL n'ont pas été contournés.

La CI de la [PR 36](https://github.com/getibplay-cmyk/pfe/pull/36) réalise ces
vérifications sur PostgreSQL 18.4. À l'exécution
[34696614838](https://github.com/getibplay-cmyk/pfe/actions/runs/34696614838), les
105 migrations et les **177 tests ciblés (2 183 assertions)** ont réussi. La suite
complète a exécuté 817 tests : 813 réussis et quatre anciens contrôles statiques
de vues à adapter aux libellés traduits. Les corrections et le filtre CI les
incluant ont été ajoutés ensuite. Le résultat final fait foi dans les contrôles
de la PR et les preuves de release générées par la CI ; ce relevé conserve les
résultats observés, sans attribuer un succès à une exécution non terminée.

Les corrections de CI incluent la clé composite des réservations, la recréation
des fonctions PostgreSQL après reconstruction de la base de test, les cookies du
navigateur dans les tests publics et les traductions de formulaires. Les trois
remarques de revue sont couvertes : identifiants UUID de l'audit, protection des
PDF contractuels et récupération des déplacements déjà confirmés après expiration.
Les mots de passe de test du bootstrap garantissent aussi les catégories requises
pour supprimer un échec aléatoire constaté sur le main précédent.

## Recette en environnement utilisateur

Le build et la compilation ne constituent pas une recette visuelle sur téléphone.
La capture réelle, la reprise après coupure et les écrans RTL doivent être vérifiés dans
l'environnement de recette, ainsi que les services SMTP/CMI selon leurs guides existants.

Le [guide d'utilisation](../operations/advanced-saas-workflows.md) et
l'[ADR 0020](../adr/0020-advanced-saas-workflows.md) décrivent le périmètre livré.
