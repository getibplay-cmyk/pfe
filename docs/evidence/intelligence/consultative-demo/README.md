# Démonstration consultative S6 — données synthétiques

Ce paquet exécutable hors ligne relie, dans cet ordre, une entrée de prévision
synthétique conforme au contrat HGB D+1, l'abstention du CatBoost refusé, la
demande effective inchangée, une résolution OR-Tools Min-Cost Flow et l'import
privé soumis à une décision humaine explicite.

La prévision n'est pas présentée comme une inférence HGB réellement exécutée :
elle est un scénario synthétique gelé qui référence la PR #9. CatBoost n'est ni
chargé ni intégré. Toutes les distances sont en kilomètres. Les références de
nœuds sont synthétiques et le paquet ne contient ni tenant, ni agence réelle,
ni identité, ni coordonnée.

## Reproduction

Depuis l'environnement Python figé OR-Tools :

```bash
python scripts/intelligence/build_consultative_demo.py \
  --output docs/evidence/intelligence/consultative-demo
sha256sum --check docs/evidence/intelligence/consultative-demo/SHA256SUMS
```

Importer ensuite `fleet-reallocation-proposal.json` dans l'écran
« Propositions de réallocation OR-Tools ». Un Tenant Owner autorisé doit choisir
« accepter pour la démo » ou « rejeter ». Dans les deux cas, l'effet enregistré
reste `NO_OPERATIONAL_ACTION` : aucune réservation, aucun contrat, tarif,
facture, paiement, véhicule, bloc ou maintenance n'est modifié.

Le test fonctionnel PostgreSQL reproduit l'import, la décision append-only et
l'absence d'écriture dans les tables opérationnelles. Il ne remplace pas une
signature humaine réelle et ne valide aucune performance locale RentFleet.
La valeur de temps contenue dans la proposition est le maximum gelé de la
qualification à 48 scénarios ; chaque reproduction mesure aussi son solveur et
échoue si l'appel courant dépasse 5 secondes, sans intégrer ce temps variable
dans les octets déterministes.

## Sources techniques primaires vérifiées

- scikit-learn 1.6.1, `HistGradientBoostingRegressor` et perte Poisson :
  https://scikit-learn.org/1.6/modules/generated/sklearn.ensemble.HistGradientBoostingRegressor.html
- CatBoost, contrat officiel `predict_proba` (non appelé dans cette démo) :
  https://catboost.ai/docs/en/concepts/python-reference_catboostclassifier_predict_proba
- Google OR-Tools, Minimum Cost Flow :
  https://developers.google.com/optimization/flow/mincostflow
- NIST AI RMF 1.0 :
  https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-ai-rmf-10
