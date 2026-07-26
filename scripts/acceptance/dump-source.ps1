[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$SourceDatabase = 'rentfleet_test',
    [string]$PostgresHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$PostgresPort = 5432,
    [string]$PostgresUser = 'rentfleet_app'
)

. (Join-Path $PSScriptRoot 'common.ps1')

Assert-QaEnvironment
if ($SourceDatabase -ne 'rentfleet_test') {
    throw "Source refusée : seule la base exacte 'rentfleet_test' peut être lue."
}
if ($PostgresHost -ne $script:ExpectedHost -or
    $PostgresPort -ne $script:ExpectedPort -or
    $PostgresUser -ne $script:ExpectedUser) {
    throw 'Serveur, port ou utilisateur PostgreSQL source inattendu.'
}

$resolvedRunRoot = Resolve-QaRunRoot -RunRoot $RunRoot
Assert-QaTools -Names @('psql', 'pg_dump', 'pg_restore')
Assert-QaPgPassAvailable

$identity = Invoke-QaPsqlScalar -DatabaseName $SourceDatabase `
    -Sql "select current_database() || '|' || current_user || '|' || coalesce(host(inet_server_addr()), '') || '|' || inet_server_port()"
if ($identity -ne "$SourceDatabase|$PostgresUser|$PostgresHost|$PostgresPort") {
    throw 'La connexion source ne correspond pas à rentfleet_test et aux paramètres attendus.'
}

$sourceSnapshot = Get-QaDatabaseSnapshot -DatabaseName $SourceDatabase
if ([int] $sourceSnapshot.migrations.count -ne 69) {
    throw "La source doit contenir exactement 69 migrations appliquées."
}
foreach ($count in $sourceSnapshot.integrity_counts.Values) {
    if ([long] $count -ne 0) {
        throw 'Un compteur d’intégrité source est non nul ; dump de recette refusé.'
    }
}

$dumpDirectory = Join-Path $resolvedRunRoot 'source'
if (Test-Path -LiteralPath $dumpDirectory) {
    throw "Le dossier source existe déjà : $dumpDirectory"
}
New-Item -ItemType Directory -Path $dumpDirectory -Force | Out-Null

$dumpPath = Join-Path $dumpDirectory 'rentfleet_test.dump'
$listPath = Join-Path $dumpDirectory 'rentfleet_test.restore-list.txt'
$manifestPath = Join-Path $dumpDirectory 'manifest.json'
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
$startedAt = [DateTime]::UtcNow

$dumpArguments = @(
    '--format=custom', '--no-owner', '--no-privileges', '--no-password',
    "--host=$PostgresHost", "--port=$PostgresPort", "--username=$PostgresUser",
    "--file=$dumpPath", $SourceDatabase
)
& pg_dump @dumpArguments
$dumpExitCode = $LASTEXITCODE
if ($dumpExitCode -ne 0) {
    throw "pg_dump a échoué avec le code $dumpExitCode."
}

$restoreList = @(& pg_restore --list $dumpPath)
$listExitCode = $LASTEXITCODE
if ($listExitCode -ne 0) {
    throw "pg_restore --list a échoué avec le code $listExitCode."
}
$restoreList | Set-Content -LiteralPath $listPath -Encoding UTF8
$entryCount = @($restoreList | Where-Object {
    $line = ([string] $_).Trim()
    $line -ne '' -and -not $line.StartsWith(';')
}).Count

$stopwatch.Stop()
$dumpItem = Get-Item -LiteralPath $dumpPath
$manifest = [ordered]@{
    schema_version = 1
    lot = '06G-B'
    status = 'completed'
    source_database = $SourceDatabase
    target_database = $script:AcceptanceDatabase
    started_at_utc = $startedAt.ToString('o')
    completed_at_utc = [DateTime]::UtcNow.ToString('o')
    duration_ms = $stopwatch.ElapsedMilliseconds
    tools = [ordered]@{
        pg_dump = (& pg_dump --version | Select-Object -Last 1)
        pg_restore = (& pg_restore --version | Select-Object -Last 1)
    }
    commands = [ordered]@{
        pg_dump_exit_code = $dumpExitCode
        pg_restore_list_exit_code = $listExitCode
        restore_list_entries = $entryCount
    }
    dump = [ordered]@{
        name = $dumpItem.Name
        size_bytes = [long] $dumpItem.Length
        sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash.ToLowerInvariant()
    }
    restore_list = [ordered]@{
        name = (Split-Path -Leaf $listPath)
        size_bytes = [long] (Get-Item -LiteralPath $listPath).Length
        sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $listPath).Hash.ToLowerInvariant()
    }
    source_snapshot = $sourceSnapshot
}
$manifest | ConvertTo-Json -Depth 30 | Set-Content -LiteralPath $manifestPath -Encoding UTF8

Write-Host "Dump lecture seule créé : $dumpPath" -ForegroundColor Green
Write-Host "Taille : $($dumpItem.Length) octets ; entrées : $entryCount ; durée : $($stopwatch.ElapsedMilliseconds) ms."
