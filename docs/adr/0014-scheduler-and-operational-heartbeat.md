# ADR 0014 — Scheduler et heartbeat opérationnel

## Statut

Accepté — lot 06F-D2.

## Contexte

Les expirations automatiques étaient planifiées, mais aucune preuve partagée ne
permettait de distinguer un scheduler sain d’un scheduler arrêté. Un fichier
local ne conviendrait pas à plusieurs instances et un worker de queue ou Redis
ajouterait une infrastructure sans usage métier réel.

## Décision

Laravel Scheduler reste l’unique planificateur applicatif. Trois événements sont
déclarés avec le fuseau `Africa/Casablanca`, `withoutOverlapping` et
`onOneServer` grâce au cache PostgreSQL partagé :

- `operations:scheduler-heartbeat` chaque minute ;
- `reservations:expire-pending` chaque minute ;
- `insurance:expire-policies` chaque jour à 00:15.

La table système `operational_heartbeats` contient uniquement la clé de
composant et `last_succeeded_at`. La commande utilise un upsert atomique et ne
stocke ni tenant, ni acteur, ni secret. `rentfleet:doctor` avertit en local si
la valeur est absente ou ancienne et échoue en production au-delà du seuil
configuré, cinq minutes par défaut.

Les deux expirations conservent leurs actions transactionnelles, verrous de
ligne, relecture d’état et historiques append-only. Une seconde exécution ne
produit aucune double transition.

## Conséquences

- le scheduler système doit appeler `schedule:run` chaque minute ;
- le cache `database` doit être partagé entre instances ;
- aucun worker de queue ni Redis n’est requis par cette release ;
- un heartbeat sain prouve l’exécution récente du scheduler, pas le succès de
chaque tâche métier : leurs erreurs restent dans les logs corrélés.
