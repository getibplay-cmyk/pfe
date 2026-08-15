# J15-B — index scientifique reproductible RentFleet

État gelé le 15 août 2026. Cet index relie les preuves scientifiques sans
copier un résultat entre branches et sans rouvrir un test final. Les benchmarks
publics ou synthétiques valident une méthode ; ils ne démontrent aucune
performance locale RentFleet.

## Décisions finales

| Composant | Décision | Test ou benchmark final | Intégration autorisée |
|---|---|---|---|
| HGB Poisson D+1 à D+7 | référence publique qualifiée | WAPE 0,152342 ; MASE 0,829556 | import consultatif uniquement |
| CatBoost annulation/no-show | résultat négatif gelé | balanced accuracy 0,610399 ; macro-F1 0,610644, sous les deux gates à 0,80 | non |
| OR-Tools `SimpleMinCostFlow` | qualifié | 48/48 solutions valides ; service 98,3607 % ; 12 demandes non servies | proposition consultative uniquement |

Le HGB et OR-Tools ne deviennent pas des modèles « précis localement ». Leur
statut local reste `NOT_VALIDATED_NO_REAL_HISTORY`. CatBoost n'est jamais chargé
par le SaaS : la chaîne utilise l'abstention conservatrice, donc une probabilité
de présence de `1.000000` et aucune réduction de demande.

## Lignée GitHub immuable

| Élément | PR brouillon | Révision de preuve | CI de référence |
|---|---|---|---|
| Adaptateur HGB consultatif | [#9](https://github.com/getibplay-cmyk/pfe/pull/9) | `d5355bd475d76a4377f95089b2402e5f8cf071f1` | [#21](https://github.com/getibplay-cmyk/pfe/actions/runs/31843463099) |
| CatBoost refusé, artefacts et notebook | [#10](https://github.com/getibplay-cmyk/pfe/pull/10) | `92c56ceba3a671169ae0e3e77687eae1d4c6ab0a` | [#23](https://github.com/getibplay-cmyk/pfe/actions/runs/31850088428) |
| OR-Tools, démonstration et dossier HGB | [#11](https://github.com/getibplay-cmyk/pfe/pull/11) | `d645b4e8aac32f24fa2feaabf8124e97078834d8` | [#33](https://github.com/getibplay-cmyk/pfe/actions/runs/31863024785) |

`main` reste la base commune `31163492fdbfe634546117e1178bfdb6cdfef143`.
Aucune de ces PR n'est fusionnée. La CI du commit qui ajoute cet index et la
checklist constitue la preuve de clôture S6 ; son URL est consignée dans la
conversation de la PR #11 afin de ne pas créer de référence circulaire dans les
octets vérifiés.

## Inventaire reproductible

### HGB — prévision de demande

- notebook : `notebooks/J15B_demand_forecast_hgb.ipynb` ;
- Model Card : `demand-forecast/demand-forecast-hgb-model-card.md` ;
- fiche de données : `demand-forecast/munich-shared-mobility-datasheet.md` ;
- environnement et versions : `environment.json`, `requirements-frozen.txt` ;
- split, seed, métriques et décision : `qualification-manifest.json` ;
- tableau et figure : `final-test-comparison.csv`, `benchmark-comparison.svg` ;
- intégrité : `notebook-manifest.json`, `SHA256SUMS`.

Le dossier est `docs/evidence/intelligence/demand-forecast/`. Le dataset Munich
est un proxy public. Sa notice Zenodo ne précise pas de licence de dataset : les
données brutes ne sont donc pas redistribuées et cette limite est explicite.

### CatBoost — annulation/no-show, résultat refusé

Les artefacts se trouvent au commit immuable de la PR #10 :

- notebook : `notebooks/J15B_cancellation_risk_catboost.ipynb` ;
- Model Card : `docs/intelligence/model-cards/cancellation-risk-catboost.md` ;
- fiche de données et licence CC BY 4.0 :
  `docs/intelligence/data-sheets/hotel-booking-demand.md` ;
- split chronologique, mapping, calibration et SHAP :
  `docs/evidence/intelligence/cancellation-risk/` ;
- résultat négatif, figures, tableaux, manifeste et `SHA256SUMS` dans ce même
  dossier.

La décision `RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION` est définitive pour
ce protocole. Le test final n'est pas rouvert et aucun modèle CatBoost n'est
intégré.

### OR-Tools — réallocation consultative

- notebook : `notebooks/J15B_fleet_reallocation_ortools.ipynb` ;
- Model Card et fiche du benchmark synthétique :
  `docs/evidence/intelligence/fleet-reallocation/` ;
- environnements, seed, scénarios, temps, tableaux, figure, manifeste et
  checksums : même dossier ;
- contrat d'intégration :
  `docs/intelligence/fleet-reallocation-consultative-integration.md` ;
- démonstration synthétique :
  `docs/evidence/intelligence/consultative-demo/`.

Toutes les distances sont exprimées en kilomètres. Les coûts sont synthétiques
et ne sont ni des économies réelles ni des montants validés pour une agence.

## Reproduction minimale

```bash
python -m unittest -v \
  tests/Python/test_fleet_reallocation_qualification.py \
  tests/Python/test_consultative_demo.py \
  tests/Python/test_demand_forecast_j15b_evidence.py \
  tests/Python/test_s6_scientific_dossier.py

(cd docs/evidence/intelligence/demand-forecast && sha256sum --check SHA256SUMS)
(cd docs/evidence/intelligence/fleet-reallocation && sha256sum --check SHA256SUMS)
(cd docs/evidence/intelligence/consultative-demo && sha256sum --check SHA256SUMS)
(cd docs/evidence/intelligence && sha256sum --check S6_SHA256SUMS)
```

La reproduction complète OR-Tools utilise Python `3.12.13` et les dépendances
figées. La suite applicative utilise PostgreSQL ; SQLite ne constitue pas une
preuve acceptée.

## Frontière opérationnelle

Aucune sortie Intelligence ne modifie automatiquement réservation, contrat,
tarif, facture, paiement, véhicule, bloc véhicule, maintenance ou réallocation.
Le tenant et l'agence sont dérivés côté serveur. Les paquets publics ne
contiennent ni secret, ni identité directe, ni donnée personnelle brute. Une
acceptation humaine signifie seulement « retenue pour la démonstration » et
conserve l'effet `NO_OPERATIONAL_ACTION`.

## Sources techniques primaires

- scikit-learn, `HistGradientBoostingRegressor` :
  https://scikit-learn.org/stable/modules/generated/sklearn.ensemble.HistGradientBoostingRegressor.html
- CatBoost, probabilités de classification :
  https://catboost.ai/docs/en/concepts/python-reference_catboostclassifier_predict_proba
- Google OR-Tools, Minimum Cost Flow :
  https://developers.google.com/optimization/flow/mincostflow
- NIST AI RMF 1.0 :
  https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-ai-rmf-10

