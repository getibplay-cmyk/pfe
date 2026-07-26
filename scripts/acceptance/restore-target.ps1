[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
    [string]$PostgresHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$PostgresPort = 5432,
    [string]$PostgresUser = 'rentfleet_app',
    [switch]$ConfirmRestore
)

. (Join-Path $PSScriptRoot 'common.ps1')

$identity = Assert-QaTargetIdentity -RunRoot $RunRoot -PostgresHost $PostgresHost `
    -PostgresPort $PostgresPort -PostgresUser $PostgresUser
if ($DatabaseName -ne $identity.Database) {
    throw 'La cible résolue ne correspond pas au nom exact autorisé.'
}
if (-not $ConfirmRestore) {
    throw 'Restauration refusée : -ConfirmRestore est obligatoire.'
}

Assert-QaTools -Names @('psql', 'pg_restore')
$sourceRoot = Join-Path $identity.RunRoot 'source'
$manifestPath = Join-Path $sourceRoot 'manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'Manifeste source 06G absent.'
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.schema_version -ne 1 -or
    $manifest.status -ne 'completed' -or
    $manifest.source_database -ne 'rentfleet_test' -or
    $manifest.target_database -ne $script:AcceptanceDatabase) {
    throw 'Le manifeste ne correspond pas à une source 06G autorisée.'
}

$dumpPath = [System.IO.Path]::GetFullPath((Join-Path $sourceRoot ([string] $manifest.dump.name)))
if (-not (Test-QaPathWithin -Candidate $dumpPath -Parent $sourceRoot) -or
    -not (Test-Path -LiteralPath $dumpPath -PathType Leaf)) {
    throw 'Dump source absent ou hors de la racine temporaire.'
}
$dumpItem = Get-Item -LiteralPath $dumpPath
$dumpHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash.ToLowerInvariant()
if ($dumpItem.Length -ne [long] $manifest.dump.size_bytes -or
    $dumpHash -ne ([string] $manifest.dump.sha256).ToLowerInvariant()) {
    throw 'Taille ou empreinte SHA-256 du dump invalide.'
}

$publicTables = [long] (Invoke-QaPsqlScalar -DatabaseName $DatabaseName `
    -Sql "select count(*) from pg_tables where schemaname='public'")
if ($publicTables -ne 0) {
    throw 'La cible doit être vide avant la restauration initiale.'
}

$restoreArguments = @(
    '--exit-on-error', '--single-transaction', '--no-owner', '--no-privileges',
    '--no-password', "--host=$PostgresHost", "--port=$PostgresPort",
    "--username=$PostgresUser", "--dbname=$DatabaseName", $dumpPath
)
& pg_restore @restoreArguments
if ($LASTEXITCODE -ne 0) {
    throw "pg_restore a échoué avec le code $LASTEXITCODE."
}

$privateRoot = Join-Path $identity.RunRoot 'private'
if (Test-Path -LiteralPath $privateRoot) {
    throw 'La racine documentaire QA existe déjà ; aucune suppression implicite n’est autorisée.'
}
New-Item -ItemType Directory -Path $privateRoot -Force | Out-Null
Copy-Item -LiteralPath $manifestPath -Destination (Join-Path $privateRoot 'source-manifest.json')

Write-Host "Restauration transactionnelle terminée dans '$DatabaseName'." -ForegroundColor Green
Write-Host "Stockage privé fictif isolé : $privateRoot"
