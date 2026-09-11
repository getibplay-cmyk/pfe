# ADR 0019 — Atelier SaaS de réentraînement, calcul Colab et stockage Drive privé

Date : 2026-09-11. Statut : proposé dans la PR de l’atelier de réentraînement.

## Besoin

Réutiliser les nouvelles observations du SaaS et des sources supplémentaires, éventuellement regroupées entre entreprises, pour entraîner et comparer de nouvelles versions. L’administration doit pouvoir préparer et relancer une tentative sans exposer les données des entreprises ni charger le serveur de location.

## Décision

Conserver le monolithe Laravel/PostgreSQL comme plan de contrôle : jeux figés, consentement explicite du Tenant Owner, campagnes réservées à la plateforme, revue et audit. Colab exécute le calcul dans la session de l’opérateur ; Drive privé conserve les snapshots, poids et résultats. Le retour vers le SaaS est un rapport JSON fermé contenant les prédictions sur un test déterminé par le serveur.

La plateforme accède aux seules contributions autorisées. Elle n’utilise pas un contexte tenant implicite pour lire les données opérationnelles. Les relations tenant/auteur et tenant/contribution sont protégées par des clés étrangères composites. Les données et campagnes sont immuables ; seuls le premier partage explicite et la révocation sont permis sur un jeu. Les résultats et décisions sont append-only.

Les indicateurs sont recalculés par Laravel, avec contrôle de couverture du test et de la provenance du manifeste. La provenance réelle des prédictions et des poids demande encore la vérification humaine et technique. Aucun modèle téléversé n’est exécuté par Laravel, et aucun candidat n’est automatiquement activé. Les contraintes financières, contractuelles, de disponibilité et de responsabilité restent celles de l’architecture principale.

## Conséquences

Pas de nouvelle dépendance applicative ni de service d’entraînement permanent. Une session Colab nécessite un opérateur, des ressources disponibles et des poids sources appropriés. Les données supplémentaires ne garantissent pas un gain. Une révocation bloque les nouveaux usages SaaS ; les copies Drive et les modèles déjà déployés nécessitent une intervention traçable.

Le découpage permet de remplacer ultérieurement Colab par un worker de calcul dédié sans modifier les contrats de jeux/campagnes/rapports. Ce prolongement nécessite une décision de ressources, d’identité du service et d’exploitation. Il n’est pas simulé par une fausse queue Colab.

Voir le [guide opérationnel](../intelligence/model-retraining-workbench.md) pour le parcours complet, les limites de chaque mesure et les critères de qualification.
