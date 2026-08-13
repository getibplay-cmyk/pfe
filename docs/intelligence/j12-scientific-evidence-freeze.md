# J12 — gel des preuves scientifiques avant J13

## Décision

Ce document gèle les preuves disponibles au 13 août 2026 sur le commit de base
`c1140ba6dfa0b5ea6a4d3f3d9ba418773a4ec8ee`. Il relie les protocoles
scientifiques J5 à J11, les contrats synthétiques intégrés en J12 et la preuve
logicielle fournie par la CI.

La décision d'entrée est `J13_CONSULTATIVE_DISABLED_ONLY`. J13 peut préparer
une interface consultative, des libellés prudents et une revue humaine
auditée. Il ne peut activer aucun modèle, appeler un solveur, importer des
sorties publiques, écrire dans une table métier ou revendiquer une validation
sur des données RentFleet réelles.

Ce gel documente les preuves disponibles ; il ne transforme pas un benchmark
public ou une fixture synthétique en preuve de précision locale. Une validation
pour un usage donné exige des preuves objectives liées à cet usage. Cette
séparation suit le NIST AI RMF, les Model Cards et les Datasheets for Datasets.

## Niveaux de preuve

| Niveau | Signification | Utilisation autorisée |
|---|---|---|
| `public_proxy_benchmark` | Expérience reproductible sur un domaine public voisin | Mémoire, comparaison méthodologique et libellé explicite « proxy public » |
| `synthetic_contract_proof` | Schéma, fixture, validation, idempotence et audit sans calcul de modèle | Démonstration d'intégration locale désactivée |
| `software_integration_proof` | Tests du code, RBAC, isolation, invariants PostgreSQL et CI | Preuve que les garde-fous logiciels fonctionnent |
| `rentfleet_local_validation` | Backtest temporel et revue humaine sur historique RentFleet compatible | Absent à la date du gel |
| `production_evidence` | Validation prospective, dérive, seuils locaux, rollback et supervision | Absent à la date du gel |

Les trois premiers niveaux ne remplacent jamais les deux derniers.

## Matrice des revendications

| Module | Preuve gelée | Gate | Revendication admissible | Revendications interdites | Statut J13 |
|---|---|---|---|---|---|
| Prévision de la demande | Benchmark public Munich, J5 | `CONFIRMED_FOR_OPTIMIZER_BENCHMARK`, audit 15/15 | Le HGB Poisson gelé surpasse les deux baselines déclarées sur le test public et peut alimenter un benchmark d'optimisation | Demande totale, demande non satisfaite, intervalle de confiance, validation marocaine ou RentFleet | Carte consultative synthétique seulement |
| Optimisation de flotte | Expérience conditionnelle J7 sur proxy public | `OPTIMIZER_CONDITIONAL_GATE_NOT_PASSED_NO_RETUNING`, audit 12/12 | Le MILP est faisable et réduit la demande non servie face à `no_relocation` dans ce benchmark | Supériorité face au greedy, gain financier réel, recommandation opérationnelle ou flotte RentFleet optimisée | Carte rejetée, aucune exécution de solveur |
| Maintenance prédictive | Benchmark Scania APS, J8 corrigé | `RESEARCH_GATE_NOT_PASSED_NO_RETUNING`, audit 13/14 | Étude méthodologique de classification coût-sensible sur camions Scania | Probabilité de panne d'une voiture RentFleet, maintenance automatique ou validation sur véhicules de location | Carte en attente, aucun score attaché à un véhicule |
| Usages atypiques | Benchmark public Munich, J9 | `RESEARCH_GATE_PASSED_PUBLIC_PROXY_NOT_FOR_SAAS`, audit 16/16 | Classement d'atypicité pour revue humaine, avec métriques sur perturbations synthétiques documentées | Fraude, danger, dommage, faute, culpabilité, probabilité ou sanction automatique | Carte consultative synthétique seulement |

Pour les quatre modules : `ready_for_saas=false`, `production_allowed=false`,
feature flag désactivé et décision humaine sans effet opérationnel.

## Cartes scientifiques consolidées

### Prévision de la demande

- Modèle gelé : `hgb_poisson::regularized`.
- Source : trajets publics de mobilité partagée à Munich, employés comme proxy.
- Test final chronologique : 60 jours, du 2 avril au 31 mai 2025.
- WAPE test : 15,23 %.
- Gain relatif face à la médiane mobile : 6,82 %.
- Dégradation validation vers test : 26,60 %.
- Écart WAPE entre fournisseurs : 5,21 points.
- Sous-couverture de la bande nominale à 90 % : 3,93 points.

La sortie P50/P90 est un scénario quantile de benchmark, pas une probabilité de
confiance. L'absence d'historique RentFleet compatible interdit l'activation.

