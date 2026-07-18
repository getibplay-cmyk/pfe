# Preuve Lot 06F-D1 — Reporting, KPI, exports et diagnostics

Date d’exécution : 2026-07-18. Environnement : PHP Herd 8.5.8, Laravel
12.63.0, PostgreSQL 18.4.

## Précontrôles `rentfleet`

Tous les contrôles ont été exécutés en lecture seule avant la première écriture :

- périodes réservation, contrat et bloc invalides : 0 ;
- blocs orphelins, sources invalides et chevauchements actifs : 0 ;
- allocations tenant/agence/client/devise incohérentes : 0 ;
- devises manquantes : 0 pour les sept tables financières contrôlées ;
- factures terminales incohérentes : 0 ;
- maintenances hors périmètre : 0 ;
- sinistres police/véhicule/contrat/dommage incohérents : 0 ;
- historique véhicule différent de l’état courant : 0.

Le premier contrôle sinistre a compté à tort un sinistre valide sans contrat.
La requête a été corrigée pour respecter la nullabilité métier, puis a retourné
0. Aucune donnée n’a été corrigée.

## Migration additive

`2026_07_27_000001_add_reporting_indexes` ajoute 12 index nommés pour les
événements de réservation, retours contrat, blocs actifs, écritures financières,
maintenances, sinistres et échéances. Elle a été appliquée sur `rentfleet` en
batch 12. Le doctor confirme `12/12 présents`.

## Validations obtenues

- `rentfleet:doctor` : succès ; 63 migrations, 0 période invalide,
  0 allocation incohérente, 0 bloc actif invalide, 12/12 index ;
- `migrate:fresh --seed --env=testing` : succès sur `rentfleet_test`, 63
  migrations et tous les seeders de démonstration ;
- suite D1 : 24 tests réussis, 77 assertions ;
- régressions dashboard, finance, réservations, maintenance, assurance, RBAC et
  reporting : 59 tests réussis, 559 assertions ;
- suite complète : 223 tests réussis, 1 438 assertions ;
- Pint correcteur : ordre des imports et guillemets sur trois fichiers D1 ;
  `pint --test` réussi ;
- Vite 6.4.3 : 56 modules transformés, build réussi ;
- compilation Blade : réussie ;
- `composer validate` : valide ;
- `composer audit --locked` : aucune vulnérabilité connue ;
- `npm audit --omit=dev` : 0 vulnérabilité ;
- routes : 192 routes, `reports.index` et `reports.export` présentes, aucune
  route `storage/*` ;
- `git diff --check` : réussi ; `.env` et `.env.testing` ignorés et non suivis.

Après la suite, `rentfleet_test` contient les 63 migrations et 0 tenant : les
seeders ont bien été validés lors du `migrate:fresh --seed`, puis les fixtures
de tests ont été annulées par `RefreshDatabase`. La base `rentfleet` conserve
ses deux tenants et n’a reçu que la migration additive d’index.
