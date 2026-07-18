# Preuve Lot 06F-D2 — sauvegarde et restauration réelle

Contrôle exécuté le 2026-07-18. Début de l’empreinte source :
`2026-07-18T15:33:03.7543411Z`. Cette preuve ne contient ni secret, ni
identité, ni valeur déchiffrée, ni chemin de document privé individuel.

## Périmètre et outils observés

| Élément | Valeur observée |
|---|---|
| Branche / HEAD | `main` / `137318b2571ce30495b5a5de822381cd2f70efd6` |
| PHP explicite | Laravel Herd 8.5.8 |
| PostgreSQL serveur / outils | 18.4 / `psql`, `pg_dump`, `pg_restore` 18.4 |
| PowerShell | 5.1.19041.6456 |
| Source | `rentfleet`, utilisateur `rentfleet_app` |
| Cible isolée | `rentfleet_restore_test`, utilisateur `rentfleet_app` |
| Base de tests, non restaurée | `rentfleet_test` |
| Migrations | 64 sur la source et 64 sur la restauration |

Le fichier `pgpass.conf` a seulement fait l’objet d’un test d’existence. Son
contenu n’a jamais été lu ou affiché. Les trois connexions ont réussi avec
`psql --no-password` et ont confirmé respectivement `rentfleet`,
`rentfleet_test` et `rentfleet_restore_test`, toutes sous `rentfleet_app`.
Les trois bases ont des OID distincts.

## Empreinte source avant sauvegarde

- 54 tables publiques ;
- empreinte agrégée de toutes les lignes :
  `8deb3e0fbadaa0a6bf44960a0c7fdd9b032857d8327bb54fbab60b8a072613d0` ;
- deux contraintes GiST : `vehicle_blocks_no_active_overlap_excl` et
  `insurance_policies_no_active_overlap_excl` ;
- 23 triggers applicatifs non internes ;
- 14 index critiques suivis par le manifeste ;
- 4 versions documentaires courantes ;
- 227 fichiers privés, 15 141 octets, aucun point de réanalyse ;
- empreinte de l’arbre documentaire :
  `3ceaa25651095937b429703aec69bdb48fa953c32aea4fbe41e78675b7828736`.

Le heartbeat a été rafraîchi avant cette empreinte, puis
`rentfleet:doctor --expect-database=rentfleet` a réussi avec un heartbeat
récent. Cette écriture opérationnelle est antérieure à l’empreinte de référence
et ne modifie aucune donnée métier.

## Sauvegarde réellement exécutée

Commande logique, sans secret :

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/backup.ps1 `
  -DatabaseName rentfleet `
  -OutputDirectory C:\tmp\RentFleetBackups
```

| Mesure | Résultat |
|---|---|
| Répertoire conservé | `C:\tmp\RentFleetBackups\rentfleet-rentfleet-20260718-153411320Z` |
| Durée interne du script | 13,185 s |
| Durée murale | 14,199 s |
| Dump custom | 364 896 octets |
| SHA-256 dump | `f9d71bfb0c2bd070eec42a0953f40d70bb87168faf08032b94819dee472fe789` |
| Archive privée | 64 797 octets |
| SHA-256 archive | `d77df9cc3cbca9493b7c4b88d1b47ea9d9a60ec3a903d840732bf3f61bb6d3ef` |
| Documents déclarés | 227/227 |

Les tailles et SHA-256 recalculés correspondent au manifeste. L’archive
contient zéro entrée interdite et zéro chemin sortant. Aucun `.env`, `APP_KEY`,
log, cache, session, build, contenu public, lien symbolique ou point de
réanalyse n’a été détecté. Les artefacts sont hors du dépôt et absents de
`git status`.

## Restauration isolée réellement exécutée

La cible littérale, son utilisateur, son OID distinct, le manifeste et les
SHA-256 ont été revérifiés avant l’action. Commande logique, sans secret :

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/restore.ps1 `
  -BackupDirectory C:\tmp\RentFleetBackups\rentfleet-rentfleet-20260718-153411320Z `
  -DatabaseName rentfleet_restore_test `
  -PrivateDocumentsTarget C:\tmp\RentFleetRestoreDocuments\run-20260718-153411320Z `
  -ConfirmRestore
```

La restauration a duré 10,789 s. `pg_restore` a utilisé
`--single-transaction --clean --if-exists --no-password`. La base n’a pas été
supprimée ni recréée. Ni `rentfleet`, ni `rentfleet_test`, ni
`storage/app/private` n’ont été ciblés.

## Vérification réelle de la restauration

Commande logique, avec `PHP_BINARY` fixé sur Herd 8.5.8 et sans affichage de la
clé applicative :

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/verify-restore.ps1 `
  -BackupDirectory C:\tmp\RentFleetBackups\rentfleet-rentfleet-20260718-153411320Z `
  -DatabaseName rentfleet_restore_test `
  -PrivateDocumentsPath C:\tmp\RentFleetRestoreDocuments\run-20260718-153411320Z
```

