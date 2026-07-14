# ADR 0011 — Sauvegarde, restauration et déploiement vérifiables

## Statut

Accepté — lot 06.

## Décision

Une sauvegarde RentFleet forme un ensemble indivisible :

1. un dump PostgreSQL `pg_dump` au format custom ;
2. son empreinte SHA-256 ;
3. une archive du seul stockage `storage/app/private` ;
4. l’empreinte de l’archive et un manifeste chemin/taille/SHA-256 ;
5. un résumé non sensible.

Les scripts ne lisent aucun fichier `.env`, n’acceptent aucun mot de passe en
argument et s’appuient sur les variables PostgreSQL standard et `pgpass` ou
`PGPASSFILE`. Le dossier `backups/` est ignoré par Git.

La restauration automatisée refuse toute base autre que
`rentfleet_restore_test`, vérifie les empreintes avant `pg_restore`, exige une
confirmation et restaure les documents dans
`storage/app/private-restore-test`. Elle ne crée, ne supprime ni ne remplace une
base de développement ou de production.

La vérification contrôle migrations, tables, tenants de démonstration,
documents privés, contrainte GiST, triggers contractuels et financiers, puis
confirme l’absence de route publique de stockage.

## Conséquences

- une archive non restaurée n’est pas considérée comme une preuve de reprise ;
- la création et l’accès à la base dédiée restent des tâches administratives ;
- chiffrement hors ligne, rétention, copie distante et supervision sont à
  configurer selon l’hébergeur et ne sont pas simulés dans le dépôt.
