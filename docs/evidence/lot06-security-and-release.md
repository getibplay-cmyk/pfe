# Preuves du lot 06 — sécurité et release candidate sans IA

## Référence avant durcissement

- Laravel 12.63.0, PHP 8.5.8, Composer 2.10.1 et PostgreSQL 18.4 ;
- `pdo_pgsql` et `pgsql` actifs ;
- base de développement `rentfleet`, base de tests `rentfleet_test` ;
- 50 migrations appliquées, 51 tables ;
- **104 tests, 374 assertions** avant le lot 06 ;
- Pint, build Vite, config/route/view/event cache réussis.

## Contrôles de la release candidate

Résultats observés le 14 juillet 2026 :

- réinitialisation/migrations/seeders sur `rentfleet_test` : réussite ;
- suite complète : **121 tests, 495 assertions**, lots 00 à 06 verts ;
- `composer audit --locked` : aucun avis de vulnérabilité ;
- `npm audit --omit=dev` : aucune vulnérabilité de production ;
- Pint correcteur et contrôle : réussite ;
- build Vite : 56 modules, réussite ;
- config, routes, vues et `optimize` : réussite ;
- `rentfleet:doctor` : contrôles critiques réussis ; les données de référence
  de la base locale vide sont signalées comme avertissements, pas masquées.

La preuve réelle de sauvegarde/restauration reste en attente de l’installation
locale d’un `pgpass.conf` et de la création administrative de
`rentfleet_restore_test`. Le test de garde confirme déjà qu’une restauration
vers `rentfleet` est refusée avant tout appel à `pg_restore`. Ne pas transformer
ce prérequis externe en faux résultat positif.

Les archives de sauvegarde restent hors Git. Cette preuve documente uniquement
les résultats non sensibles et les empreintes peuvent être conservées dans le
dossier de soutenance externe.
