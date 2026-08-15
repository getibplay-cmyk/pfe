# Fiche de données — benchmark synthétique de réallocation

## Objet

Cette suite sert à qualifier une méthode de flot à coût minimum avant toute intégration RentFleet. Elle ne décrit aucune entreprise, agence, personne ou flotte réelle.

## Origine et licence

Les données sont générées dans le dépôt par `qualify_fleet_reallocation.py` avec le seed `20260814`. Aucun dataset externe n'est redistribué. OR-Tools est utilisé sous licence Apache 2.0 ; les exemples Google ont uniquement guidé la modélisation.

## Composition

- 48 scénarios ;
- quatre clés d'agence synthétiques par scénario ;
- une catégorie synthétique et un horizon D+1 à D+7 ;
- 732 unités de demande cumulées ;
- distances déjà exprimées en kilomètres ;
- trois voies dirigées avec capacité par scénario ;
- douze scénarios contenant une pénurie inévitable d'un véhicule.

Le snapshot canonique possède l'empreinte SHA-256 `53d79202807b2952dc95154e0116153664f202007807a0855a16cbea63cc4214`.

## Construction

Chaque scénario conserve une demande locale puis place un surplus dans deux agences et un déficit dans deux autres. La voie la plus courte attire greedy vers une décision locale, alors qu'une autre agence ne peut desservir qu'une destination. Cette structure teste si une optimisation globale préserve la voie rare.

Cette construction est intentionnelle : il s'agit d'un test de stress de l'algorithme, pas d'un échantillon représentatif du marché marocain de location.

## Champs principaux

- `scenario_id` : identifiant synthétique ;
- `horizon_day` : horizon 1 à 7 ;
- `category_key` : catégorie pseudonyme ;
- `available_vehicles` : stock entier ;
- `gross_demand_forecast` : demande synthétique brute ;
- `presence_probability` : toujours `1,000000` à cause de l'abstention CatBoost ;
- `effective_demand` : identique à la demande brute ;
- `lanes` : origine, destination, capacité et `distance_km`.

## Données exclues

Aucun nom réel, adresse, téléphone, e-mail, CIN, permis, plaque, VIN, tenant, agence réelle, réservation, contrat, tarif ou facture n'est présent.

## Usages autorisés

- reproduction du benchmark ;
- comparaison avec `no_relocation` et `greedy` selon le protocole gelé ;
- démonstration pédagogique clairement étiquetée synthétique.

## Usages interdits

- présenter le taux de service comme performance locale ;
- déduire une économie réelle en MAD ;
- exécuter automatiquement les transferts proposés ;
- entraîner ou valider un autre modèle final ;
- remplacer une future recette shadow sur historique RentFleet.

## Qualité et limites

Le générateur, le seed et le hash rendent le contenu déterministe. Les scénarios restent petits, structurés et favorables à la distinction entre une heuristique myope et un optimum global. Ils ne couvrent pas trafic, temps de trajet, convoyeurs, multi-catégories, réservations urgentes ou incertitude de prévision.
