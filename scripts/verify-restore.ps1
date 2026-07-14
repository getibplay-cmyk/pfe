[CmdletBinding()]
param(
    [string]$DatabaseName = $(if ($env:RENTFLEET_RESTORE_DATABASE) { $env:RENTFLEET_RESTORE_DATABASE } else { 'rentfleet_restore_test' }),
    [string]$PrivateDocumentsPath = 'storage/app/private-restore-test'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($DatabaseName -ne 'rentfleet_restore_test') {
    throw "Vérification refusée : la cible doit être 'rentfleet_restore_test'."
}

$root = Split-Path -Parent $PSScriptRoot
$privateRoot = [System.IO.Path]::GetFullPath((Join-Path $root $PrivateDocumentsPath))
$allowedPrivateRoot = [System.IO.Path]::GetFullPath((Join-Path $root 'storage/app/private-restore-test'))
if ($privateRoot -ne $allowedPrivateRoot) {
    throw 'La cible documentaire de vérification n’est pas dédiée à la restauration.'
}

$hostName = if ($env:PGHOST) { $env:PGHOST } else { '127.0.0.1' }
$port = if ($env:PGPORT) { $env:PGPORT } else { '5432' }
$user = if ($env:PGUSER) { $env:PGUSER } else { $null }
if (-not $user) {
    throw 'Définissez PGUSER et utilisez pgpass/PGPASSFILE pour le mot de passe.'
}

function Invoke-Scalar {
    param([Parameter(Mandatory)][string]$Sql)

    $value = & psql '--tuples-only' '--no-align' '--set=ON_ERROR_STOP=1' "--host=$hostName" "--port=$port" "--username=$user" "--dbname=$DatabaseName" "--command=$Sql"
    if ($LASTEXITCODE -ne 0) {
        throw 'La requête de vérification PostgreSQL a échoué.'
    }

    return ($value | Select-Object -Last 1).Trim()
}

if ((Invoke-Scalar 'select current_database()') -ne $DatabaseName) {
    throw 'La connexion ne cible pas la base de restauration attendue.'
}

$checks = [ordered]@{
    migrations = [int](Invoke-Scalar 'select count(*) from migrations') -ge 50
    tables = [int](Invoke-Scalar "select count(*) from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'") -ge 51
    demo_tenants = [int](Invoke-Scalar 'select count(*) from tenants') -ge 2
    private_document_rows = [int](Invoke-Scalar 'select count(*) from document_versions') -ge 1
    gist_exclusion = [int](Invoke-Scalar "select count(*) from pg_constraint where conname = 'vehicle_blocks_no_active_overlap_excl'") -eq 1
    contract_immutability = [int](Invoke-Scalar "select count(*) from pg_trigger where not tgisinternal and tgname in ('contract_versions_prevent_locked_update','vehicle_inspections_prevent_completed_update','rental_contracts_prevent_closed_before_finance')") -eq 3
    financial_immutability = [int](Invoke-Scalar "select count(*) from pg_trigger where not tgisinternal and tgname in ('invoices_financial_immutability','invoice_lines_financial_immutability','payments_financial_immutability','payment_allocations_financial_immutability','deposit_transactions_financial_immutability')") -eq 5
}

$manifestPath = Join-Path $privateRoot 'private-documents-manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'Le manifeste des documents privés restaurés est absent.'
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
foreach ($entry in $manifest.files) {
    $file = [System.IO.Path]::GetFullPath((Join-Path $privateRoot ([string] $entry.path)))
    if (-not $file.StartsWith($privateRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Document privé restauré absent ou hors périmètre : $($entry.path)"
    }
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash.ToLowerInvariant() -ne ([string] $entry.sha256).ToLowerInvariant()) {
        throw "Empreinte documentaire invalide : $($entry.path)"
    }
}
$checks.private_documents_manifest = $true

$documentRows = & psql '--tuples-only' '--no-align' '--field-separator=|' '--set=ON_ERROR_STOP=1' "--host=$hostName" "--port=$port" "--username=$user" "--dbname=$DatabaseName" '--command=select stored_path, size_bytes, sha256 from document_versions order by id'
if ($LASTEXITCODE -ne 0) {
    throw 'Impossible de rapprocher les documents restaurés de PostgreSQL.'
}
foreach ($row in $documentRows) {
    $parts = $row -split '\|', 3
    if ($parts.Count -ne 3) {
        throw 'Ligne documentaire PostgreSQL invalide.'
    }
    $file = [System.IO.Path]::GetFullPath((Join-Path $privateRoot $parts[0]))
    if (-not $file.StartsWith($privateRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Fichier référencé par PostgreSQL absent : $($parts[0])"
    }
    if ((Get-Item -LiteralPath $file).Length -ne [long] $parts[1] -or (Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash.ToLowerInvariant() -ne $parts[2].ToLowerInvariant()) {
        throw "Fichier incohérent avec PostgreSQL : $($parts[0])"
    }
}
$checks.private_documents_database_coherence = $true

Set-Location -LiteralPath $root
$routes = & php artisan route:list --json
if ($LASTEXITCODE -ne 0) {
    throw 'Impossible de contrôler les routes Laravel.'
}
$checks.no_public_storage_route = -not (($routes -join "`n") -match 'storage/\{')

$failed = @($checks.GetEnumerator() | Where-Object { -not $_.Value })
$checks.GetEnumerator() | ForEach-Object {
    $state = if ($_.Value) { 'PASS' } else { 'FAIL' }
    Write-Host ("[{0}] {1}" -f $state, $_.Key)
}

if ($failed.Count -gt 0) {
    throw 'La restauration ne satisfait pas tous les contrôles.'
}

Write-Host 'Sauvegarde restaurée et vérifiée avec succès.' -ForegroundColor Green
