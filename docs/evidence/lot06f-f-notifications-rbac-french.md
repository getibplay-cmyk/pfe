# Preuve — Lot 06F-F

## Référence de départ

- commit E2 : `1a0a14b feat: finaliser la validation navigateur et accessibilité lot 06F-E2` ;
- Laravel 12.63.0, PHP Herd 8.5.8 et PostgreSQL 18.4 ;
- base destructive autorisée et observée : `rentfleet_test` ;
- base `rentfleet` jamais réinitialisée ; les deux migrations additives ont été
  appliquées en batch 2 après un diagnostic confirmant 0 tenant et 0 rôle.

## Livrables

- deux migrations additives : centre de notifications et gouvernance RBAC ;
- cloche, compteur, aperçu, filtres, lecture/non-lecture et destinations sûres ;
- commande idempotente planifiée `notifications:generate-operational` ;
- rôles personnalisés, matrice de permissions, remplacement contrôlé et
  délégations explicites par agence ;
- traductions Laravel françaises et extension de `UiLabel` ;
- documentation d’architecture et de sécurité dédiée.

## Résultats observés pendant l’implémentation

- `migrate:fresh --seed --env=testing` : 66 migrations et seeders réussis sur
  PostgreSQL `rentfleet_test` ;
- suite dédiée : 10 tests, 75 assertions, toutes vertes ;
- régressions multitenant : 11 tests verts ;
- régression administration utilisateur ciblée : 1 test, 24 assertions, verte.

Les résultats de la suite complète, du build, des caches et des audits sont
complétés à la clôture uniquement avec les valeurs réellement observées.

## Validation finale observée

- suite PHPUnit complète : **268 tests, 2 647 assertions**, toutes vertes ;
- Pint correcteur et `--test` : réussis ;
- Vite 6.4.3 : 56 modules, build réussi ;
- vues Blade, configuration, routes et optimisation : caches réussis ;
- Composer : manifeste valide, aucune vulnérabilité du lockfile ;
- npm production : 0 vulnérabilité ;
- routes : 204, sans `register`, `signup`, `storage/*` ni suppression du profil ;
- scheduler : génération des notifications toutes les quinze minutes avec
  verrou partagé ;
- recherches statiques : 0 encodage corrompu, 0 libellé anglais ciblé, 0 champ
  Blade `tenant_id`/`agency_id`, 0 sortie technique sensible ;
- `git diff --check` : réussi ; `.env` et `.env.testing` ignorés et non suivis ;
- `rentfleet:doctor` : 66 migrations appliquées, PostgreSQL 18.4 et contraintes
  critiques au vert. Les avertissements locaux attendus concernent le mode
  développement, le heartbeat absent et l’absence volontaire de données.

Aucun commit n’a été créé pour ce lot.
