# Sauvegarde et restauration

## Authentification PostgreSQL

Les scripts n’utilisent pas de mot de passe en ligne de commande. Configurer
`pgpass.conf` sous Windows ou définir `PGPASSFILE` vers un fichier protégé, puis
fournir uniquement les paramètres non sensibles :

```powershell
$env:PGHOST = '127.0.0.1'
$env:PGPORT = '5432'
$env:PGUSER = 'rentfleet_app'
$env:RENTFLEET_BACKUP_DATABASE = 'rentfleet_test'
```

## Sauvegarder

```powershell
powershell -ExecutionPolicy Bypass -File scripts/backup.ps1
```

Le résultat horodaté sous `backups/` contient le dump custom, l’archive privée,
leurs empreintes et le manifeste documentaire. Copier cet ensemble vers un
support chiffré et appliquer une politique de rétention externe.

## Préparer la cible dédiée

Depuis un compte PostgreSQL administrateur, une seule fois :

```sql
CREATE DATABASE rentfleet_restore_test OWNER rentfleet_app;
```

La cible ne doit contenir aucune donnée à conserver. Le script refuse les noms
de production et toute cible autre que ce nom exact.

## Restaurer et vérifier

```powershell
$env:RENTFLEET_RESTORE_DATABASE = 'rentfleet_restore_test'
powershell -ExecutionPolicy Bypass -File scripts/restore.ps1 `
  -BackupDirectory 'backups/AAAAmmjj-HHMMSSZ' `
  -ConfirmRestore `
  -ReplacePrivateRestoreTarget

powershell -ExecutionPolicy Bypass -File scripts/verify-restore.ps1
```

Sans `-ConfirmRestore`, il faut taper exactement `RESTAURER`. La vérification
doit être verte avant de considérer la sauvegarde exploitable. Ne jamais
rediriger ces scripts vers `rentfleet`.
