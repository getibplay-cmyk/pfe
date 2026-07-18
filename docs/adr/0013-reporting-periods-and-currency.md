# ADR 0013 — Périodes de reporting et séparation des devises

- Statut : accepté
- Date : 2026-07-18

## Contexte

Le rapport minimal et le dashboard utilisaient des définitions proches mais
distinctes. Certaines intersections acceptaient `ends_at >= report_start`, le
taux d’utilisation ne suivait pas les changements de statut véhicule et les
sommes financières pouvaient mélanger des devises.

## Décision

1. `BuildMinimalReport` devient le service canonique du rapport, de son export
   et des KPI mensuels repris sur le dashboard.
2. `ReportCriteria` transporte explicitement tenant, agences autorisées,
   `report_start`, `report_end` exclusif, fuseau et devise optionnelle.
3. Toute intersection suit `starts_at < report_end AND ends_at > report_start`.
4. Les dates d’interface sont converties dans `Africa/Casablanca` avant les
   requêtes ; la date de fin affichée reste inclusive, sa représentation métier
   est le début du jour suivant.
5. L’utilisation est calculée en secondes par PostgreSQL. Le numérateur contient
   les blocs actifs des quatre types et le dénominateur les intervalles véhicule
   aux statuts `active` ou `maintenance`, reconstruits depuis les historiques.
6. Les agrégats financiers sont groupés par code ISO de devise. Aucune valeur
   multi-devise ou taux de change n’est produit.
7. L’encaissement suit `posted_at`, les allocations et leur direction. Les
   paiements en attente sont exclus et les contrepassations soustraites.
8. Les exports sont streamés, neutralisés pour les tableurs, limités à 366 jours
   et 10 000 lignes pour les réservations, puis audités sans leur contenu.

## Conséquences

- Les frontières consécutives sont déterministes et non doubles.
- Un rapport historique financier reste explicable par les écritures
  comptabilisées avant sa fin exclusive.
- Le dashboard n’affiche plus un faux solde unique en présence de plusieurs
  devises.
- Douze index PostgreSQL spécialisés supportent les filtres tenant/agence/date.
- La photo flotte d’une période future est limitée au moment de consultation ;
  aucune prédiction n’est introduite.

## Alternatives écartées

- Additionner les devises dans la devise du tenant : absence de taux de change
  autoritatif et risque comptable.
- Calculer les durées ligne par ligne en PHP : moins précis et moins performant.
- Utiliser des périodes fermées : double comptage aux frontières consécutives.
- Ajouter une bibliothèque graphique : inutile pour le rapport Blade attendu.
