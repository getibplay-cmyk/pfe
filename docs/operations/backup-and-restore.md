# Sauvegarde et restauration isolée

RentFleet sauvegarde toujours un ensemble cohérent : dump PostgreSQL custom,
archive du stockage privé, manifeste JSON, tailles et empreintes SHA-256. Une
archive non restaurée et non vérifiée n’est pas une preuve de reprise.

## Authentification PostgreSQL sans mot de passe en commande

Les scripts refusent `DB_PASSWORD`, `PGPASSWORD` et tout mot de passe en
argument. Sous Windows, créer manuellement le fichier suivant hors du dépôt :

```text
%APPDATA%\postgresql\pgpass.conf
```

Il doit contenir localement une entrée par base, avec une valeur réelle fournie
hors Git à la place du marqueur :

```text
127.0.0.1:5432:rentfleet:rentfleet_app:<REMPLACER_LOCALEMENT>
127.0.0.1:5432:rentfleet_test:rentfleet_app:<REMPLACER_LOCALEMENT>
127.0.0.1:5432:rentfleet_restore_test:rentfleet_app:<REMPLACER_LOCALEMENT>
```

Restreindre les droits au compte Windows courant sans afficher le contenu :

```powershell
icacls "$env:APPDATA\postgresql\pgpass.conf" /inheritance:r
icacls "$env:APPDATA\postgresql\pgpass.conf" /grant:r "${env:USERNAME}:(R,W)"
```

Valider uniquement par connexion :

```powershell
psql --no-password -h 127.0.0.1 -p 5432 -U rentfleet_app -d rentfleet -c "select current_database();"
psql --no-password -h 127.0.0.1 -p 5432 -U rentfleet_app -d rentfleet_test -c "select current_database();"
psql --no-password -h 127.0.0.1 -p 5432 -U rentfleet_app -d rentfleet_restore_test -c "select current_database();"
```

Sous Linux, utiliser `~/.pgpass` avec les droits `0600`, ou définir
`PGPASSFILE` vers un fichier protégé. Ne jamais imprimer ce fichier.

## Préparer la cible dédiée

La restauration automatisée accepte exclusivement la base exacte
`rentfleet_restore_test`. Si elle n’existe pas, un administrateur PostgreSQL
doit exécuter manuellement :

```sql
CREATE DATABASE rentfleet_restore_test OWNER rentfleet_app;
```

Le script ne crée, ne supprime et ne renomme jamais une base. Il refuse les
cibles vides, variantes, `rentfleet`, `rentfleet_test`, `postgres`, `template0`
et `template1`.

## Créer une sauvegarde

Choisir un répertoire explicite sur un volume chiffré et à accès restreint,
hors du dépôt et hors de `public/` :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/backup.ps1 `
  -DatabaseName rentfleet `
  -OutputDirectory 'D:\RentFleetBackups'
```

Pour une répétition sans donnée de développement, `rentfleet_test` est la seule
autre source autorisée. Le script :

- vérifie `current_database()` et `current_user` avec `psql --no-password` ;
- utilise `pg_dump --format=custom --no-password` ;
- rejette liens symboliques, points de réanalyse et chemins sortants ;
- exclut logs, caches, sessions, builds, temporaires, `.env*` et clés ;
- archive uniquement `storage/app/private` ;
- calcule les SHA-256 après fermeture des artefacts ;
- ajoute commit Git, version PostgreSQL, tailles, comptages agrégés et état des
  étapes dans `backup-manifest.json` ;
- supprime explicitement une sauvegarde partielle en cas d’échec.

`APP_KEY` n’est jamais sauvegardée. Elle doit être conservée séparément dans un
gestionnaire de secrets : la même clé est nécessaire pour déchiffrer les
identités, permis, polices et références assureur restaurés. Les scripts
n’ajoutent aucun chiffrement artisanal ; le volume de sauvegarde assure le
chiffrement au repos.

## Restaurer sans toucher au stockage vivant

Choisir une racine documentaire temporaire hors du dépôt. Elle ne doit jamais
être `storage/app/private` :

```powershell
$backup = 'D:\RentFleetBackups\rentfleet-rentfleet-AAAAmmjj-HHMMSSfffZ'
$restoredPrivate = 'C:\tmp\rentfleet-private-restore-AAAAmmjj-HHMMSS'

powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore.ps1 `
  -BackupDirectory $backup `
  -DatabaseName rentfleet_restore_test `
  -PrivateDocumentsTarget $restoredPrivate `
  -ConfirmRestore
```

Si cette cible documentaire exacte existe déjà et peut être remplacée, ajouter
`-ReplacePrivateRestoreTarget`. Le script annonce le chemin exact avant toute
suppression. Il valide le manifeste et les empreintes avant PostgreSQL, vérifie
la base et l’utilisateur, puis utilise :

```text
pg_restore --single-transaction --clean --if-exists --no-password
```

Le nettoyage porte sur les objets contenus dans `rentfleet_restore_test`,
jamais sur la base elle-même. L’archive documentaire est d’abord extraite et
validée dans une zone isolée avant d’être déplacée vers la cible explicite.

## Vérifier la restauration

La vérification exige le binaire PHP explicite et hérite de la même `APP_KEY`
locale sans l’afficher :

```powershell
$env:PHP_BINARY = 'C:\Users\pc\.config\herd\bin\php85\php.exe'

powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-restore.ps1 `
  -BackupDirectory $backup `
  -DatabaseName rentfleet_restore_test `
  -PrivateDocumentsPath $restoredPrivate
```

Le processus enfant reçoit uniquement `DB_DATABASE=rentfleet_restore_test`,
`APP_ENV=restore-verification` et la racine documentaire isolée. Les contrôles
comparent les comptages agrégés, migrations, contraintes GiST/composites,
triggers, index, documents, tailles et SHA-256. Ils exécutent ensuite le
déchiffrement contrôlé sans valeur affichée, `rentfleet:doctor` et le contrôle
des routes publiques. Toute différence critique produit un code non nul.

## Rétention et nettoyage

- conserver ensemble dump, archive et manifeste ;
- copier l’ensemble vers un volume chiffré avec contrôle d’accès et rétention ;
- tester périodiquement une restauration réelle vers la cible dédiée ;
- ne jamais conserver inutilement une copie documentaire décompressée ;
- avant nettoyage, résoudre et relire le chemin exact ; ne jamais supprimer
  récursivement une racine, le dépôt, le profil utilisateur ou une variable
  vide.
