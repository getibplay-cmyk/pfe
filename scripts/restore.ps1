[CmdletBinding()]
param(
    [Parameter(Mandatory)][AllowEmptyString()][string]$BackupDirectory,
    [Parameter(Mandatory)][AllowEmptyString()][string]$DatabaseName,
    [AllowEmptyString()][string]$PrivateDocumentsTarget = '',
    [switch]$ConfirmRestore,
    [switch]$ReplacePrivateRestoreTarget,
    [string]$PostgresHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$PostgresPort = 5432,
    [string]$PostgresUser = 'rentfleet_app'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$directorySeparator = [System.IO.Path]::DirectorySeparatorChar

function Get-AbsolutePath {
    param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][string]$BasePath)

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return [System.IO.Path]::GetFullPath($Path)
    }

    return [System.IO.Path]::GetFullPath((Join-Path $BasePath $Path))
}

function Test-IsWithin {
    param([Parameter(Mandatory)][string]$Candidate, [Parameter(Mandatory)][string]$Parent)

    $parentPrefix = $Parent.TrimEnd('\', '/') + $directorySeparator

    return $Candidate.StartsWith($parentPrefix, [StringComparison]::OrdinalIgnoreCase)
}

function Assert-PgPassAvailable {
    $pgpass = $env:PGPASSFILE
    if ([string]::IsNullOrWhiteSpace($pgpass)) {
        if ($env:APPDATA) {
            $pgpass = Join-Path $env:APPDATA 'postgresql\pgpass.conf'
        } elseif ($env:HOME) {
            $pgpass = Join-Path $env:HOME '.pgpass'
        }
    }

    if ([string]::IsNullOrWhiteSpace($pgpass) -or -not (Test-Path -LiteralPath $pgpass -PathType Leaf)) {
        throw 'pgpass est absent. Créez le fichier protégé pgpass.conf (Windows) ou .pgpass (Linux), puis relancez sans fournir de mot de passe à la commande.'
    }
}

function Assert-Artifact {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][long]$ExpectedSize,
        [Parameter(Mandatory)][string]$ExpectedHash
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Artefact requis absent : $Path"
    }
    $item = Get-Item -LiteralPath $Path
    $actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
    if ($item.Length -ne $ExpectedSize -or $actualHash -ne $ExpectedHash.ToLowerInvariant()) {
        throw "Taille ou empreinte SHA-256 invalide pour $(Split-Path -Leaf $Path)."
    }
}

if ([string]::IsNullOrWhiteSpace($DatabaseName) -or $DatabaseName -ne 'rentfleet_restore_test') {
    throw "Restauration refusée : la seule cible autorisée est exactement 'rentfleet_restore_test'."
}
if ($DatabaseName -in @('rentfleet', 'rentfleet_test', 'postgres', 'template0', 'template1')) {
    throw "Restauration refusée vers la base protégée '$DatabaseName'."
}
if (-not $ConfirmRestore) {
    throw 'Restauration refusée : le paramètre explicite -ConfirmRestore est obligatoire.'
}
if ($PostgresUser -ne 'rentfleet_app') {
    throw "Restauration refusée : l'utilisateur PostgreSQL attendu est rentfleet_app."
}
if ([string]::IsNullOrWhiteSpace($BackupDirectory) -or [string]::IsNullOrWhiteSpace($PrivateDocumentsTarget)) {
    throw 'Le dossier de sauvegarde et la cible documentaire isolée sont obligatoires.'
}

$backupRoot = Get-AbsolutePath -Path $BackupDirectory -BasePath $root
$privateTarget = Get-AbsolutePath -Path $PrivateDocumentsTarget -BasePath $root
$livePrivateRoot = [System.IO.Path]::GetFullPath((Join-Path $root 'storage/app/private'))
$homePath = if ($env:USERPROFILE) { [System.IO.Path]::GetFullPath($env:USERPROFILE) } elseif ($env:HOME) { [System.IO.Path]::GetFullPath($env:HOME) } else { $null }
$targetRoot = [System.IO.Path]::GetPathRoot($privateTarget)

if ($privateTarget -eq $root -or $privateTarget -eq $livePrivateRoot -or $privateTarget -eq $targetRoot -or ($homePath -and $privateTarget -eq $homePath) -or (Test-IsWithin -Candidate $privateTarget -Parent $root)) {
    throw 'Cible documentaire refusée : utilisez un répertoire temporaire explicite hors du dépôt, distinct du stockage vivant.'
}
if (-not (Test-Path -LiteralPath $backupRoot -PathType Container)) {
    throw "Dossier de sauvegarde introuvable : $backupRoot"
}
if ($privateTarget -eq $backupRoot -or (Test-IsWithin -Candidate $privateTarget -Parent $backupRoot) -or (Test-IsWithin -Candidate $backupRoot -Parent $privateTarget)) {
    throw 'La cible documentaire isolée et le dossier de sauvegarde doivent être strictement séparés.'
}
if ((Test-Path -LiteralPath $privateTarget) -and -not $ReplacePrivateRestoreTarget) {
    throw 'La cible documentaire isolée existe déjà. Utilisez -ReplacePrivateRestoreTarget pour cette cible exacte.'
}

$manifestPath = Join-Path $backupRoot 'backup-manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw "Manifeste de sauvegarde absent : $manifestPath"
}
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.schema_version -ne 1 -or $manifest.status -ne 'completed' -or $manifest.source_database -notin @('rentfleet', 'rentfleet_test')) {
    throw 'Manifeste incomplet ou source non autorisée.'
}

