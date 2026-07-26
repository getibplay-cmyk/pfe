[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$RunRoot,
    [string]$DatabaseName = 'rentfleet_06g_acceptance',
    [switch]$ConfirmReset
)

. (Join-Path $PSScriptRoot 'common.ps1')

Assert-QaStaticGuard -DatabaseName $DatabaseName -RunRoot $RunRoot | Out-Null
if (-not $ConfirmReset) {
    throw 'Remise à zéro refusée : -ConfirmReset est obligatoire.'
}

& (Join-Path $PSScriptRoot 'destroy-target.ps1') `
    -RunRoot $RunRoot -DatabaseName $DatabaseName -ConfirmDestroy
if ($LASTEXITCODE -ne 0) {
    throw 'La destruction contrôlée de la cible a échoué.'
}

& (Join-Path $PSScriptRoot 'prepare-target.ps1') `
    -RunRoot $RunRoot -DatabaseName $DatabaseName -ConfirmCreate
if ($LASTEXITCODE -ne 0) {
    throw 'La recréation contrôlée de la cible a échoué.'
}

& (Join-Path $PSScriptRoot 'restore-target.ps1') `
    -RunRoot $RunRoot -DatabaseName $DatabaseName -ConfirmRestore
if ($LASTEXITCODE -ne 0) {
    throw 'La restauration contrôlée de la cible a échoué.'
}

& (Join-Path $PSScriptRoot 'verify-target.ps1') `
    -RunRoot $RunRoot -DatabaseName $DatabaseName
if ($LASTEXITCODE -ne 0) {
    throw 'La vérification de la cible remise à zéro a échoué.'
}

Write-Host 'Cible 06G remise à zéro et revérifiée.' -ForegroundColor Green
