# ADR 0015 — Stratégie de sauvegarde, restauration et release

## Statut

Accepté — lot 06F-D2. Cette décision complète l’ADR 0011.

## Contexte

Une sauvegarde PostgreSQL seule ne restaure pas les documents privés. Une copie
de fichiers sans manifeste ne prouve ni intégrité ni cohérence. La démonstration
doit permettre une restauration réelle sans exposer de mot de passe et sans
risquer `rentfleet` ou `rentfleet_test`.

## Décision

Une sauvegarde valide est un ensemble indivisible, produit dans un chemin
explicite hors Git :

1. dump PostgreSQL custom avec `pg_dump --no-password` ;
2. archive du seul stockage privé, sans liens ni chemins sortants ;
3. manifeste JSON versionné avec date UTC, source, versions, commit, étapes,
   tailles, SHA-256, comptages agrégés et inventaire documentaire ;
4. conservation externe sur volume chiffré et restreint.

L’authentification des outils PostgreSQL passe exclusivement par
`pgpass.conf`/`.pgpass` ou `PGPASSFILE`. `APP_KEY` reste dans le gestionnaire de
secrets séparé : elle n’entre jamais dans l’archive mais doit être disponible au
processus de vérification pour contrôler le déchiffrement sans afficher les
valeurs.

La restauration automatisée accepte uniquement le nom exact
`rentfleet_restore_test`, le rôle `rentfleet_app`, `-ConfirmRestore` et une
racine documentaire hors dépôt. Elle refuse toutes les variantes et protège le
stockage vivant. `pg_restore` utilise `--single-transaction --clean --if-exists`
sans créer ni supprimer la base.

La vérification compare source et restauration par comptages agrégés, noms de
contraintes/triggers/index, manifeste et fichiers. Elle force dans son processus
`DB_DATABASE=rentfleet_restore_test`, une racine documentaire isolée et
`APP_ENV=restore-verification`, puis exécute le déchiffrement contrôlé,
`rentfleet:doctor` et le contrôle des routes.

## Conséquences

- une archive seulement créée n’est pas déclarée restaurable ;
- la preuve D2 reste bloquée si pgpass ou la base dédiée manque ;
- aucun rollback SQL automatique n’est prévu en production ;
- le rollback de code précède une restauration, utilisée en dernier recours ;
- les copies documentaires décompressées sont temporaires et supprimées avec
  une garde de chemin explicite.
