[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
    [string]$PostgresHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$PostgresPort = 5432,
    [string]$PostgresUser = 'rentfleet_app'
)

. (Join-Path $PSScriptRoot 'common.ps1')

$identity = Assert-QaTargetIdentity -RunRoot $RunRoot -PostgresHost $PostgresHost `
    -PostgresPort $PostgresPort -PostgresUser $PostgresUser
if ($DatabaseName -ne $identity.Database) {
    throw 'La cible résolue ne correspond pas au nom exact autorisé.'
}

$manifestPath = Join-Path (Join-Path $identity.RunRoot 'source') 'manifest.json'
$privateRoot = Join-Path $identity.RunRoot 'private'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf) -or
    -not (Test-Path -LiteralPath $privateRoot -PathType Container)) {
    throw 'Manifeste source ou stockage privé QA absent.'
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$sourceDatabase = [string] $manifest.source_database
if ($sourceDatabase -ne 'rentfleet_test') {
    throw 'Le manifeste ne référence pas la source protégée attendue en lecture seule.'
}

$sourceSnapshot = Get-QaDatabaseSnapshot -DatabaseName $sourceDatabase
# The source must still match the snapshot that was captured with the dump.
Assert-QaSnapshotEqual -Expected $manifest.source_snapshot -Actual $sourceSnapshot

$actualSnapshot = Get-QaDatabaseSnapshot -DatabaseName $DatabaseName
$sourceComparable = ConvertTo-QaComparableRestoreSnapshot `
    -Snapshot $sourceSnapshot `
    -PortableCatalog (Get-QaPortableCatalogSnapshot -DatabaseName $sourceDatabase)
$targetComparable = ConvertTo-QaComparableRestoreSnapshot `
    -Snapshot $actualSnapshot `
    -PortableCatalog (Get-QaPortableCatalogSnapshot -DatabaseName $DatabaseName)
Assert-QaSnapshotEqual -Expected $sourceComparable -Actual $targetComparable

if ([int] $actualSnapshot.migrations.count -ne 69) {
    throw 'La cible ne contient pas exactement 69 migrations.'
}
if (@($actualSnapshot.notification_lifecycle_columns).Count -ne 5) {
    throw 'Les cinq colonnes de cycle de vie des notifications ne sont pas présentes.'
}
if (@($actualSnapshot.rbac_triggers).Count -ne 3) {
    throw 'Les trois triggers RBAC G2 ne sont pas présents.'
}
if (@($actualSnapshot.g2_indexes).Count -ne 3) {
    throw 'Les trois index G2 attendus ne sont pas présents.'
}
foreach ($count in $actualSnapshot.integrity_counts.Values) {
    if ([long] $count -ne 0) {
        throw 'Un des neuf compteurs d’intégrité est non nul.'
    }
}

$php = if ($env:PHP_BINARY) {
    $env:PHP_BINARY
} else {
    'C:\Users\pc\.config\herd\bin\php85\php.exe'
}
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw 'PHP Herd 8.5.8 est introuvable.'
}

$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$previous = @{
    APP_ENV = $env:APP_ENV
    DB_CONNECTION = $env:DB_CONNECTION
    DB_DATABASE = $env:DB_DATABASE
    PRIVATE_DOCUMENT_ROOT = $env:PRIVATE_DOCUMENT_ROOT
    RENTFLEET_ACCEPTANCE_MODE = $env:RENTFLEET_ACCEPTANCE_MODE
}

try {
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_DATABASE = $DatabaseName
    $env:PRIVATE_DOCUMENT_ROOT = $privateRoot
    $env:RENTFLEET_ACCEPTANCE_MODE = '1'
    Set-Location -LiteralPath $root

    & $php artisan migrate:status --env=testing --no-ansi
    if ($LASTEXITCODE -ne 0) {
        throw 'migrate:status a échoué sur la cible QA.'
    }
    & $php artisan rentfleet:doctor --env=testing --expect-database=$DatabaseName --no-ansi
    if ($LASTEXITCODE -ne 0) {
        throw 'rentfleet:doctor a échoué sur la cible QA.'
    }
} finally {
    foreach ($name in $previous.Keys) {
        [System.Environment]::SetEnvironmentVariable(
            $name,
            $previous[$name],
            [System.EnvironmentVariableTarget]::Process
        )
    }
}

$verification = [ordered]@{
    database = $DatabaseName
    oid = $identity.Oid
    verified_at_utc = [DateTime]::UtcNow.ToString('o')
    migrations = [int] $actualSnapshot.migrations.count
    notification_columns = @($actualSnapshot.notification_lifecycle_columns).Count
    rbac_triggers = @($actualSnapshot.rbac_triggers).Count
    g2_indexes = @($actualSnapshot.g2_indexes).Count
    integrity_counts = $actualSnapshot.integrity_counts
    result = 'pass'
}
$verification | ConvertTo-Json -Depth 10 | Set-Content `
    -LiteralPath (Join-Path $identity.RunRoot 'target-verification.json') -Encoding UTF8

Write-Host 'Cible 06G restaurée et vérifiée sans divulgation de données.' -ForegroundColor Green
