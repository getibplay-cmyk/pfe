[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
    [string]$PostgresHost = '127.0.0.1',
    [ValidateRange(1, 65535)][int]$PostgresPort = 5432,
    [string]$PostgresUser = 'rentfleet_app',
    [string]$AdminUser = 'postgres',
    [switch]$ConfirmCreate
)

. (Join-Path $PSScriptRoot 'common.ps1')

$resolvedRunRoot = Assert-QaStaticGuard -DatabaseName $DatabaseName -RunRoot $RunRoot `
    -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
if (-not $ConfirmCreate) {
    throw 'Création refusée : -ConfirmCreate est obligatoire.'
}

Assert-QaTools -Names @('psql', 'createdb', 'dropdb')
Assert-QaPgPassAvailable
if ($AdminUser -ne 'postgres') {
    throw "Création refusée : l’utilisateur administratif attendu est postgres."
}
Assert-QaTargetAbsent -RunRoot $resolvedRunRoot -PostgresHost $PostgresHost `
    -PostgresPort $PostgresPort -PostgresUser $PostgresUser

New-Item -ItemType Directory -Path $resolvedRunRoot -Force | Out-Null

$adminIdentity = Invoke-QaPsqlScalar -DatabaseName postgres `
    -Sql "select current_database() || '|' || current_user" `
    -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $AdminUser
if ($adminIdentity -ne "postgres|$AdminUser") {
    throw 'La connexion administrative PostgreSQL attendue n’est pas disponible.'
}

$arguments = @(
    '--no-password', "--host=$PostgresHost", "--port=$PostgresPort",
    "--username=$AdminUser", "--owner=$PostgresUser", '--encoding=UTF8',
    '--maintenance-db=postgres', $DatabaseName
)
$created = $false
try {
    & createdb @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "createdb a échoué avec le code $LASTEXITCODE. Aucun contournement administratif n’a été tenté."
    }
    $created = $true

    $identity = Assert-QaTargetIdentity -RunRoot $resolvedRunRoot `
        -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
} catch {
    if ($created) {
        $createdOid = Get-QaDatabaseOid -DatabaseName $DatabaseName `
            -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
        if ($DatabaseName -ne $script:AcceptanceDatabase -or
            $DatabaseName -in $script:ProtectedDatabases -or
            [string]::IsNullOrWhiteSpace($createdOid)) {
            throw 'La connexion applicative a échoué et la garde refuse de supprimer une cible non vérifiable.'
        }

        & dropdb --no-password --force "--host=$PostgresHost" "--port=$PostgresPort" `
            "--username=$AdminUser" '--maintenance-db=postgres' $DatabaseName
        if ($LASTEXITCODE -ne 0) {
            throw 'La connexion applicative a échoué et la suppression de sécurité de la cible a également échoué.'
        }

        $remainingOid = Get-QaDatabaseOid -DatabaseName $DatabaseName `
            -PostgresHost $PostgresHost -PostgresPort $PostgresPort -PostgresUser $PostgresUser
        if ($remainingOid -ne '') {
            throw 'La connexion applicative a échoué et la cible existe encore après la suppression de sécurité.'
        }
    }

    throw
}

$metadata = [ordered]@{
    database = $identity.Database
    oid = $identity.Oid
    created_at_utc = [DateTime]::UtcNow.ToString('o')
    host = $PostgresHost
    port = $PostgresPort
    user = $PostgresUser
}
$metadata | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $resolvedRunRoot 'target-created.json') -Encoding UTF8

Write-Host "Base jetable créée et contrôlée : $DatabaseName (OID $($identity.Oid))." -ForegroundColor Green