### Optimisation de flotte

- Méthode : MILP conditionnel aux prévisions J5, sans nouveau modèle ML.
- Réduction de demande non servie face à `no_relocation` : 52,62 %.
- Gain de taux de service face à `no_relocation` : 9,16 points.
- Retard face à `greedy_p50` : 108 895,10 unités, soit 7,91 %.
- Le bootstrap J7 exclut la supériorité requise du MILP face au greedy.

Le résultat négatif est conservé. Aucun réglage après confirmation et aucune
présentation comme optimisation validée ne sont autorisés.

### Maintenance prédictive

- Source : Scania APS, camions lourds, 60 000 lignes d'entraînement et 16 000
  lignes de test public.
- Objectif gelé : `10 × FP + 500 × FN`.
- Coût logistique pondérée : 12 900.
- Coût HGB : 13 630.
- Le HGB évite 327 faux positifs mais ajoute huit faux négatifs ; surcoût net :
  730, soit 5,66 %.
- Une interruption a obligé une seconde lecture du test ; l'audit autoritatif
  reste 13/14.

La différence de domaine entre camions Scania et voitures de location interdit
toute inférence directe vers RentFleet.

### Usages atypiques

- Candidat public sélectionné en J9 : `robust_mad_top2`.
- Source : trajets publics dérivés de mobilité partagée à Munich.
- Taux de revue sur trajets publics non modifiés : 1,21 %.
- Rappel sur perturbations injectées : 1,0000.
- Précision@k sur perturbations injectées : 0,4551.
- Stabilité Jaccard multi-graines : 0,9212.
- Aucune étiquette réelle de fraude, danger, dommage ou faute n'existe.

Ces métriques mesurent uniquement la sensibilité aux quatre perturbations
synthétiques déclarées. Elles ne mesurent ni la prévalence ni la précision
métier réelle.

## Distinction obligatoire des deux lignées « anomalie »

Le benchmark scientifique J9 et l'artefact historique du Lot 07B1 sont deux
objets séparés :

| Objet | Identité | Données | Rôle autorisé |
|---|---|---|---|
| Benchmark public J9 | candidat sélectionné `robust_mad_top2` | trajets publics Munich | preuve proxy de classement d'atypicité, hors SaaS |
| Artefact historique Lot 07B1 | `rental_anomaly_iforest` `0.1.0`, seuil `0.5740760891923362` | données synthétiques | contrat d'adaptation historique et fallback de recherche, sans inférence dans Laravel |
| Fixture J11/J12 | module `rental_usage_anomaly`, `computation_status=not_run_synthetic_contract_fixture` | fixture synthétique scellée | preuve de schéma, revue et audit seulement |

J13 ne doit pas lire `config('intelligence.frozen_model')` pour décrire le
résultat scientifique J9. Il ne doit pas non plus qualifier la fixture J11 de
sortie Isolation Forest ou de sortie `robust_mad_top2` : aucun calcul n'y a été
exécuté.

## Carte des données

| Source | Population et rôle | Données absentes ou limites | Réutilisation SaaS |
|---|---|---|---|
| Munich Shared Mobility v2 | Mobilité partagée publique, proxy J5/J7/J9 | Pas de contrats RentFleet, pas de demande non satisfaite, trajets dérivés, distance orthodromique, aucune étiquette réelle d'anomalie | Sorties historiques non importables |
| Scania APS | Camions lourds, benchmark méthodologique J8 | Domaine véhicule différent, classe négative pas nécessairement saine, historique de test déjà observé | Aucun score attachable à un véhicule RentFleet |
| Fixtures J11 | Quatre enregistrements entièrement synthétiques | Aucune donnée client, coordonnée, CIN, permis ou identifiant réel | Démonstration locale désactivée seulement |
| Export RentFleet 07B1 v1.1 | Schéma local anonyme et non étiqueté pour étude future | Aucun label, aucune prédiction, aucun résultat de qualité locale | Export manuel contrôlé ; aucun import de prédiction |

Une future validation locale doit employer un split temporel, des identifiants
pseudonymisés par HMAC serveur, des décisions humaines vérifiées et des
métriques par tenant/agence compatibles avec l'usage prévu.

## Manifeste des artefacts J11 intégrés en J12

