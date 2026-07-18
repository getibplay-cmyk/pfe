[CmdletBinding()]
param(
    [Parameter(Mandatory)][AllowEmptyString()][string]$DatabaseName,
    [Parameter(Mandatory)][AllowEmptyString()][string]$OutputDirectory,
    [string]$PrivateDocumentsPath = 'storage/app/private',
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

function Invoke-PsqlScalar {
    param([Parameter(Mandatory)][string]$Sql)

    $arguments = @(
        '--no-password', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1',
        "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
        "--dbname=$DatabaseName", "--command=$Sql"
    )
    $output = & psql @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "psql a échoué avec le code $LASTEXITCODE. Vérifiez pgpass et les paramètres non sensibles."
    }

    return (($output | Select-Object -Last 1) -as [string]).Trim()
}

if ($DatabaseName -notin @('rentfleet', 'rentfleet_test')) {
    throw "Sauvegarde refusée : la source doit être exactement 'rentfleet' ou 'rentfleet_test'."
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    throw 'Un répertoire de sortie explicite est obligatoire.'
}
if ($PostgresUser -ne 'rentfleet_app') {
    throw "Sauvegarde refusée : l'utilisateur PostgreSQL attendu est rentfleet_app."
}

foreach ($tool in @('psql', 'pg_dump', 'git')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "Outil requis introuvable : $tool"
    }
}

$outputRoot = Get-AbsolutePath -Path $OutputDirectory -BasePath $root
$privateRoot = Get-AbsolutePath -Path $PrivateDocumentsPath -BasePath $root
$expectedPrivateRoot = [System.IO.Path]::GetFullPath((Join-Path $root 'storage/app/private'))
$repoBackups = [System.IO.Path]::GetFullPath((Join-Path $root 'backups'))

if ($privateRoot -ne $expectedPrivateRoot) {
    throw "Sauvegarde refusée : la racine documentaire doit être '$expectedPrivateRoot'."
}
if ($outputRoot -eq $root -or $outputRoot -eq $privateRoot -or (Test-IsWithin -Candidate $outputRoot -Parent (Join-Path $root 'public'))) {
    throw 'Répertoire de sauvegarde dangereux ou public.'
}
if ((Test-IsWithin -Candidate $outputRoot -Parent $root) -and $outputRoot -ne $repoBackups -and -not (Test-IsWithin -Candidate $outputRoot -Parent $repoBackups)) {
    throw "Dans le dépôt, les sauvegardes sont autorisées uniquement sous le chemin ignoré '$repoBackups'. Préférez un volume externe chiffré."
}
if (-not (Test-Path -LiteralPath $privateRoot -PathType Container)) {
    throw "Stockage privé introuvable : $privateRoot"
}

Assert-PgPassAvailable

$identity = Invoke-PsqlScalar "select current_database() || '|' || current_user"
if ($identity -ne "$DatabaseName|$PostgresUser") {
    throw 'La connexion PostgreSQL ne correspond pas à la base source et à l’utilisateur attendus.'
}

$timestamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmssfffZ')
$backupDirectory = Join-Path $outputRoot ("rentfleet-{0}-{1}" -f $DatabaseName, $timestamp)
$staging = Join-Path $backupDirectory ('.private-stage-' + [Guid]::NewGuid().ToString('N'))
$backupCreated = $false
$startedAt = [DateTime]::UtcNow

