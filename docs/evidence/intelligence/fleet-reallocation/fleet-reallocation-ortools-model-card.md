# Model Card — réallocation OR-Tools Min-Cost Flow

## Identification

- Nom : `fleet_reallocation_ortools_min_cost_flow`
- Version scientifique : `1.0.0`
- Type : optimisation combinatoire déterministe, pas modèle probabiliste appris
- Bibliothèque : Google OR-Tools `9.15.6755`
- Décision : `QUALIFIED_FOR_CONSULTATIVE_SAAS_INTEGRATION_REVIEW`
- Statut local : `NOT_VALIDATED_NO_REAL_HISTORY`

## Usage prévu

Proposer à un responsable de flotte des transferts entiers de véhicules entre agences pour une catégorie et un horizon, en tenant compte des stocks, demandes, distances en kilomètres, capacités de transfert et coûts configurés.

La proposition doit rester séparée des écritures métier. Un humain peut l'accepter, la modifier ou la refuser. La qualification n'autorise aucune mutation automatique de réservation, contrat, tarif, facture, véhicule ou réallocation.

## Entrées

- véhicules disponibles entiers par agence ;
- demande effective entière par agence et catégorie ;
- voies dirigées, capacités entières et distances en kilomètres ;
- coût par kilomètre converti explicitement en centimes ;
- pénalité de demande non servie, versionnée et expliquée.

Dans le benchmark, CatBoost s'abstient. La probabilité de présence vaut `1,000000`, donc la prévision brute n'est pas réduite.

## Sorties

- statut du solveur ;
- transferts origine, destination, véhicules et kilomètres ;
- demande servie et non servie ;
- coût de réallocation et objectif synthétique ;
- facteurs nécessaires à une revue humaine.

## Résultats publics synthétiques

- 48 / 48 statuts `OPTIMAL` ;
- 48 / 48 solutions conformes aux invariants ;
- taux de service : `0,983607` ;
- demande non servie : 12, contre 180 pour greedy et 348 sans réallocation ;
- temps maximal du seul appel solveur : `0,032959 ms` sur le runner CI.

Ces résultats valident le comportement de la méthode sur une suite de stress synthétique conçue pour tester l'anticipation globale. Ils ne mesurent pas un gain, un coût ou une accuracy RentFleet locale.

## Limites

- quatre agences et une seule catégorie par scénario ;
- distances et coûts synthétiques, sans trafic, durée de convoyage ni disponibilité du personnel ;
- capacités dirigées simplifiées ;
- greedy est volontairement myope et ne représente pas tous les heuristiques possibles ;
- temps mesuré uniquement autour de `solve()` ;
- aucune validation sur historique réel ;
- aucune preuve de stabilité lorsque plusieurs catégories ou horizons interagissent.

## Risques et contrôles

- Une pénalité mal paramétrée peut privilégier un transfert économiquement inadapté : affichage, version et validation humaine obligatoires.
- Une distance incorrecte fausse le coût : unité `km` obligatoire et refus de toute unité inconnue.
- Un stock ou une demande périmés rendent le plan caduc : timestamp, empreinte du snapshot et expiration nécessaires à l'intégration.
- Les identifiants tenant/agence doivent être dérivés côté serveur et pseudonymisés dans tout export scientifique.

## Traçabilité

- protocole préenregistré : commit `e970822409eac55f6d9b2343d3fd31a52f0722c6` ;
- CI : `https://github.com/getibplay-cmyk/pfe/actions/runs/31852107373` ;
- snapshot SHA-256 : `53d79202807b2952dc95154e0116153664f202007807a0855a16cbea63cc4214` ;
- artefacts et empreintes : répertoire courant.
