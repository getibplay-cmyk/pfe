# Preuve Lot 06F-G2 — D3 post-migration

Date d’exécution : 26 juillet 2026  
Branche inspectée : `main`  
HEAD : `6f8d233`

Cette preuve ne contient ni mot de passe, ni DSN complet, ni clé applicative,
ni donnée personnelle, ni contenu de document privé.

## État attendu puis état découvert

Le premier cahier D3 attendait 66 migrations appliquées et trois migrations G2
`Pending`. La première tentative a donc été arrêtée lorsqu’elle a observé
l’état réel suivant :

- 69 migrations appliquées ;
- zéro migration en attente ;
- les trois migrations G2 dans le batch 2 ;
- cinq colonnes de cycle de vie des notifications ;
- trois triggers d’intégrité RBAC ;
- l’index `users_role_assignment_idx`.

La présente reprise a explicitement autorisé une sauvegarde D3
**post-migration** de cet état réel. Aucun rollback, nouvel appel à `migrate`,
seeder ou changement manuel de la table `migrations` n’a été exécuté.

## Investigation non destructive du batch 2

### Chronologie factuelle

| Horodatage UTC | Événement | Source | Certitude |
|---|---|---|---|
| 24 juillet, 21:46–22:32 | Création puis dernière modification des trois fichiers G2 | Métadonnées NTFS | Élevée pour les fichiers, aucune preuve d’exécution |
| 26 juillet, 01:26:50 | Dernière campagne navigateur G2 enregistrée | Métadonnée du JSON navigateur | Élevée |
| 26 juillet, 01:33:38 | La preuve de clôture G2 est enregistrée avec 66 migrations et G2 `Pending` sur `rentfleet` | Preuve G2 et métadonnée NTFS | Élevée pour l’observation documentée |
| Entre 01:33:38 et 08:48:24 | Fenêtre pendant laquelle l’état est passé à 69 migrations | Bornes des deux preuves | Moyenne : absence de journal SQL |
| 26 juillet, 08:48:24 | La première preuve D3 documente 69 migrations et les trois lignes G2 en batch 2 | Table `migrations`, objets PostgreSQL et preuve D3 | Élevée |
| 26 juillet, 09:01:43 | Fin de la sauvegarde D3 post-migration | Manifeste D3 | Élevée |

### Sources examinées et limites

- la table Laravel `migrations` confirme `66` lignes en batch 1 et les trois
  migrations G2 en batch 2, mais ne stocke aucun horodatage ;
- les trois migrations partagent le même batch, ce qui rend probable une seule
  exécution Laravel ayant traité les trois fichiers ;
- PostgreSQL 18.4 est configuré avec `log_statement=none` et
  `log_min_duration_statement=-1` : aucune trace de requête DDL exploitable
  n’est disponible au rôle applicatif ;
- le contenu de l’historique PowerShell n’a pas été lu : la garde de sécurité a
  refusé cette source, car elle peut contenir des identifiants ou tokens sans
  possibilité de masquage fiable ;
- le journal Laravel existe et a été modifié à 08:40:07 UTC, mais sa métadonnée
  seule ne prouve pas l’exécution des migrations ;
- les scripts versionnés `backup.ps1` et `restore.ps1` n’exécutent aucune
  migration ; `verify-restore.ps1` et `deploy-check.ps1` appellent seulement
  `migrate:status` ;
- l’historique Git reste sur `6f8d233` et n’attribue aucune opération locale.

Conclusion de l’investigation : **une exécution Laravel unique est probable,
mais l’heure exacte, la commande et l’opérateur restent indéterminés**. Aucune
attribution personnelle n’est possible avec les preuves disponibles.

## État source avant sauvegarde

Connexion explicitement contrôlée :

```text
database=rentfleet
user=rentfleet_app
PostgreSQL=18.4
OID=16387
```

| Objet | Source |
|---|---:|
| Taille PostgreSQL | 18 372 287 octets |
| Schémas applicatifs | 1 |
| Tables publiques | 57 |
| Vues | 0 |
| Séquences | 47 |
| Fonctions publiques | 239 |
| Triggers applicatifs | 30 |
| Contraintes | 856 |
| Index | 218 |
| Clés étrangères non validées | 0 |
| Migrations | 69 : batch 1 = 66, batch 2 = 3 |
| Extensions | `btree_gist` 1.8, `plpgsql` 1.0 |

Les comptages exacts des 57 tables ont été relevés sans afficher de ligne
métier. L’empreinte agrégée de leur contenu était :

```text
c3d84aa6fcc699b39ebce62ab019ae7c44726ccc17588e4322f7ff5ebaa847f3
```

Le stockage privé vivant contenait 419 fichiers, 38 549 octets et aucun point
de réanalyse.

Les neuf précontrôles d’intégrité G2 étaient tous à zéro. Le Doctor source
était vert ; l’absence de heartbeat permanent était le seul avertissement
local.

## Sauvegarde D3 post-migration