La vérification a duré 12,401 s et a réussi. Elle a confirmé :

- `current_database() = rentfleet_restore_test` et 64 migrations `Ran` ;
- 54/54 tables avec zéro différence de comptage et zéro différence de lignes ;
- l’empreinte de contenu restaurée identique à la source ;
- 227 fichiers, 15 141 octets et l’empreinte documentaire identique ;
- 4/4 versions documentaires courantes avec taille et SHA-256 conformes ;
- 25 valeurs chiffrées déchiffrables sans afficher leur valeur ;
- les huit contraintes critiques du manifeste, dont les deux GiST et les clés
  composites d’agence/tenant et d’allocation financière ;
- 23/23 triggers par nom et définition, dont 8/8 triggers d’immutabilité et
  5/5 contrôles assurance signalés par le doctor ;
- 14/14 index critiques, dont les 12 index de reporting et les deux unicités
  maintenance ;
- aucune route `storage/*`, `register` ou `signup`.

`rentfleet:doctor --expect-database=rentfleet_restore_test` a explicitement
confirmé la cible et a terminé avec succès. Il a signalé deux avertissements
attendus : environnement `restore-verification` et heartbeat vieilli depuis
l’instant du snapshot, car aucun scheduler permanent ne tourne pendant la
preuve manuelle.

### Comptages métier comparés

| Domaine | Comptages source = restauration |
|---|---|
| SaaS / accès | 2 tenants, 3 agences, 6 rôles, 71 permissions, 9 utilisateurs |
| Parc / tiers | 4 catégories, 18 véhicules, 12 clients, 12 conducteurs |
| Location | 11 réservations, 9 blocs, 6 contrats, 6 versions contractuelles |
| Finance | 2 factures, 2 paiements, 2 allocations, 4 mouvements de caution, 0 dépense |
| Maintenance | 2 ordres |
| Assurance | 1 compagnie, 1 police, 1 garantie, 1 sinistre |
| Exploitation | 79 audits, 1 heartbeat |

Les catalogues source et cible contiennent les mêmes 811 contraintes
applicatives et les mêmes 203 index, sans objet manquant ou supplémentaire.
PostgreSQL réécrit après restauration 44 expressions `CHECK` et deux prédicats
d’index partiel sous une forme de cast équivalente (`varchar[]` vers `text[]`).
Les noms, colonnes, valeurs admises, nombres d’objets et contrôles critiques
restent identiques ; ce point est un avertissement de normalisation textuelle,
pas une divergence de schéma.

## Non-régression de la source

Après restauration :

- `current_database() = rentfleet` ;
- l’empreinte de toutes les lignes reste exactement
  `8deb3e0fbadaa0a6bf44960a0c7fdd9b032857d8327bb54fbab60b8a072613d0` ;
- les 54 tables et 64 migrations restent identiques à l’avant-sauvegarde ;
- les 227 fichiers privés, leur taille et leur empreinte restent identiques ;
- `.env` et `.env.testing` conservent un horodatage antérieur au début de la
  preuve et restent ignorés/non suivis ;
- le doctor source confirme explicitement `rentfleet` et réussit.

Le doctor source avertit que le heartbeat vieillit pendant la preuve manuelle.
Sa ligne existe et la configuration du scheduler est valide ; en exploitation,
le service `schedule:run` doit être déclenché chaque minute pour le garder
récent.

## Validations et état des artefacts

- tests D2 rejoués : 18 tests, 124 assertions, succès ;
- sécurité, doctor et stockage privé rejoués : 33 tests, 179 assertions,
  succès ;
- suite complète précédemment obtenue sans modification PHP ultérieure :
  241 tests, 1 562 assertions ;
- Pint `--test` : succès ;
- analyse syntaxique PowerShell : 4/4 scripts valides ;
- `composer validate` : succès ;
- `composer audit --locked` : aucune vulnérabilité signalée ;
- `npm audit --omit=dev` : 0 vulnérabilité ;
- `git diff --check` : succès ;
- la base `rentfleet_restore_test` est conservée pour réinspection ;
- le dump, l’archive et le manifeste sont conservés ensemble ;
- la copie documentaire isolée exacte
  `C:\tmp\RentFleetRestoreDocuments\run-20260718-153411320Z` a été supprimée
  après tous les contrôles, conformément à l’autorisation explicite ;
- une première tentative de nettoyage a reçu un refus d’accès Windows ; la
  garde de chemin a été rejouée, les attributs en lecture seule ont été retirés
  uniquement sous cette cible exacte, puis la suppression contrôlée a réussi.

Aucun artefact, `pgpass.conf`, `.env`, `.env.testing` ou document restauré n’est
suivi par Git. Aucun commit n’est créé par cette preuve.
