[CmdletBinding()]
param(
    [string]$RunRoot = 'C:\tmp\RentFleet06G\static-guard'
)

. (Join-Path $PSScriptRoot 'common.ps1')

$previous = @{
    APP_ENV = $env:APP_ENV
    DB_CONNECTION = $env:DB_CONNECTION
    RENTFLEET_ACCEPTANCE_MODE = $env:RENTFLEET_ACCEPTANCE_MODE
}

function Assert-Refused {
    param([Parameter(Mandatory)][scriptblock]$Action)

    try {
        & $Action
    } catch {
        return
    }

    throw 'Une configuration dangereuse a été acceptée par la garde statique.'
}

try {
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'pgsql'
    $env:RENTFLEET_ACCEPTANCE_MODE = '1'

    Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot $RunRoot | Out-Null

    foreach ($database in @(
        'rentfleet',
        'rentfleet_test',
        'rentfleet_restore_test',
        'rentfleet_06g_acceptance_copy',
        'rentfleet_06g_acceptanc',
        ''
    )) {
        Assert-Refused { Assert-QaStaticGuard -DatabaseName $database -RunRoot $RunRoot | Out-Null }
    }

    $env:RENTFLEET_ACCEPTANCE_MODE = $null
    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot $RunRoot | Out-Null }
    $env:RENTFLEET_ACCEPTANCE_MODE = '1'

    $env:APP_ENV = 'local'
    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot $RunRoot | Out-Null }
    $env:APP_ENV = 'testing'

    $env:DB_CONNECTION = 'mysql'
    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot $RunRoot | Out-Null }
    $env:DB_CONNECTION = 'pgsql'

    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot 'C:\tmp\RentFleet06G' | Out-Null }
    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot 'C:\Users\pc' | Out-Null }
    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot $RunRoot -PostgresPort 5433 | Out-Null }
    Assert-Refused { Assert-QaStaticGuard -DatabaseName 'rentfleet_06g_acceptance' -RunRoot $RunRoot -PostgresUser 'postgres' | Out-Null }

    Write-Host 'Gardes statiques 06G-B validées.' -ForegroundColor Green
} finally {
    foreach ($name in $previous.Keys) {
        [System.Environment]::SetEnvironmentVariable(
            $name,
            $previous[$name],
            [System.EnvironmentVariableTarget]::Process
        )
    }
}
