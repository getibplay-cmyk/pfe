[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$BackupDirectory,
    [string]$DatabaseName = $(if ($env:RENTFLEET_RESTORE_DATABASE) { $env:RENTFLEET_RESTORE_DATABASE } else { 'rentfleet_restore_test' }),
    [string]$PrivateDocumentsTarget = 'storage/app/private-restore-test',
    [switch]$ConfirmRestore,
    [switch]$ReplacePrivateRestoreTarget
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($DatabaseName -ne 'rentfleet_restore_test') {
    throw "Restauration refusée : la seule cible autorisée est 'rentfleet_restore_test'."
}

if ($DatabaseName -match '^(rentfleet|postgres|template0|template1)$' -or $DatabaseName -match '(?i)prod(uction)?') {
    throw "Restauration refusée vers la base protégée '$DatabaseName'."
}

$root = Split-Path -Parent $PSScriptRoot
$backupRoot = [System.IO.Path]::GetFullPath((Join-Path $root $BackupDirectory))
$allowedPrivateTarget = [System.IO.Path]::GetFullPath((Join-Path $root 'storage/app/private-restore-test'))
$privateTarget = [System.IO.Path]::GetFullPath((Join-Path $root $PrivateDocumentsTarget))

if ($privateTarget -ne $allowedPrivateTarget) {
    throw "Restauration documentaire refusée : la cible doit être '$allowedPrivateTarget'."
}

if (-not (Test-Path -LiteralPath $backupRoot -PathType Container)) {
    throw "Dossier de sauvegarde introuvable : $backupRoot"
}

$dumpPath = Join-Path $backupRoot 'database.dump'
$archivePath = Join-Path $backupRoot 'private-documents.zip'
$manifestPath = Join-Path $backupRoot 'private-documents-manifest.json'

foreach ($required in @($dumpPath, "$dumpPath.sha256", $archivePath, "$archivePath.sha256", $manifestPath)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "Fichier de sauvegarde requis absent : $required"
    }
}

function Assert-Hash {
    param([Parameter(Mandatory)][string]$File, [Parameter(Mandatory)][string]$HashFile)

    $expected = ((Get-Content -LiteralPath $HashFile -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $File).Hash.ToLowerInvariant()
    if ($actual -ne $expected) {
        throw "Empreinte SHA-256 invalide pour $(Split-Path -Leaf $File)."
    }
}

Assert-Hash $dumpPath "$dumpPath.sha256"
Assert-Hash $archivePath "$archivePath.sha256"

if (-not $ConfirmRestore) {
    $answer = Read-Host "Confirmer la restauration destructive dans '$DatabaseName' uniquement (taper RESTAURER)"
    if ($answer -ne 'RESTAURER') {
        throw 'Restauration annulée.'
    }
}

$hostName = if ($env:PGHOST) { $env:PGHOST } else { '127.0.0.1' }
$port = if ($env:PGPORT) { $env:PGPORT } else { '5432' }
$user = if ($env:PGUSER) { $env:PGUSER } else { $null }
if (-not $user) {
    throw 'Définissez PGUSER et utilisez pgpass/PGPASSFILE pour le mot de passe.'
}

Write-Host "Restauration PostgreSQL vers la base dédiée '$DatabaseName'."
& pg_restore '--exit-on-error' '--clean' '--if-exists' '--no-owner' '--no-privileges' "--host=$hostName" "--port=$port" "--username=$user" "--dbname=$DatabaseName" $dumpPath
if ($LASTEXITCODE -ne 0) {
    throw "pg_restore a échoué avec le code $LASTEXITCODE."
}

$stage = [System.IO.Path]::GetFullPath((Join-Path $root ('storage/app/private-restore-stage-'+[Guid]::NewGuid().ToString('N'))))
try {
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    Expand-Archive -LiteralPath $archivePath -DestinationPath $stage -Force
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json

    foreach ($entry in $manifest.files) {
        $candidate = [System.IO.Path]::GetFullPath((Join-Path $stage ([string] $entry.path)))
        if (-not $candidate.StartsWith($stage, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Chemin documentaire invalide dans le manifeste : $($entry.path)"
        }
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) {
            throw "Document restauré absent : $($entry.path)"
        }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $candidate).Hash.ToLowerInvariant()
        if ($actual -ne ([string] $entry.sha256).ToLowerInvariant() -or (Get-Item -LiteralPath $candidate).Length -ne [long] $entry.size) {
            throw "Document restauré invalide : $($entry.path)"
        }
    }

    if (Test-Path -LiteralPath $privateTarget) {
        if (-not $ReplacePrivateRestoreTarget) {
            throw "La cible documentaire existe déjà. Utilisez -ReplacePrivateRestoreTarget uniquement pour la cible dédiée."
        }
        if (-not $privateTarget.EndsWith('private-restore-test', [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Garde de suppression documentaire déclenchée.'
        }
        Remove-Item -LiteralPath $privateTarget -Recurse -Force
    }

    Move-Item -LiteralPath $stage -Destination $privateTarget
    $stage = $null
    Write-Host "Restauration terminée dans '$DatabaseName' et '$privateTarget'." -ForegroundColor Green
} finally {
    if ($stage -and (Test-Path -LiteralPath $stage) -and $stage.Contains('private-restore-stage-')) {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
}
