# Qualification CatBoost — risque d’annulation/no-show

## Décision gelée

Le candidat `cancellation_risk_catboost` **ne passe pas** le gate public :

| Critère obligatoire | Seuil | Test chronologique final | Décision |
|---|---:|---:|---|
| Balanced accuracy | ≥ 0,80 | **0,610399** | Échec |
| Macro-F1 | ≥ 0,80 | **0,610644** | Échec |

La décision machine est
`RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION`. Aucun score CatBoost n’est
donc ajouté à l’interface, à une réservation ou à un contrat. Le modèle gelé
est un artefact de recherche négatif : il permet de reproduire et d’expliquer
le refus, pas d’effectuer une inférence RentFleet.

Ce résultat n’est pas une accuracy locale. RentFleet ne possède toujours pas
d’historique réel suffisant et son statut reste
`NOT_VALIDATED_NO_REAL_HISTORY`.

## Source et licence

La source primaire est *Hotel booking demand datasets* de Nuno António, Ana de
Almeida et Luis Nunes, DOI
[`10.1016/j.dib.2018.11.126`](https://doi.org/10.1016/j.dib.2018.11.126).
Elle décrit 40 060 réservations d’un resort et 79 330 réservations d’un hôtel
urbain, avec les éléments d’identification supprimés. L’article et les données
sont publiés sous [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

Le snapshot de travail est le miroir TidyTuesday épinglé au commit
`1f5a20eae51d871ec4ac0f95d16e43b9ba3f1dec` :

- fichier : `hotels.csv` ;
- taille : 16 855 599 octets ;
- lignes : 119 390 ;
- SHA-256 :
  `7c2ae42a7353905ea136e5c2287f17c92c5435826598bfbb8491c6f0c7b1fc06`.

Le CSV brut n’est pas commité. Le script refuse une empreinte, un nombre de
lignes ou un schéma différents.

## Contrat RentFleet-compatible

Le candidat final utilise uniquement quinze variables qu’un futur export
RentFleet peut produire sans donnée personnelle brute : agence pseudonymisée,
catégorie pseudonymisée, présence de caution, dates dérivées, délai avant
départ, durée, historique antérieur du client et présence d’options.

Le mapping exact se trouve dans
`docs/evidence/intelligence/cancellation-risk/feature-mapping.csv`. Pour une
future validation locale :

- `tenant_id` et l’agence sont dérivés du contexte serveur ;
- leurs clés d’export sont des HMAC, jamais des identifiants de base ;
- tout historique client est calculé strictement avant le cutoff ;
- le fuseau local est `Africa/Casablanca` ;
- l’unité de distance canonique reste `km`, même si ce modèle n’utilise aucune
  distance ;
- aucun nom, e-mail, CIN, permis, téléphone, plaque ou texte libre n’entre dans
  le modèle.

Les colonnes suivantes sont exclues comme cible ou fuite postérieure :
`is_canceled`, `reservation_status`, `reservation_status_date`,
`assigned_room_type`, `booking_changes` et `days_in_waiting_list`. Elles ne
figurent pas dans `FEATURES`, et la CI vérifie cette séparation.

## Prétraitement et protocole

Le pipeline `hotel-booking-to-rentfleet-v1.0.0` :

1. vérifie SHA-256, schéma et nombre de lignes ;
2. reconstruit la date d’arrivée et la date de réservation ;
3. forme la cible `annulation ou no-show` uniquement depuis les statuts finaux ;
4. exclut 715 lignes à séjour nul, incompatibles avec l’intervalle RentFleet
   `[début, fin)` ;
5. conserve les doublons du fichier public, faute d’identifiant de réservation
   permettant de prouver qu’ils sont artificiels ;
6. trie de façon stable par date d’arrivée, date de réservation et numéro de
   ligne source ;
7. crée cinq blocs calendaires contigus, sans mélange aléatoire.

| Bloc | Période | Lignes | Taux cible | Usage unique |
|---|---|---:|---:|---|
| Entraînement | 2015-07-01 → 2016-09-08 | 59 673 | 35,9007 % | Ajustement initial |
| Validation | 2016-09-09 → 2017-01-05 | 19 077 | 38,0301 % | Itération/early stopping |
| Calibration | 2017-01-06 → 2017-03-25 | 11 186 | 33,7028 % | Isotonic uniquement |
| Seuil | 2017-03-26 → 2017-06-12 | 15 288 | 42,8768 % | Seuil de décision |
| Test final | 2017-06-13 → 2017-08-31 | 13 451 | 38,5548 % | Gate final |

CatBoost est réentraîné en CPU mono-thread sur les deux premiers blocs avec le
nombre d’itérations choisi sur la validation. Le mono-thread stabilise aussi
l’artefact et ses checksums. Le déséquilibre est traité uniquement dans les blocs
d’entraînement avec `auto_class_weights=Balanced`. La calibration isotonic est
ajustée sur son bloc disjoint. Le seuil `0,377` maximise de manière déterministe
le minimum entre balanced accuracy et macro-F1 sur le bloc de seuil.

La documentation CatBoost confirme la prise en charge native des variables
catégorielles, des probabilités et des graines :
<https://catboost.ai/docs/en/concepts/python-reference_catboostclassifier>.
La calibration emploie des données séparées conformément à la documentation
scikit-learn : <https://scikit-learn.org/stable/modules/calibration.html>.

## Résultats du test final

| Mesure | Valeur |
|---|---:|
| Balanced accuracy | 0,610399 |
| Macro-F1 | 0,610644 |
| PR-AUC | 0,585140 |
| ROC-AUC | 0,690498 |
| Brier calibré | 0,207954 |
| Brier brut | 0,204580 |
| Delta Brier brut − calibré | **−0,003374** |
| ECE, 10 classes | 0,056037 |
| Log-loss | 0,598784 |

La calibration isotonic dégrade légèrement le Brier sur le test futur. Le
rappel de la classe annulation/no-show n’est que de `0,395874`. Ces éléments
confirment le refus ; l’AUC seule ne peut pas remplacer les deux gates imposés.

## Explicabilité

Les explications utilisent les valeurs SHAP natives de CatBoost sur un
échantillon déterministe de 2 000 lignes du test. Les cinq contributions
globales moyennes absolues les plus fortes sont : présence d’options, délai de
réservation, caution requise, annulations antérieures et agence proxy.

Une contribution SHAP décrit le calcul du modèle dans l’espace de marge brute ;
elle ne démontre ni causalité, ni faute du client, ni motif juridique. La
définition et l’additivité sont documentées par CatBoost :
<https://catboost.ai/docs/en/concepts/shap-values>.

## Reproduction

En Python 3.12 :

```bash
python3 -m venv .venv-cancellation
.venv-cancellation/bin/python -m pip install -r requirements/science-cancellation.lock
curl --fail --location --output /tmp/hotels.csv \
  "https://raw.githubusercontent.com/rfordatascience/tidytuesday/1f5a20eae51d871ec4ac0f95d16e43b9ba3f1dec/data/2020/2020-02-11/hotels.csv"
MPLCONFIGDIR=/tmp/rentfleet-mpl .venv-cancellation/bin/python \
  scripts/intelligence/train_cancellation_risk.py \
  --dataset /tmp/hotels.csv \
  --output /tmp/cancellation-risk-evidence
cd /tmp/cancellation-risk-evidence && sha256sum --check SHA256SUMS
```

Les artefacts gelés sont sous
`docs/evidence/intelligence/cancellation-risk/`. Le modèle CatBoost JSON
normalisé et le calibrateur `.joblib` y sont conservés uniquement pour
reproduire le résultat négatif. Le GUID aléatoire et l’heure de fin que
CatBoost inscrit dans ses exports sont retirés du JSON ; les arbres et tous les
paramètres d’inférence restent inchangés. Aucun code Laravel ne les charge.
Deux exécutions complètes et indépendantes du pipeline ont produit le même
`SHA256SUMS`, notamment
`cc11175c2af28e30d4ed15580cd6817a5f47d3173901d740cc447d01fa653b67`
pour le modèle JSON normalisé.

Le notebook Colab `notebooks/J15B_cancellation_risk_catboost.ipynb` clone et
checkout explicitement le commit scientifique
`f27985d35aa853653e3120a3ee3acb5289948319`, installe le lock, vérifie le
dataset, relance le pipeline et contrôle tous les checksums. Il est livré sans
sortie embarquée afin de ne pas confondre une exécution locale avec la preuve
gelée.

## Limites et prochain état autorisé

- Domaine hôtelier différent de la location automobile.
- Deux hôtels seulement, période 2015–2017 et forte dérive temporelle.
- Pas d’identifiant de réservation permettant de résoudre les doublons.
- Certaines correspondances (`deposit_required`, `has_options`) sont partielles.
- Aucune donnée RentFleet réelle, aucun Maroc et aucune mesure prospective.
- La calibration publique ne peut pas être transférée à un tenant local.

CatBoost reste donc absent de la chaîne SaaS. Le prochain vertical slice
autorisé est la qualification OR-Tools Min-Cost Flow ; il doit consommer HGB et
une probabilité de présence neutre/observée, sans inventer une sortie CatBoost.
