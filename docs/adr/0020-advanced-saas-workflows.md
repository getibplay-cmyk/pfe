# ADR 0020 — Parcours SaaS avancés et interface français/arabe

Date : 2026-09-12. Statut : implémenté sur la branche de travail, validation PostgreSQL et revue avant intégration.

## Contexte

L'exploitation quotidienne doit relier les demandes des locataires, la disponibilité, les inspections et les coûts. Les corrections humaines doivent alimenter l'atelier d'apprentissage de l'ADR 0019. Ces parcours conservent le monolithe Laravel, PostgreSQL, Blade, Alpine et les actions métier canoniques.

## Décisions

| Parcours | Donnée de référence | Validation et historique |
|---|---|---|
| Double authentification | Utilisateur et version de sécurité | TOTP sans rejeu ; codes de secours à usage unique ; révocation des sessions |
| Inspections guidées | Brouillon révisé, photos privées et inspection canonique | Révision attendue ; six vues ou explication ; finalisation humaine immuable |
| Annotations IA | Annotation vérifiée liée au résultat et à l'empreinte source | Couleur, plaque canonique ou cadres normalisés ; export privé ; ancien jeu révoqué après correction |
| Planning | Réservations et `vehicle_blocks` | Aperçu temporaire ; annulation/création/confirmation transactionnelles ; liaison ancien/nouveau dossier |
| Portail actif | Acceptation du client et version contractuelle | Nom et consentement liés à l'accès ; avenant offert par l'agence, accepté par le locataire |
| Catalogue public | Publication explicite par entreprise | Identifiants publics distincts ; devis temporaire ; demande sans bloc avant confirmation métier |
| Rentabilité estimée | Profils de coûts datés et versionnés | Calcul en centimes ; devises et registres réels séparés ; aucune écriture automatique |
| Qualité des modèles | Analyses et dernières annotations comparables | Dénominateurs visibles ; abstentions séparées ; alertes sans activation automatique |
| Espace personnel | Préférences du seul utilisateur | Recherche, favoris et filtres réautorisés à chaque lecture |
| Français/arabe | Locale utilisateur ou session anonyme | Interface traduite et RTL ; valeurs métier et formats techniques conservés |

Les nouveaux liens métier ont des clés étrangères composites tenant/agence. Le middleware public détermine le périmètre à partir du catalogue publié ; le portail détermine le client à partir d'un accès valide. Aucun de ces parcours ne réutilise l'identité d'un collaborateur connecté comme signataire client.

La contrainte GiST reste l'autorité finale de disponibilité. Un déplacement remplace une réservation confirmée sans contrat ; une prolongation crée une nouvelle version acceptée et étend le bloc existant. Aucun tarif ou document déjà accepté n'est réécrit.

Une prolongation exige un PDF propre à la proposition, son empreinte, les nouvelles conditions, l'accord de l'agence puis celui du locataire. Prix, disponibilité, permis, version et document sont revérifiés à l'acceptation. Une facture existante interdit ce parcours.

Les annotations ne modifient pas les résultats initiaux, les véhicules, les dommages facturés ou la responsabilité. Les exports d'images sont réencodés sans métadonnées. Le regroupement en campagnes exige une autorisation distincte ; les photos d'un même véhicule restent dans le même groupe. Le SaaS prépare et compare, Colab calcule, Drive privé conserve les fichiers. Qualification et déploiement restent séparés.

## Conséquences

Sept migrations additives portent le total à 105. Les données existantes restent utilisables ; MFA obligatoire et catalogue public sont désactivés par défaut. GD et ZIP sont nécessaires aux parcours d'images, sans nouvelle dépendance Composer ou npm.

Les brouillons d'inspection sont conservés côté serveur. En cas de coupure, seule la dernière sauvegarde confirmée est récupérable.

La marge estimée dépend des hypothèses et de leur couverture. Le complément d'assurance est calculé pour la période sélectionnée après déduction des dépenses approuvées. Il n'est pas additif entre fenêtres différentes ; les indicateurs réels restent séparés.

Les tests d'intégration doivent s'exécuter sur `rentfleet_test`, sous PostgreSQL, avant fusion. Voir le [guide des dix améliorations](../operations/advanced-saas-workflows.md).
