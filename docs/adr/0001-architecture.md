# ADR 0001 — Socle RentFleet

## Décision

RentFleet démarre comme un monolithe Laravel 12, avec PHP 8.5, PostgreSQL,
Blade/Tailwind et authentification Breeze. Livewire 3 est prévu pour les
interactions ciblées sans introduire React, Vue ou Inertia.

Le lot 00 ne contient aucune logique métier, multitenance, rôle ou permission.
Les pilotes de session, queue et cache utilisent PostgreSQL via les migrations
Laravel disponibles.

## Conséquences

Le projet reste simple à démarrer et testable sur une base PostgreSQL locale.
Les modules métier seront introduits par lots verticaux ultérieurs.
