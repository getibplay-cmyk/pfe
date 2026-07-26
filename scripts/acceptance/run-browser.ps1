[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [ValidateSet('e2', 'g2')][string]$Harness = 'e2',
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
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
Assert-QaTools -Names @('python')

$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$privateRoot = Join-Path $identity.RunRoot 'private'
$harnessPath = if ($Harness -eq 'e2') {
    Join-Path $root 'tests\Browser\lot06f_e2_browser.py'
} else {
    Join-Path $root 'tests\Browser\lot06f_g2_browser.py'
}
if (-not (Test-Path -LiteralPath $harnessPath -PathType Leaf)) {
    throw 'Harnais navigateur introuvable.'
}

$previous = @{
    APP_ENV = $env:APP_ENV
    DB_CONNECTION = $env:DB_CONNECTION
    DB_DATABASE = $env:DB_DATABASE
    PRIVATE_DOCUMENT_ROOT = $env:PRIVATE_DOCUMENT_ROOT
    RENTFLEET_ACCEPTANCE_MODE = $env:RENTFLEET_ACCEPTANCE_MODE
    RENTFLEET_QA_DATABASE = $env:RENTFLEET_QA_DATABASE
    PHP_BINARY = $env:PHP_BINARY
}

try {
    $env:APP_ENV = 'testing'
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_DATABASE = $DatabaseName
    $env:PRIVATE_DOCUMENT_ROOT = $privateRoot
    $env:RENTFLEET_ACCEPTANCE_MODE = '1'
    $env:RENTFLEET_QA_DATABASE = $DatabaseName
    $env:PHP_BINARY = $PhpBinary
    Set-Location -LiteralPath $root

    $browserRoot = Join-Path $identity.RunRoot 'browser'
    New-Item -ItemType Directory -Path $browserRoot -Force | Out-Null
    $output = Join-Path $browserRoot ("lot06g-$Harness-results.json")
    $screenshots = Join-Path $browserRoot ("lot06g-$Harness-screenshots")

    if ($Harness -eq 'e2') {
        & python $harnessPath --php $PhpBinary --output $output --screenshots $screenshots
    } else {
        & python $harnessPath --root $root --php $PhpBinary `
            --output $output --screenshots $screenshots
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Le harnais navigateur $Harness a échoué avec le code $LASTEXITCODE."
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
