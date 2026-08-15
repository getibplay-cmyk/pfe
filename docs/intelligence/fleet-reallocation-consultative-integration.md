# Intégration consultative OR-Tools — réallocation de flotte

## Décision

Le solveur `SimpleMinCostFlow` qualifié dans la PR #11 peut être présenté dans
RentFleet sous la forme d’une **proposition synthétique à revoir**. Laravel
n’exécute pas OR-Tools et ne déplace jamais un véhicule. Il valide un JSON
privé, conserve sa forme canonique avec SHA-256, écrit un registre append-only
et exige une décision humaine séparée.

Cette intégration ne constitue ni une validation locale, ni une mesure
d’économie réelle. Le statut obligatoire reste
`NOT_VALIDATED_NO_REAL_HISTORY` jusqu’à l’existence d’un historique RentFleet
suffisant et d’un nouveau protocole chronologique gelé.

## Lignée fermée

- demande : `hgb_poisson::regularized` `j5-v1`, uniquement consultatif ;
- risque d’annulation : CatBoost refusé par son gate ;
- probabilité de présence : `1.000000` ;
- ajustement : `ABSTENTION_NO_DEMAND_REDUCTION` ;
- optimisation : `ortools_simple_min_cost_flow` `9.15.6755` ;
- statut solveur : `OPTIMAL` ;
- unité de distance : `km` uniquement ;
- coût de démonstration : 5,00 MAD par véhicule-km, calculé en centimes ;
- pénalité synthétique de demande non servie : 1 000 000 centimes ;
- effet métier : `NO_OPERATIONAL_ACTION`.

Le payload ne contient que des références `SYNTH-NODE-NNN`. Il refuse
`tenant_id`, `agency_id`, identifiants métier, coordonnées, identité client,
chemin privé, propriété inconnue et valeur numérique flottante non
contractuelle. Le tenant réel provient exclusivement du `TenantContext`.

## Validation applicative et PostgreSQL

Le validateur PHP recalcule notamment :

1. l’horizon exact entre `as_of_date` et `target_date` ;
2. l’égalité demande prévue = demande effective, puisque CatBoost s’abstient ;
3. le coût unitaire depuis la distance en kilomètres ;
4. le coût total de chaque ligne ;
5. la demande totale, le taux de service et l’objectif de décision ;
6. le gate de service `>= 0,80` et le temps solveur `<= 5 000 ms` ;
7. l’empreinte canonique d’idempotence.

PostgreSQL complète ces contrôles avec des contraintes, des clés composites de
tenant, un trigger de complétude différé, une vérification de l’acteur tenant
entier et des triggers interdisant toute mise à jour ou suppression des
propositions, mouvements et décisions.

## Revue humaine

Seul un utilisateur actif de niveau entreprise possédant
`prediction.demo.review` peut importer ou décider. Les utilisateurs limités à
une agence sont refusés, même s’ils peuvent consulter d’autres écrans
Intelligence.

Deux décisions append-only sont permises :

- `accepted_for_demo_review` : la proposition peut être utilisée dans le
  scénario de démonstration ;
- `rejected` : la proposition est conservée comme preuve refusée.

Dans les deux cas, aucun `vehicle`, `vehicle_block`, `reservation`,
`rental_contract`, `maintenance_order`, `invoice` ou `payment` n’est modifié.

## Contrat et sources officielles

Le schéma normatif est
`docs/intelligence/schemas/fleet-reallocation-proposal-v1.0.0.json`.

- Google OR-Tools, Minimum Cost Flows :
  <https://developers.google.com/optimization/flow/mincostflow>
- API Python `SimpleMinCostFlow` :
  <https://or-tools.github.io/docs/pdoc/ortools/graph/python/min_cost_flow.html>
- Laravel 12, validation des fichiers :
  <https://laravel.com/docs/12.x/validation#validating-files>
- PostgreSQL 18, contraintes et triggers :
  <https://www.postgresql.org/docs/18/ddl-constraints.html>
  et <https://www.postgresql.org/docs/18/sql-createtrigger.html>
- NIST AI RMF, interaction humain–IA :
  <https://airc.nist.gov/airmf-resources/airmf/appendices/app-c-ai-risk-management-and-human-ai-interaction/>

## Limites restantes

- La PR #9 HGB et la PR #11 restent distinctes et non fusionnées.
- Le payload de ce lot est synthétique ; aucune agence réelle n’y figure.
- La démonstration complète prévision → abstention → demande effective →
  réallocation → revue humaine appartient au slice suivant.
- Aucun gain local RentFleet ne peut être revendiqué.