Commande logique sans secret :

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/backup.ps1 `
  -DatabaseName rentfleet `
  -OutputDirectory C:\tmp\RentFleetBackups
```

| Mesure | Résultat observé |
|---|---|
| Répertoire D3 | `C:\tmp\RentFleetBackups\rentfleet-rentfleet-20260726-090126033Z` |
| Format | dump PostgreSQL custom + ZIP privé + manifeste JSON |
| `pg_dump` | PostgreSQL 18.4 |
| Code de sortie | 0 |
| Durée interne / murale | 17,102 s / 17,992 s |
| Dump | `rentfleet-rentfleet-20260726-090126033Z.dump` |
| Taille du dump | 401 058 octets |
| SHA-256 du dump | `7fb3a398fc0ca963822fcb851eb9d184bf0179fd1cee77829dac81c92f945043` |
| Archive privée | `rentfleet-private-20260726-090126033Z.zip` |
| Taille de l’archive | 125 812 octets |
| SHA-256 de l’archive | `8e3c60961d596d51fbd8a2d1ffdfe577bf5ae0e74c74fe65b04fc1407c3d8e0f` |
| Documents déclarés | 419 |
| `pg_restore --list` | code 0, 778 entrées |

Le dump, l’archive et le manifeste sont non vides, hors dépôt et hors
`public/`. Le dossier D3 a été durci après création : héritage ACL désactivé,
accès conservé uniquement pour l’opérateur, SYSTEM et les Administrateurs,
zéro droit d’écriture générique. Les empreintes ont été revérifiées après ce
durcissement.

Le statut de chiffrement BitLocker du volume C n’était pas lisible dans le
contexte disponible. La protection observée et prouvée porte donc sur
l’isolation du chemin et ses ACL ; la copie de rétention définitive doit rester
sur un volume chiffré conformément au runbook.

## Restauration contrôlée

Les OID ont été contrôlés avant l’opération :

| Base | OID |
|---|---:|
| `rentfleet` | 16387 |
| `rentfleet_test` | 16675 |
| `rentfleet_restore_test` | 387455 |

Commande logique sans secret :

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/restore.ps1 `
  -BackupDirectory C:\tmp\RentFleetBackups\rentfleet-rentfleet-20260726-090126033Z `
  -DatabaseName rentfleet_restore_test `
  -PrivateDocumentsTarget C:\tmp\RentFleetRestoreDocuments\run-20260726-090126033Z `
  -ConfirmRestore
```

Le script a utilisé `pg_restore --single-transaction --clean --if-exists`
uniquement sur les objets de `rentfleet_restore_test`. Il n’a supprimé ou
recréé aucune base.

- code de sortie : 0 ;
- durée murale : 15,427 s ;
- avertissement PostgreSQL : aucun ;
- cible documentaire vivante jamais ciblée ;
- `rentfleet` et `rentfleet_test` jamais ciblées.

La copie documentaire restaurée a également reçu une ACL protégée sans droit
d’écriture générique. Elle contient les 419 fichiers attendus.

## Vérification versionnée

Commande logique :

```powershell
$env:PHP_BINARY = 'C:\Users\pc\.config\herd\bin\php85\php.exe'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/verify-restore.ps1 `
  -BackupDirectory C:\tmp\RentFleetBackups\rentfleet-rentfleet-20260726-090126033Z `
  -DatabaseName rentfleet_restore_test `
  -PrivateDocumentsPath C:\tmp\RentFleetRestoreDocuments\run-20260726-090126033Z
