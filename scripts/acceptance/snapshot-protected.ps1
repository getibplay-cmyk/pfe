[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [ValidateSet('initial', 'final')][string]$Phase
)

. (Join-Path $PSScriptRoot 'common.ps1')

Assert-QaEnvironment
$resolvedRunRoot = Resolve-QaRunRoot -RunRoot $RunRoot
Assert-QaTools -Names @('psql')
Assert-QaPgPassAvailable
New-Item -ItemType Directory -Path $resolvedRunRoot -Force | Out-Null

$databases = [ordered]@{}
foreach ($database in $script:ProtectedDatabases) {
    $identity = Invoke-QaPsqlScalar -DatabaseName $database `
        -Sql "select current_database() || '|' || current_user || '|' || coalesce(host(inet_server_addr()), '') || '|' || inet_server_port()"
    if ($identity -ne "$database|$script:ExpectedUser|$script:ExpectedHost|$script:ExpectedPort") {
        throw "L’identité PostgreSQL de la base protégée '$database' est inattendue."
    }

    $schema = Get-QaDatabaseSnapshot -DatabaseName $database
    $data = Get-QaDataSnapshot -DatabaseName $database
    $databases[$database] = [ordered]@{
        oid = Get-QaDatabaseOid -DatabaseName $database
        migrations = $schema.migrations
        catalog = $schema.catalog
        notification_lifecycle_columns = $schema.notification_lifecycle_columns
        rbac_triggers = $schema.rbac_triggers
        g2_indexes = $schema.g2_indexes
        integrity_counts = $schema.integrity_counts
        data = $data
    }

    Write-Host "$database : OID $($databases[$database].oid), $($schema.migrations.count) migrations, $($data.tables) tables, $($data.rows) lignes, empreinte $($data.content_sha256)"
}

$snapshot = [ordered]@{
    phase = $Phase
    captured_at_utc = [DateTime]::UtcNow.ToString('o')
    databases = $databases
}
$path = Join-Path $resolvedRunRoot "protected-$Phase.json"
$snapshot | ConvertTo-Json -Depth 30 | Set-Content -LiteralPath $path -Encoding UTF8

if ($Phase -eq 'final') {
    $initialPath = Join-Path $resolvedRunRoot 'protected-initial.json'
    if (-not (Test-Path -LiteralPath $initialPath -PathType Leaf)) {
        throw 'L’empreinte initiale des bases protégées est absente.'
    }

    $initial = Get-Content -Raw -LiteralPath $initialPath | ConvertFrom-Json
    $expected = $initial.databases | ConvertTo-Json -Depth 30 -Compress
    $actual = $snapshot.databases | ConvertTo-Json -Depth 30 -Compress
    if ($expected -ne $actual) {
        throw 'Une base protégée diffère de son empreinte initiale.'
    }
}

Write-Host "Empreinte $Phase des bases protégées enregistrée sans contenu sensible." -ForegroundColor Green
