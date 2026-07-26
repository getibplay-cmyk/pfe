[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
    [string]$PostgresHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$PostgresPort = 5432,
    [string]$PostgresUser = 'rentfleet_app',
    [string]$AdminUser = 'postgres',
    [switch]$ConfirmDestroy
)

. (Join-Path $PSScriptRoot 'common.ps1')

$identity = Assert-QaTargetIdentity -RunRoot $RunRoot -PostgresHost $PostgresHost `
    -PostgresPort $PostgresPort -PostgresUser $PostgresUser
if ($DatabaseName -ne $identity.Database) {
    throw 'La cible résolue ne correspond pas au nom exact autorisé.'
}
if (-not $ConfirmDestroy) {
    throw 'Destruction refusée : -ConfirmDestroy est obligatoire.'
}

Assert-QaTools -Names @('psql', 'dropdb')
if ($AdminUser -ne 'postgres') {
    throw "Destruction refusée : l’utilisateur administratif attendu est postgres."
}
$adminIdentity = Invoke-QaPsqlScalar -DatabaseName postgres `
    -Sql "select current_database() || '|' || current_user" `
    -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $AdminUser
if ($adminIdentity -ne "postgres|$AdminUser") {
    throw 'La connexion administrative PostgreSQL attendue n’est pas disponible.'
}

$arguments = @(
    '--no-password', '--force', "--host=$PostgresHost", "--port=$PostgresPort",
    "--username=$AdminUser", '--maintenance-db=postgres', $DatabaseName
)
& dropdb @arguments
if ($LASTEXITCODE -ne 0) {
    throw "dropdb a échoué avec le code $LASTEXITCODE."
}

$remainingOid = Get-QaDatabaseOid -DatabaseName $DatabaseName `
    -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
if ($remainingOid -ne '') {
    throw 'La base jetable existe encore après dropdb.'
}

$privateRoot = [System.IO.Path]::GetFullPath((Join-Path $identity.RunRoot 'private'))
if (Test-Path -LiteralPath $privateRoot) {
    if (-not (Test-QaPathWithin -Candidate $privateRoot -Parent $identity.RunRoot) -or
        $privateRoot -eq $identity.RunRoot -or
        $privateRoot -eq $script:AcceptanceRoot) {
        throw 'Suppression documentaire refusée par la garde de chemin.'
    }

    $item = Get-Item -LiteralPath $privateRoot -Force
    if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw 'Suppression documentaire refusée : point de réanalyse détecté.'
    }

    Write-Host "Suppression de la racine documentaire QA exacte : $privateRoot"
    Remove-Item -LiteralPath $privateRoot -Recurse -Force
}

$destroyed = [ordered]@{
    database = $DatabaseName
    former_oid = $identity.Oid
    destroyed_at_utc = [DateTime]::UtcNow.ToString('o')
    private_root_removed = -not (Test-Path -LiteralPath $privateRoot)
}
$destroyed | ConvertTo-Json | Set-Content `
    -LiteralPath (Join-Path $identity.RunRoot 'target-destroyed.json') -Encoding UTF8

Write-Host "Base jetable détruite : $DatabaseName." -ForegroundColor Green
