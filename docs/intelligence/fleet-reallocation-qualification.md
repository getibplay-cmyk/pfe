# Qualification scientifique de la réallocation de flotte

## Décision finale

La qualification préenregistrée a réussi : `QUALIFIED_FOR_CONSULTATIVE_SAAS_INTEGRATION_REVIEW`.

Le protocole `fleet-reallocation-v1.0.0.json`, le générateur, les baselines, les seuils et la règle de décision ont été figés dans le commit `e970822409eac55f6d9b2343d3fd31a52f0722c6`, avant la CI finale. Le lot qualifie uniquement la méthode `SimpleMinCostFlow`. Il ne contient encore aucune intégration Laravel et ne modifie aucune donnée métier.

## Résultats observés

| Méthode | Demande servie | Demande non servie | Taux de service | Coût de décision synthétique (centimes) |
|---|---:|---:|---:|---:|
| `no_relocation` | 384 / 732 | 348 | 52,4590 % | 348 000 000 |
| `greedy` | 552 / 732 | 180 | 75,4098 % | 182 346 500 |
| `ortools_min_cost_flow` | 720 / 732 | 12 | 98,3607 % | 19 545 000 |

Les 48 résolutions ont reçu le statut `OPTIMAL` et satisfait les invariants de conservation, capacité, demande et coût recalculé. Le temps maximal observé pour le seul appel solveur est `0,032959 ms` sur le runner CI ; cette mesure dépend de la machine et n'inclut pas la préparation du réseau.

Les douze demandes non servies correspondent aux pénuries totales introduites dans douze scénarios. Le solveur n'invente aucun véhicule.

## Problème modélisé

Pour une catégorie de véhicule et un horizon donnés, chaque agence d'origine fournit un nombre entier de véhicules. Chaque agence de destination porte une demande entière. Une voie dirigée contient :

- une capacité entière de transfert ;
- une distance explicitement exprimée en kilomètres ;
- un coût unitaire entier en centimes de MAD.

Le solveur minimise :

`coût de réallocation + véhicules non servis × pénalité synthétique`.

La pénalité sert uniquement à prioriser le service dans le benchmark. Elle ne représente ni une facture, ni un tarif, ni une estimation comptable RentFleet.

## Données et abstention CatBoost

Les 48 scénarios sont synthétiques, déterministes et dépourvus de donnée personnelle, tenant ou agence réelle. Ils testent notamment des capacités de transfert dirigées pour lesquelles une décision locale gloutonne peut empêcher une meilleure allocation globale.

CatBoost ayant échoué à son gate scientifique, aucune probabilité issue de ce modèle n'est utilisée. La probabilité de présence vaut `1,000000` : la demande brute n'est jamais réduite artificiellement. Ce fallback conservateur est étiqueté `CATBOOST_RESEARCH_GATE_NOT_PASSED_CONSERVATIVE_NO_DISCOUNT`.

Le benchmark prouve une méthode d'optimisation sur données synthétiques. Il ne prouve ni un gain local RentFleet, ni un coût réel, ni une performance future.

Le coût de décision combine réallocation et pénalité synthétique. Sa forte amélioration ne doit jamais être présentée comme une économie en MAD réellement observée.

## Baselines

1. `no_relocation` sert uniquement la demande locale avec les véhicules déjà présents.
2. `greedy` sert d'abord localement, puis parcourt une fois les voies par distance, origine et destination croissantes, sans anticipation globale.
3. `ortools_min_cost_flow` résout le réseau entier sous les mêmes capacités et coûts.

## Gates préenregistrés

- 100 % des statuts OR-Tools doivent être `OPTIMAL` ;
- 100 % des solutions doivent respecter conservation, demandes et capacités ;
- taux de service agrégé au moins égal à 80 % ;
- demande non servie agrégée strictement inférieure aux deux baselines ;
- coût de décision agrégé strictement inférieur aux deux baselines ;
- chaque résolution doit rester sous 5 secondes dans l'environnement de qualification.

## Sécurité et portée produit

Même en cas de réussite, la sortie restera consultative. Elle devra être importée de manière idempotente, tenant/agence dérivés côté serveur et pseudonymisés, puis validée par un humain. Elle ne pourra modifier automatiquement réservation, contrat, tarif, facture, véhicule ou transfert.

Le statut local restera `NOT_VALIDATED_NO_REAL_HISTORY` jusqu'à une évaluation shadow sur un historique RentFleet suffisant.

## Reproductibilité

- Python `3.12.13` ;
- OR-Tools `9.15.6755` ;
- seed `20260814` et `PYTHONHASHSEED=20260814` ;
- snapshot synthétique SHA-256 `53d79202807b2952dc95154e0116153664f202007807a0855a16cbea63cc4214` ;
- dépendances transitives figées dans `requirements-fleet-reallocation.txt` ;
- artefact CI et checksums conservés sous `docs/evidence/intelligence/fleet-reallocation`.

Preuve d'exécution : [GitHub Actions CI #24](https://github.com/getibplay-cmyk/pfe/actions/runs/31852107373).

## Sources primaires

- Google for Developers, [Minimum Cost Flows](https://developers.google.com/optimization/flow/mincostflow).
- Google OR-Tools, [API Python `SimpleMinCostFlow`](https://or-tools.github.io/docs/pdoc/ortools/graph/python/min_cost_flow.html).
- Google OR-Tools, [version 9.15](https://github.com/google/or-tools/releases/tag/v9.15).
- PyPI, [distribution officielle `ortools` 9.15.6755](https://pypi.org/project/ortools/9.15.6755/).
