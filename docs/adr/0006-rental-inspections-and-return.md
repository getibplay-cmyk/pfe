# ADR 0006 — Inspections, retour et dommages

## Statut

Accepté pour le lot 04.

## Décision

L’activation exige un contrat `accepted`, une inspection de départ `completed`,
un véhicule opérationnel, un permis valide et le bloc contractuel actif. Le
kilométrage et le carburant de départ deviennent les références du contrat.

Une inspection terminée et ses éléments sont immuables par triggers PostgreSQL.
Le retour crée une seconde inspection, interdit un kilométrage inférieur au
départ, compare les éléments et passe le contrat à `return_pending`.

Les calculs monétaires utilisent des chaînes décimales et des unités mineures,
jamais des `float`. Les retards, kilomètres supplémentaires, manques de
carburant et nettoyages produisent des frais `proposed`. Le serveur calcule
`total_amount`; PostgreSQL vérifie `total = quantity × unit_amount`.

Une différence d’inspection peut suggérer un dommage, mais la responsabilité
reste toujours `pending` jusqu’à une revue humaine. Seule une décision humaine
`customer` avec coût approuvé crée un frais dommage. Pour une sévérité `major`
ou `critical`, l’interface recommande une mise hors service ; la transition du
véhicule exige encore une confirmation humaine via l’action existante.

## Retour et disponibilité

`MarkRentalReturned` exige l’inspection retour, la décision finale de chaque
dommage et une décision explicite pour chaque frais proposé. Il met à jour les
totaux, le kilométrage du véhicule et libère uniquement le bloc rattaché au
contrat. Les blocs futurs restent intacts, y compris lors d’un retour tardif.
Les conflits futurs potentiels sont audités sans contourner la contrainte GiST.

Les photos réutilisent les documents privés avec `inspection_photo` et
`damage_photo`; aucune URL publique n’est générée.

## Conséquences

- les constats de départ et retour restent probants et non réécrits ;
- les frais sont explicables et soumis à approbation ;
- aucune automatisation ne tranche un litige client ;
- `vehicle_blocks` demeure l’unique source de disponibilité planifiée.
