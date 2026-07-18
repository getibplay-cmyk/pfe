[CmdletBinding()]
param(
    [Parameter(Mandatory)][AllowEmptyString()][string]$BackupDirectory,
    [Parameter(Mandatory)][AllowEmptyString()][string]$DatabaseName,
    [Parameter(Mandatory)][AllowEmptyString()][string]$PrivateDocumentsPath,
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

function Invoke-PsqlScalar {
    param([Parameter(Mandatory)][string]$Sql)

    $arguments = @(
        '--no-password', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1',
        "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
        "--dbname=$DatabaseName", "--command=$Sql"
    )
    $output = & psql @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Une requête de vérification PostgreSQL a échoué.'
    }

    return (($output | Select-Object -Last 1) -as [string]).Trim()
}

function Set-ProcessEnvironment {
    param([Parameter(Mandatory)][string]$Name, [Parameter(Mandatory)][string]$Value)

    [System.Environment]::SetEnvironmentVariable($Name, $Value, [System.EnvironmentVariableTarget]::Process)
}

if ([string]::IsNullOrWhiteSpace($DatabaseName) -or $DatabaseName -ne 'rentfleet_restore_test') {
    throw "Vérification refusée : la cible doit être exactement 'rentfleet_restore_test'."
}
if ($PostgresUser -ne 'rentfleet_app') {
    throw "Vérification refusée : l'utilisateur PostgreSQL attendu est rentfleet_app."
}
if ([string]::IsNullOrWhiteSpace($env:PHP_BINARY)) {
    throw 'PHP_BINARY doit désigner explicitement PHP Herd 8.5.8 ou le binaire PHP 8.5 de production.'
}
$php = $env:PHP_BINARY
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw 'PHP_BINARY ne désigne pas un fichier exécutable existant.'
}
if ([string]::IsNullOrWhiteSpace($BackupDirectory) -or [string]::IsNullOrWhiteSpace($PrivateDocumentsPath)) {
    throw 'Le dossier de sauvegarde et la racine documentaire restaurée sont obligatoires.'
}

$backupRoot = Get-AbsolutePath -Path $BackupDirectory -BasePath $root
$privateRoot = Get-AbsolutePath -Path $PrivateDocumentsPath -BasePath $root
$livePrivateRoot = [System.IO.Path]::GetFullPath((Join-Path $root 'storage/app/private'))
if ($privateRoot -eq $livePrivateRoot -or (Test-IsWithin -Candidate $privateRoot -Parent $root)) {
    throw 'La vérification exige une racine documentaire restaurée hors du dépôt et distincte du stockage vivant.'
}

$manifestPath = Join-Path $backupRoot 'backup-manifest.json'
$restoredManifestPath = Join-Path $privateRoot 'backup-manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf) -or -not (Test-Path -LiteralPath $restoredManifestPath -PathType Leaf)) {
    throw 'Le manifeste de sauvegarde ou sa copie restaurée est absent.'
}
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $manifestPath).Hash -ne (Get-FileHash -Algorithm SHA256 -LiteralPath $restoredManifestPath).Hash) {
    throw 'Le manifeste restauré diffère du manifeste source.'
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.schema_version -ne 1 -or $manifest.status -ne 'completed') {
    throw 'Le manifeste ne représente pas une sauvegarde complète.'
}
$dumpPath = [System.IO.Path]::GetFullPath((Join-Path $backupRoot ([string] $manifest.artifacts.database.name)))
$archivePath = [System.IO.Path]::GetFullPath((Join-Path $backupRoot ([string] $manifest.artifacts.private_documents.name)))
if (-not (Test-IsWithin -Candidate $dumpPath -Parent $backupRoot) -or -not (Test-IsWithin -Candidate $archivePath -Parent $backupRoot)) {
    throw 'Le manifeste référence un artefact hors du dossier de sauvegarde.'
}
Assert-Artifact -Path $dumpPath -ExpectedSize ([long] $manifest.artifacts.database.size_bytes) -ExpectedHash ([string] $manifest.artifacts.database.sha256)
Assert-Artifact -Path $archivePath -ExpectedSize ([long] $manifest.artifacts.private_documents.size_bytes) -ExpectedHash ([string] $manifest.artifacts.private_documents.sha256)

foreach ($entry in $manifest.private_files) {
    $candidate = [System.IO.Path]::GetFullPath((Join-Path $privateRoot ([string] $entry.path)))
    if (-not (Test-IsWithin -Candidate $candidate -Parent $privateRoot)) {
        throw 'Un document restauré sort de la racine isolée.'
    }
    Assert-Artifact -Path $candidate -ExpectedSize ([long] $entry.size_bytes) -ExpectedHash ([string] $entry.sha256)
}
$restoredFiles = @(Get-ChildItem -LiteralPath $privateRoot -File -Recurse -Force | Where-Object { $_.Name -notin @('private-files-manifest.json', 'backup-manifest.json') })
if ($restoredFiles.Count -ne [int] $manifest.artifacts.private_documents.file_count) {
    throw 'Le nombre de documents privés restaurés diffère du manifeste.'
}

foreach ($tool in @('psql')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "Outil requis introuvable : $tool"
    }
}
Assert-PgPassAvailable

$identity = Invoke-PsqlScalar "select current_database() || '|' || current_user"
if ($identity -ne "$DatabaseName|$PostgresUser") {
    throw 'La connexion ne cible pas la base de restauration et l’utilisateur attendus.'
}