```

Résultat : code 0 en 10,509 s.

La vérification a confirmé :

- 69 migrations, dont les trois G2 en batch 2 ;
- manifeste, dump, archive, documents, tailles et SHA-256 conformes ;
- 25 valeurs chiffrées déchiffrables sans afficher leur valeur ;
- contraintes critiques, triggers et index attendus ;
- aucune route `register`, `signup` ou `storage/*` ;
- Doctor vert sur `rentfleet_restore_test`.

## Comparaison exhaustive source/restauration

| Élément | `rentfleet` | `rentfleet_restore_test` | Résultat |
|---|---:|---:|---|
| Migrations | 69 | 69 | identiques, mêmes batches |
| Tables | 57 | 57 | identiques |
| Vues | 0 | 0 | identiques |
| Séquences | 47 | 47 | noms et valeurs identiques |
| Fonctions | 239 | 239 | noms et définitions identiques |
| Triggers | 30 | 30 | noms et définitions identiques |
| Contraintes | 856 | 856 | mêmes noms et règles |
| Index | 218 | 218 | mêmes noms et règles |
| Extensions | 2 | 2 | `btree_gist`, `plpgsql` |
| Clés étrangères non validées | 0 | 0 | conforme |

Les 57 tables ont les mêmes comptages et les mêmes empreintes de contenu.
L’empreinte globale est identique :

```text
c3d84aa6fcc699b39ebce62ab019ae7c44726ccc17588e4322f7ff5ebaa847f3
```

Les empreintes des 69 lignes de migration et des 47 états de séquence sont
également identiques. Les 47 séquences possédées ont été comparées à la valeur
maximale de leur colonne : zéro incohérence.

Les mêmes huit tables sont vides des deux côtés :
`cache`, `cache_locks`, `expenses`, `failed_jobs`, `job_batches`, `jobs`,
`operational_heartbeats` et `password_reset_tokens`. Aucune table vide
inattendue n’est apparue.

### Normalisations textuelles expliquées

PostgreSQL a réécrit après `pg_restore` :

- 47 expressions de contraintes `CHECK` basées sur des listes de valeurs ;
- les prédicats de `maintenance_orders_reporting_schedule_idx` et
  `payments_reporting_posted_idx`.

La source utilise notamment `ARRAY[...]::text[]`, tandis que la restauration
place le cast `::text` sur chaque élément. Les valeurs admises, colonnes,
opérateurs, noms et nombres d’objets sont identiques. Il s’agit de la
normalisation textuelle PostgreSQL déjà observée lors de D2, pas d’une
différence fonctionnelle.

## Objets G2 et intégrité restaurée

La source et la restauration contiennent chacune :

- cinq colonnes de cycle de vie des notifications ;
- trois triggers RBAC ;
- un index `users_role_assignment_idx` ;
- trois migrations G2 en batch 2.

Les neuf compteurs d’intégrité G2 valent zéro sur les deux bases :

| Contrôle | Source | Restauration |
|---|---:|---:|
| Rôle personnalisé hors entreprise | 0 | 0 |
| Rôle plateforme sur utilisateur d’entreprise | 0 | 0 |
| Rôle d’entreprise sur administrateur plateforme | 0 | 0 |
| Rôle inactif affecté | 0 | 0 |
| Utilisateur d’entreprise sans rôle | 0 | 0 |
| Administrateur d’entreprise avec agence | 0 | 0 |
| Rôle d’agence sans agence | 0 | 0 |
| Administrateur plateforme dans un tenant | 0 | 0 |
| Délégation incohérente | 0 | 0 |

Les documents possèdent zéro incohérence de version courante et zéro
métadonnée de taille ou SHA-256 invalide.

## Contrôles applicatifs non destructifs

L’application a été lancée avec des variables de processus temporaires :

```text
APP_ENV=restore-verification
DB_DATABASE=rentfleet_restore_test
PRIVATE_DOCUMENT_ROOT=<racine restaurée isolée>
```

Aucun fichier `.env` n’a été modifié.

Résultats :

- Laravel 12.63.0 et PHP 8.5.8 ;
- conteneur Laravel démarré ;
- Doctor vert avec 69 migrations ;
- trois tenants, quatre agences, sept rôles, 74 permissions et dix
  utilisateurs lus sans identité affichée ;
- véhicules, clients, conducteurs, réservations, contrats, factures,
  paiements, maintenances et assurances lisibles ;
- contexte tenant/agence résolu puis restauré correctement ;
- rôle et permissions eager-loadés ;
- boîte de notifications lue sans mutation ;
- vue de connexion rendue sans session ;
- douze documents et douze versions documentaires contrôlés par métadonnées.

Le premier rendu Blade direct a échoué faute du `ViewErrorBag` normalement
fourni par le middleware HTTP. Le contrôle a été relancé avec un sac d’erreurs
vide, sans changement de code ni écriture en base, puis a réussi.

## Fumée finale sur `rentfleet`

Les contrôles ont utilisé uniquement des lectures directes, sans requête HTTP
ni session :

- 69 migrations `Ran`, zéro `Pending` ;
- Doctor vert ;
- cinq colonnes, trois triggers et un index G2 présents ;
- neuf contrôles d’intégrité à zéro ;
- route et vue de connexion présentes ;
- route et boîte de notifications lisibles ;
- sept rôles, 74 permissions et neuf délégations lisibles ;
- aucune génération de notification ou modification de rôle.

L’empreinte finale de `rentfleet` reste exactement celle calculée avant la
sauvegarde. Le stockage privé vivant reste à 419 fichiers et 38 549 octets.

## État opérationnel et limites

- D3, manifeste et archive conservés ensemble ;
- copie documentaire restaurée conservée temporairement pour réinspection ;
- `rentfleet_restore_test` conserve la restauration vérifiée ;
- aucune migration ou donnée de `rentfleet` modifiée pendant cette reprise ;
- aucun accès à `rentfleet_test` ;
- aucune dépendance modifiée ;
- aucun commit ;
- worktree G2 préservé.

Limites :

- origine exacte du batch 2 indéterminée faute d’horodatage SQL et d’historique
  de commande consultable sans risque ;
- chiffrement BitLocker du volume local non vérifiable dans ce contexte ;
- le scheduler local ne maintient pas de heartbeat permanent.

**D3 post-migration validée sur 69 migrations. G2 est déjà appliqué à
rentfleet. Aucun rollback ni nouvel migrate n’a été exécuté. L’origine du batch
2 est indéterminée selon les preuves documentées.**