| Module | Artefact | SHA-256 attendu |
|---|---|---|
| Prévision | `resources/intelligence/j11/fixtures/demand_forecast.accepted.json` | `a1cd6ea351aa5c6b2fbe9ed93f42a4e25d5a0cf8d62376a860aa8be3cdfcd6a0` |
| Prévision | `resources/intelligence/j11/schemas/demand_forecast.v1.schema.json` | `4c065d70207885f2588c1e0b11f705c26303b0a1c7112b83730ebf1db496642f` |
| Optimisation | `resources/intelligence/j11/fixtures/fleet_optimization.rejected.json` | `c7358c99f7e938b47f353abdc2c7cafd8f0e49b6eaaf6170e6b816234e99db89` |
| Optimisation | `resources/intelligence/j11/schemas/fleet_optimization.v1.schema.json` | `516d33f7589fbf9ae7c7c0e591d0baa0ddd5414eaa1fce532dc7eff43af8dd48` |
| Maintenance | `resources/intelligence/j11/fixtures/predictive_maintenance.pending.json` | `de3380708b13b914f3ebda6df8689efa67b86bfcdd025653ea10110e9dd722eb` |
| Maintenance | `resources/intelligence/j11/schemas/predictive_maintenance.v1.schema.json` | `b514d26224a3fb6ef593daca444eba1696389f438da9d97bb1f7416459e14fc0` |
| Usages atypiques | `resources/intelligence/j11/fixtures/rental_usage_anomaly.accepted.json` | `5d1d30002307ce7c85636e724c64906a2578e05abf3763181053383c10e5fabc` |
| Usages atypiques | `resources/intelligence/j11/schemas/rental_usage_anomaly.v1.schema.json` | `bcfb4ab224ebd98d77ad1fb73cc216e49d5795536eaf3815e9031625272d055b` |

Le manifeste machine lisible associé est
`docs/intelligence/j12-scientific-evidence-manifest.json`. Le test
`J12ScientificEvidenceFreezeTest` vérifie que ces empreintes, décisions et
limites restent synchronisées avec `J11AdvisoryModule` et les fichiers réels.

## Preuves logicielles disponibles

- J10 : 20/20 contrôles de consolidation.
- J11 : 26/26 tests et 95/95 contrôles sémantiques.
- J12 ciblé : 16 tests et 236 assertions.
- Base `main` avant ce gel : 336 tests et 3 596 assertions dans la CI GitHub
  Actions réussie nº 31673087342.
- J12 refuse les champs inconnus, l'activation, le solveur, la fraude, la
  probabilité de panne, les coordonnées et les identifiants sensibles.
- Le stockage de démonstration est isolé, append-only, borné au tenant/agence,
  idempotent et sans écriture dans les tables métier.

Ces résultats démontrent les garde-fous logiciels. Ils ne démontrent aucune
qualité prédictive sur les opérations RentFleet.

## Gate d'entrée J13

J13 est autorisé seulement si toutes les conditions suivantes restent vraies :

- `mode=consultative_disabled_only` ;
- quatre modules exactement, sans cinquième modèle ni tarification dynamique ;
- `feature_flags_enabled=false` et `ready_for_saas=false` ;
- aucun entraînement, aucune inférence et aucun solveur ;
- aucune importation des sorties historiques publiques ;
- aucun effet sur véhicule, réservation, contrat, maintenance, facture,
  paiement ou client ;
- tenant et agence dérivés du contexte serveur ;
- revue humaine enregistrée avec `NO_OPERATIONAL_ACTION` ;
- libellé visible du niveau de preuve, de la source et de la limite ;
- fallback sûr, RBAC, audit, idempotence et rollback démontrés ;
- toute future activation renvoyée vers un lot distinct avec données locales,
  backtest temporel, seuils locaux et autorisation produit/sécurité.

## Provenance

- J5 : `J5_protocol.yaml`, gelé le 2 août 2026.
- J7 : `J7_protocol.yaml`, gelé le 8 août 2026.
- J8 : `J8_protocol.yaml` et résumé corrigé, gelés le 9 août 2026.
- J9 : `J9_protocol.yaml` et résumé scientifique, gelés le 9 août 2026.
- J10 : `J10_protocol.yaml` et `J10_scientific_summary.md`, gelés le 9 août
  2026.
- J11 : `J11_protocol.yaml` et `J11_scientific_summary.md`, gelés le 9 août
  2026.
- J12 : contrats, fixtures, adaptateur désactivé et tests versionnés dans ce
  dépôt.

## Références

- NIST AI RMF 1.0 : https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-ai-rmf-10
- NIST, validité et fiabilité : https://airc.nist.gov/airmf-resources/airmf/3-sec-characteristics/
- Model Cards for Model Reporting : https://research.google/pubs/model-cards-for-model-reporting/
- Datasheets for Datasets : https://arxiv.org/abs/1803.09010
- Munich Shared Mobility v2 : https://zenodo.org/records/16947276
- Scania APS : https://archive.ics.uci.edu/dataset/421/aps+failure+at+scania+trucks

