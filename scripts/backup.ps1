[CmdletBinding()]
param(
    [string]$OutputDirectory = 'backups',
    [string]$PrivateDocumentsPath = 'storage/app/private'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent $PSScriptRoot
$outputRoot = [System.IO.Path]::GetFullPath((Join-Path $root $OutputDirectory))
$privateRoot = [System.IO.Path]::GetFullPath((Join-Path $root $PrivateDocumentsPath))
$database = if ($env:RENTFLEET_BACKUP_DATABASE) { $env:RENTFLEET_BACKUP_DATABASE } elseif ($env:PGDATABASE) { $env:PGDATABASE } else { $null }
$hostName = if ($env:PGHOST) { $env:PGHOST } else { '127.0.0.1' }
$port = if ($env:PGPORT) { $env:PGPORT } else { '5432' }
$user = if ($env:PGUSER) { $env:PGUSER } else { $null }

if (-not $database -or -not $user) {
    throw 'Définissez RENTFLEET_BACKUP_DATABASE (ou PGDATABASE) et PGUSER. Utilisez pgpass/PGPASSFILE pour le mot de passe.'
}

$timestamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmssZ')
$backupDirectory = Join-Path $outputRoot $timestamp
$staging = Join-Path $backupDirectory '_private-staging'
New-Item -ItemType Directory -Path $staging -Force | Out-Null

try {
    $dumpPath = Join-Path $backupDirectory 'database.dump'
    $dumpArguments = @(
        '--format=custom', '--no-owner', '--no-privileges', '--verbose',
        "--host=$hostName", "--port=$port", "--username=$user", "--file=$dumpPath", $database
    )
    Write-Host "Création de la sauvegarde PostgreSQL de '$database' (format custom)."
    & pg_dump @dumpArguments
    if ($LASTEXITCODE -ne 0) {
        throw "pg_dump a échoué avec le code $LASTEXITCODE."
    }

    $dumpHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash.ToLowerInvariant()
    Set-Content -LiteralPath "$dumpPath.sha256" -Value "$dumpHash  database.dump" -Encoding Ascii

    $manifestEntries = @()
    if (Test-Path -LiteralPath $privateRoot) {
        $files = Get-ChildItem -LiteralPath $privateRoot -File -Recurse | Where-Object {
            $_.FullName.Substring($privateRoot.Length).TrimStart('\', '/') -notmatch '(^|[\\/])(logs?|cache|sessions?|temp|tmp)([\\/]|$)'
        }

        foreach ($file in $files) {
            $relative = $file.FullName.Substring($privateRoot.Length).TrimStart('\', '/').Replace('\', '/')
            $destination = Join-Path $staging $relative
            New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
            Copy-Item -LiteralPath $file.FullName -Destination $destination
            $manifestEntries += [ordered]@{
                path = $relative
                size = $file.Length
                sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
            }
        }
    }

    $manifest = [ordered]@{
        created_at_utc = [DateTime]::UtcNow.ToString('o')
        source = 'storage/app/private'
        file_count = $manifestEntries.Count
        files = $manifestEntries
    }
    $manifestPath = Join-Path $backupDirectory 'private-documents-manifest.json'
    $manifest | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $staging 'private-documents-manifest.json')

    $archivePath = Join-Path $backupDirectory 'private-documents.zip'
    Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archivePath -CompressionLevel Optimal -Force
    $archiveHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $archivePath).Hash.ToLowerInvariant()
    Set-Content -LiteralPath "$archivePath.sha256" -Value "$archiveHash  private-documents.zip" -Encoding Ascii

    [ordered]@{
        created_at_utc = [DateTime]::UtcNow.ToString('o')
        database_file = 'database.dump'
        database_sha256 = $dumpHash
        private_archive = 'private-documents.zip'
        private_archive_sha256 = $archiveHash
        private_file_count = $manifestEntries.Count
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $backupDirectory 'backup-summary.json') -Encoding UTF8

    Write-Host "Sauvegarde créée : $backupDirectory" -ForegroundColor Green
} finally {
    if (Test-Path -LiteralPath $staging) {
        $resolvedStaging = [System.IO.Path]::GetFullPath($staging)
        if ($resolvedStaging.StartsWith($backupDirectory, [StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedStaging -Recurse -Force
        }
    }
}