try {
    New-Item -ItemType Directory -Path $staging -Force | Out-Null
    $backupCreated = $true

    $dumpName = "rentfleet-$DatabaseName-$timestamp.dump"
    $archiveName = "rentfleet-private-$timestamp.zip"
    $dumpPath = Join-Path $backupDirectory $dumpName
    $archivePath = Join-Path $backupDirectory $archiveName
    $manifestPath = Join-Path $backupDirectory 'backup-manifest.json'

    $dumpArguments = @(
        '--format=custom', '--no-owner', '--no-privileges', '--no-password',
        "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
        "--file=$dumpPath", $DatabaseName
    )
    Write-Host "Création du dump PostgreSQL custom pour '$DatabaseName'."
    & pg_dump @dumpArguments
    if ($LASTEXITCODE -ne 0) {
        throw "pg_dump a échoué avec le code $LASTEXITCODE."
    }

    $reparsePoints = @(Get-ChildItem -LiteralPath $privateRoot -Recurse -Force | Where-Object { ($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0 })
    if ($reparsePoints.Count -gt 0) {
        throw 'Le stockage privé contient au moins un lien symbolique ou point de réanalyse ; sauvegarde refusée.'
    }

    $privateFiles = @()
    $excluded = '(^|[\\/])(logs?|cache|sessions?|build|temp|tmp)([\\/]|$)|(^|[\\/])\.env(?:\.|$)|\.key$'
    $files = @(Get-ChildItem -LiteralPath $privateRoot -File -Recurse -Force | Where-Object {
        $relativeCandidate = $_.FullName.Substring($privateRoot.Length).TrimStart('\', '/')
        $relativeCandidate -notmatch $excluded
    })

    foreach ($file in $files) {
        $source = [System.IO.Path]::GetFullPath($file.FullName)
        if (-not (Test-IsWithin -Candidate $source -Parent $privateRoot)) {
            throw 'Un document résolu sort de la racine privée.'
        }

        $relative = $source.Substring($privateRoot.Length).TrimStart('\', '/').Replace('\', '/')
        $destination = [System.IO.Path]::GetFullPath((Join-Path $staging $relative))
        if (-not (Test-IsWithin -Candidate $destination -Parent $staging)) {
            throw 'Un chemin documentaire de destination sort de la zone temporaire.'
        }

        New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination
        $privateFiles += [ordered]@{
            path = $relative
            size_bytes = [long] $file.Length
            sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $source).Hash.ToLowerInvariant()
        }
    }

    ConvertTo-Json -InputObject @($privateFiles) -Depth 5 | Set-Content -LiteralPath (Join-Path $staging 'private-files-manifest.json') -Encoding UTF8
    Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archivePath -CompressionLevel Optimal -Force

    $tableCounts = [ordered]@{}
    foreach ($table in @(
        'migrations', 'tenants', 'agencies', 'roles', 'users', 'vehicle_categories', 'vehicles',
        'customers', 'drivers', 'reservations', 'vehicle_blocks', 'rental_contracts', 'contract_versions',
        'invoices', 'payments', 'deposit_transactions', 'maintenance_orders', 'insurance_policies',
        'insurance_claims', 'documents', 'document_versions', 'audit_logs'
    )) {
        $tableCounts[$table] = [long] (Invoke-PsqlScalar "select count(*) from $table")
    }

    $constraintNames = (Invoke-PsqlScalar "select coalesce(string_agg(conname, ',' order by conname), '') from pg_constraint where conname in ('vehicle_blocks_no_active_overlap_excl','insurance_policies_no_active_overlap_excl','users_tenant_agency_scope_fk','customers_tenant_agency_scope_fk','insurance_claims_contract_agency_fk','insurance_claims_damage_scope_fk','payment_allocations_payment_scope_fk','payment_allocations_invoice_scope_fk')")
    $triggerNames = (Invoke-PsqlScalar "select coalesce(string_agg(tgname, ',' order by tgname), '') from pg_trigger where not tgisinternal")
    $indexNames = (Invoke-PsqlScalar "select coalesce(string_agg(indexname, ',' order by indexname), '') from pg_indexes where schemaname = 'public' and indexname in ('reservations_reporting_created_idx','reservation_status_histories_reporting_events_idx','rental_contracts_reporting_returns_idx','vehicle_blocks_reporting_period_idx','invoices_reporting_issued_idx','payments_reporting_posted_idx','deposit_transactions_reporting_occurred_idx','expenses_reporting_date_idx','maintenance_orders_reporting_schedule_idx','insurance_claims_reporting_open_idx','documents_reporting_expiry_idx','drivers_reporting_licence_expiry_idx','vehicle_blocks_one_per_maintenance_unique','expenses_one_per_maintenance_unique')")

    $commit = (& git -c "safe.directory=$($root.Replace('\', '/'))" rev-parse HEAD | Select-Object -Last 1)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($commit)) {
        $commit = 'unknown'
    }

    $dumpItem = Get-Item -LiteralPath $dumpPath
    $archiveItem = Get-Item -LiteralPath $archivePath
    $manifest = [ordered]@{
        schema_version = 1
        status = 'completed'
        created_at_utc = [DateTime]::UtcNow.ToString('o')
        source_database = $DatabaseName
        source_user = $PostgresUser
        postgres_version = Invoke-PsqlScalar "select current_setting('server_version')"
        application_commit = ([string] $commit).Trim()
        duration_seconds = [math]::Round(([DateTime]::UtcNow - $startedAt).TotalSeconds, 3)
        artifacts = [ordered]@{
            database = [ordered]@{
                name = $dumpName
                size_bytes = [long] $dumpItem.Length
                sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash.ToLowerInvariant()
            }
            private_documents = [ordered]@{
                name = $archiveName
                size_bytes = [long] $archiveItem.Length
                sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $archivePath).Hash.ToLowerInvariant()
                file_count = $privateFiles.Count
            }
        }
        steps = [ordered]@{
            database_dump = 'completed'
            private_documents_archive = 'completed'
            integrity_manifest = 'completed'
        }
        private_files = $privateFiles
        source_snapshot = [ordered]@{
            table_counts = $tableCounts
            critical_constraints = @($constraintNames -split ',' | Where-Object { $_ })
            critical_triggers = @($triggerNames -split ',' | Where-Object { $_ })
            critical_indexes = @($indexNames -split ',' | Where-Object { $_ })
        }
    }
    $manifest | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $manifestPath -Encoding UTF8

    Write-Host "Sauvegarde complète créée hors espace public : $backupDirectory" -ForegroundColor Green
} catch {
    if ($backupCreated -and (Test-Path -LiteralPath $backupDirectory) -and (Test-IsWithin -Candidate $backupDirectory -Parent $outputRoot)) {
        Write-Warning "Suppression de l'artefact partiel exact : $backupDirectory"
        Remove-Item -LiteralPath $backupDirectory -Recurse -Force
    }
    throw
} finally {
    if (Test-Path -LiteralPath $staging) {
        $resolvedStaging = [System.IO.Path]::GetFullPath($staging)
        if (Test-IsWithin -Candidate $resolvedStaging -Parent $backupDirectory) {
            Write-Host "Suppression de la zone temporaire exacte : $resolvedStaging"
            Remove-Item -LiteralPath $resolvedStaging -Recurse -Force
        }
    }
}