$expectedTables = @(
    'migrations', 'tenants', 'agencies', 'roles', 'users', 'vehicle_categories', 'vehicles',
    'customers', 'drivers', 'reservations', 'vehicle_blocks', 'rental_contracts', 'contract_versions',
    'invoices', 'payments', 'deposit_transactions', 'maintenance_orders', 'insurance_policies',
    'insurance_claims', 'documents', 'document_versions', 'audit_logs'
)
$manifestTables = @($manifest.source_snapshot.table_counts.PSObject.Properties.Name | Sort-Object)
if (($manifestTables -join '|') -ne (@($expectedTables | Sort-Object) -join '|')) {
    throw 'Le manifeste ne contient pas exactement la liste attendue des comptages agrégés.'
}
foreach ($table in $expectedTables) {
    $expected = [long] $manifest.source_snapshot.table_counts.$table
    $actual = [long] (Invoke-PsqlScalar "select count(*) from $table")
    if ($actual -ne $expected) {
        throw "Comptage agrégé différent pour la table $table."
    }
}

$constraintNamesRaw = Invoke-PsqlScalar "select coalesce(string_agg(conname, ',' order by conname), '') from pg_constraint where conname in ('vehicle_blocks_no_active_overlap_excl','insurance_policies_no_active_overlap_excl','users_tenant_agency_scope_fk','customers_tenant_agency_scope_fk','insurance_claims_contract_agency_fk','insurance_claims_damage_scope_fk','payment_allocations_payment_scope_fk','payment_allocations_invoice_scope_fk')"
$triggerNamesRaw = Invoke-PsqlScalar "select coalesce(string_agg(tgname, ',' order by tgname), '') from pg_trigger where not tgisinternal"
$indexNamesRaw = Invoke-PsqlScalar "select coalesce(string_agg(indexname, ',' order by indexname), '') from pg_indexes where schemaname = 'public' and indexname in ('reservations_reporting_created_idx','reservation_status_histories_reporting_events_idx','rental_contracts_reporting_returns_idx','vehicle_blocks_reporting_period_idx','invoices_reporting_issued_idx','payments_reporting_posted_idx','deposit_transactions_reporting_occurred_idx','expenses_reporting_date_idx','maintenance_orders_reporting_schedule_idx','insurance_claims_reporting_open_idx','documents_reporting_expiry_idx','drivers_reporting_licence_expiry_idx','vehicle_blocks_one_per_maintenance_unique','expenses_one_per_maintenance_unique')"
$constraintNames = @($constraintNamesRaw -split ',' | Where-Object { $_ })
$triggerNames = @($triggerNamesRaw -split ',' | Where-Object { $_ })
$indexNames = @($indexNamesRaw -split ',' | Where-Object { $_ })

foreach ($comparison in @(
    @{ Name = 'contraintes critiques'; Expected = @($manifest.source_snapshot.critical_constraints); Actual = $constraintNames },
    @{ Name = 'triggers critiques'; Expected = @($manifest.source_snapshot.critical_triggers); Actual = $triggerNames },
    @{ Name = 'index critiques'; Expected = @($manifest.source_snapshot.critical_indexes); Actual = $indexNames }
)) {
    $expected = @($comparison.Expected | Sort-Object)
    $actual = @($comparison.Actual | Sort-Object)
    if (($expected -join '|') -ne ($actual -join '|')) {
        throw "La liste des $($comparison.Name) diffère de la source."
    }
}

$previousEnvironment = @{
    DB_DATABASE = $env:DB_DATABASE
    APP_ENV = $env:APP_ENV
    PRIVATE_DOCUMENT_ROOT = $env:PRIVATE_DOCUMENT_ROOT
}

try {
    Set-ProcessEnvironment -Name 'DB_DATABASE' -Value $DatabaseName
    Set-ProcessEnvironment -Name 'APP_ENV' -Value 'restore-verification'
    Set-ProcessEnvironment -Name 'PRIVATE_DOCUMENT_ROOT' -Value $privateRoot
    Set-Location -LiteralPath $root

    & $php -v
    if ($LASTEXITCODE -ne 0) {
        throw 'PHP_BINARY n’est pas exécutable.'
    }
    & $php artisan migrate:status --no-ansi
    if ($LASTEXITCODE -ne 0) {
        throw 'Le statut des migrations restaurées est invalide.'
    }
    & $php artisan rentfleet:verify-restored-data --no-ansi
    if ($LASTEXITCODE -ne 0) {
        throw 'Le déchiffrement contrôlé des données restaurées a échoué.'
    }
    & $php artisan rentfleet:doctor --no-ansi
    if ($LASTEXITCODE -ne 0) {
        throw 'rentfleet:doctor a échoué sur la restauration.'
    }

    $routes = & $php artisan route:list --json
    if ($LASTEXITCODE -ne 0 -or (($routes -join "`n") -match 'storage/|register|signup')) {
        throw 'Une route publique interdite est présente sur la restauration.'
    }
} finally {
    foreach ($name in $previousEnvironment.Keys) {
        $value = $previousEnvironment[$name]
        if ($null -eq $value) {
            [System.Environment]::SetEnvironmentVariable($name, $null, [System.EnvironmentVariableTarget]::Process)
        } else {
            Set-ProcessEnvironment -Name $name -Value ([string] $value)
        }
    }
}

Write-Host 'Sauvegarde restaurée et vérifiée avec succès, sans divulgation de données.' -ForegroundColor Green