$dumpPath = [System.IO.Path]::GetFullPath((Join-Path $backupRoot ([string] $manifest.artifacts.database.name)))
$archivePath = [System.IO.Path]::GetFullPath((Join-Path $backupRoot ([string] $manifest.artifacts.private_documents.name)))
if (-not (Test-IsWithin -Candidate $dumpPath -Parent $backupRoot) -or -not (Test-IsWithin -Candidate $archivePath -Parent $backupRoot)) {
    throw 'Le manifeste référence un artefact hors du dossier de sauvegarde.'
}
Assert-Artifact -Path $dumpPath -ExpectedSize ([long] $manifest.artifacts.database.size_bytes) -ExpectedHash ([string] $manifest.artifacts.database.sha256)
Assert-Artifact -Path $archivePath -ExpectedSize ([long] $manifest.artifacts.private_documents.size_bytes) -ExpectedHash ([string] $manifest.artifacts.private_documents.sha256)

foreach ($tool in @('psql', 'pg_restore')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "Outil requis introuvable : $tool"
    }
}
Assert-PgPassAvailable

$identityArguments = @(
    '--no-password', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1',
    "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
    "--dbname=$DatabaseName", "--command=select current_database() || '|' || current_user"
)
$identity = & psql @identityArguments
if ($LASTEXITCODE -ne 0 -or (($identity | Select-Object -Last 1) -as [string]).Trim() -ne "$DatabaseName|$PostgresUser") {
    throw 'La base rentfleet_restore_test doit exister préalablement et appartenir à rentfleet_app.'
}

$targetParent = Split-Path -Parent $privateTarget
if ([string]::IsNullOrWhiteSpace($targetParent)) {
    throw 'La cible documentaire ne possède pas de répertoire parent sûr.'
}
New-Item -ItemType Directory -Path $targetParent -Force | Out-Null
$stage = Join-Path $targetParent ('.rentfleet-restore-stage-' + [Guid]::NewGuid().ToString('N'))

try {
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    Expand-Archive -LiteralPath $archivePath -DestinationPath $stage -Force

    $reparsePoints = @(Get-ChildItem -LiteralPath $stage -Recurse -Force | Where-Object { ($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0 })
    if ($reparsePoints.Count -gt 0) {
        throw 'L’archive restaurée contient un lien symbolique ou point de réanalyse.'
    }

    $expectedPaths = @{}
    foreach ($entry in $manifest.private_files) {
        $relative = [string] $entry.path
        $candidate = [System.IO.Path]::GetFullPath((Join-Path $stage $relative))
        if (-not (Test-IsWithin -Candidate $candidate -Parent $stage)) {
            throw 'Le manifeste documentaire contient un chemin sortant de la racine isolée.'
        }
        Assert-Artifact -Path $candidate -ExpectedSize ([long] $entry.size_bytes) -ExpectedHash ([string] $entry.sha256)
        $expectedPaths[$relative.Replace('\', '/')] = $true
    }

    $restoredFiles = @(Get-ChildItem -LiteralPath $stage -File -Recurse -Force | Where-Object { $_.Name -ne 'private-files-manifest.json' })
    if ($restoredFiles.Count -ne [int] $manifest.artifacts.private_documents.file_count) {
        throw 'Le nombre de documents extraits diffère du manifeste.'
    }
    foreach ($file in $restoredFiles) {
        $relative = $file.FullName.Substring($stage.Length).TrimStart('\', '/').Replace('\', '/')
        if (-not $expectedPaths.ContainsKey($relative)) {
            throw 'L’archive contient un document non déclaré dans le manifeste.'
        }
    }

    Write-Host "Restauration PostgreSQL transactionnelle vers '$DatabaseName'."
    $restoreArguments = @(
        '--exit-on-error', '--single-transaction', '--clean', '--if-exists',
        '--no-owner', '--no-privileges', '--no-password',
        "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
        "--dbname=$DatabaseName", $dumpPath
    )
    & pg_restore @restoreArguments
    if ($LASTEXITCODE -ne 0) {
        throw "pg_restore a échoué avec le code $LASTEXITCODE."
    }

    Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $stage 'backup-manifest.json')
    if (Test-Path -LiteralPath $privateTarget) {
        if ($privateTarget -eq $livePrivateRoot -or $privateTarget -eq $targetRoot -or (Test-IsWithin -Candidate $privateTarget -Parent $root)) {
            throw 'Garde de suppression documentaire déclenchée.'
        }
        Write-Warning "Suppression de la cible documentaire isolée exacte : $privateTarget"
        Remove-Item -LiteralPath $privateTarget -Recurse -Force
    }

    Move-Item -LiteralPath $stage -Destination $privateTarget
    $stage = $null
    Write-Host "Restauration terminée dans '$DatabaseName' et '$privateTarget'." -ForegroundColor Green
} finally {
    if ($stage -and (Test-Path -LiteralPath $stage)) {
        $resolvedStage = [System.IO.Path]::GetFullPath($stage)
        if ($resolvedStage.StartsWith((Join-Path $targetParent '.rentfleet-restore-stage-'), [StringComparison]::OrdinalIgnoreCase)) {
            Write-Host "Suppression de la zone temporaire exacte : $resolvedStage"
            Remove-Item -LiteralPath $resolvedStage -Recurse -Force
        }
    }
}
