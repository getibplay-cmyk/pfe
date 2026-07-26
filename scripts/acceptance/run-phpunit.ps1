[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
    [string[]]$TestArguments = @(),
    [string]$PhpBinary = 'C:\Users\pc\.config\herd\bin\php85\php.exe'
)

. (Join-Path $PSScriptRoot 'common.ps1')

$identity = Assert-QaTargetIdentity -RunRoot $RunRoot
if ($DatabaseName -ne $identity.Database) {
    throw 'La cible résolue ne correspond pas au nom exact autorisé.'
}
if (-not (Test-Path -LiteralPath $PhpBinary -PathType Leaf)) {
    throw 'PHP Herd 8.5.8 est introuvable.'
}

$version = (& $PhpBinary -r 'echo PHP_VERSION;')
if ($LASTEXITCODE -ne 0 -or $version -ne '8.5.8') {
    throw 'Le harnais exige exactement PHP Herd 8.5.8.'
}

$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$privateRoot = Join-Path $identity.RunRoot 'private'
if (-not (Test-Path -LiteralPath $privateRoot -PathType Container)) {
    New-Item -ItemType Directory -Path $privateRoot -Force | Out-Null
}

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

    & $PhpBinary artisan test @TestArguments
    if ($LASTEXITCODE -ne 0) {
        throw "PHPUnit a échoué avec le code $LASTEXITCODE sur la cible QA."
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
