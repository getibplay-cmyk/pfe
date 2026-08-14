# Model Card — `cancellation_risk_catboost` research-1.0.0

## Statut

**Rejeté pour le SaaS.** Gate public non atteint : balanced accuracy
`0,610399`, macro-F1 `0,610644`. Décision :
`RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION`.

## Modèle et objectif

- Famille : `CatBoostClassifier` 1.2.10, CPU mono-thread reproductible.
- Cible de recherche : annulation ou no-show avant l’arrivée planifiée.
- Usage envisagé : aide consultative soumise à validation humaine.
- Usage effectif autorisé : rapport scientifique et reproduction du résultat
  négatif uniquement.
- Hors périmètre : annulation automatique, accusation, tarification,
  facturation, blocage d’un client, changement de véhicule ou réallocation.

## Données

Hotel Booking Demand, 119 390 lignes brutes, CC BY 4.0, DOI
<https://doi.org/10.1016/j.dib.2018.11.126>. Après exclusion des séjours nuls :
118 675 lignes. Le test final est le dernier bloc chronologique, du 13 juin au
31 août 2017, avec 13 451 observations.

Le benchmark prouve uniquement une méthode sur un proxy public. Il ne prouve
aucune performance RentFleet, marocaine, automobile, tenant ou agence.

## Variables et prévention des fuites

Quinze variables compatibles ou partiellement compatibles avec le schéma
RentFleet sont utilisées. Les identités, pays, prix en devise étrangère, textes
libres et identifiants bruts sont exclus. Les variables postérieures ou cibles
`reservation_status`, `reservation_status_date`, `assigned_room_type`,
`booking_changes`, `days_in_waiting_list` et `is_canceled` ne sont jamais des
features.

## Entraînement

- Seed : `20260814`.
- Thread CatBoost : `1` pour stabiliser les artefacts.
- Export : JSON CatBoost normalisé sans GUID aléatoire ni heure de fin.
- Cinq blocs temporels disjoints : train, validation, calibration, seuil, test.
- Déséquilibre : `auto_class_weights=Balanced` sur l’apprentissage seulement.
- Itérations choisies : 63.
- Calibration : isotonic sur bloc disjoint.
- Seuil figé avant le test : `0,377`.
- Explication : Tree SHAP natif CatBoost sur marge brute.

## Évaluation

| Métrique finale | Valeur |
|---|---:|
| Balanced accuracy | 0,610399 |
| Macro-F1 | 0,610644 |
| PR-AUC | 0,585140 |
| ROC-AUC | 0,690498 |
| Brier | 0,207954 |
| Rappel annulation/no-show | 0,395874 |

La calibration n’est pas retenue comme bénéfice : son Brier est plus mauvais
de `0,003374` que celui des probabilités brutes sur le test.

## Explicabilité et risques

Les facteurs SHAP ne sont pas causaux. Une agence, une catégorie ou un
historique peuvent refléter des biais de collecte, de politique commerciale ou
de période. Ils ne doivent jamais être affichés comme motif de culpabilité ou
comme justification juridique.

Risques principaux : dérive temporelle, transfert hôtel→automobile, mapping
partiel, sous-détection de la classe cible, calibration non transférable et
absence de données locales.

## Gouvernance

- `validation_scope=PUBLIC_BENCHMARK` ;
- `local_rentfleet_status=NOT_VALIDATED_NO_REAL_HISTORY` ;
- `saas_integration_allowed=false` ;
- `automatic_action_allowed=false` ;
- `production_claim_allowed=false` ;
- nouvelle évaluation locale possible uniquement après accumulation d’un
  historique réel, split prospectif et autorisation humaine distincte.
