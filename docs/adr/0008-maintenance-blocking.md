# ADR 0008 — Maintenance et indisponibilité

## Statut

Accepté — lot 05.

## Décision

Une maintenance `planned` ne bloque pas. Son approbation crée un `vehicle_block` de type `maintenance` sur l’intervalle semi-ouvert `[début, fin)`. La contrainte GiST `vehicle_blocks_no_active_overlap_excl` reste l’arbitre final des conflits avec réservations, contrats et autres blocages.

Le démarrage place le véhicule en `maintenance` via l’action commune de statut. La fin libère le bloc, met à jour kilométrage et échéances, et peut créer une dépense brouillon. Le retour à `active` exige une confirmation humaine explicite.

## Conséquences

Aucune réservation future n’est supprimée automatiquement. Un conflit est traduit en message métier, mais la garantie demeure PostgreSQL.
