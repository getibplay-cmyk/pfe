[CmdletBinding()]
param(
    [switch]$SkipTests,
    [switch]$KeepCaches
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $root

function Invoke-Checked {
    param([Parameter(Mandatory)][string]$File, [Parameter(Mandatory)][string[]]$Arguments)

    Write-Host ("> {0} {1}" -f $File, ($Arguments -join ' '))
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "La commande '$File' a échoué avec le code $LASTEXITCODE."
    }
}

$php = if ($env:PHP_BINARY) { $env:PHP_BINARY } else { 'php' }
$npm = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
$composer = if ($env:COMPOSER_BINARY) { $env:COMPOSER_BINARY } else { 'composer' }

if (-not (Test-Path -LiteralPath 'vendor/autoload.php')) {
    throw 'vendor/autoload.php est absent. Exécutez composer install avant le contrôle.'
}

Invoke-Checked $php @('artisan', '--version', '--no-ansi')
Invoke-Checked $php @('-r', "foreach (['pdo_pgsql','pgsql'] as `$extension) { if (!extension_loaded(`$extension)) { fwrite(STDERR, `$extension.' manquante'.PHP_EOL); exit(1); } } echo PHP_VERSION.PHP_EOL;")
Invoke-Checked $composer @('--version', '--no-ansi')
Invoke-Checked $php @('artisan', 'migrate:status', '--no-ansi')
Invoke-Checked $php @('artisan', 'rentfleet:doctor', '--production', '--no-ansi')
Invoke-Checked $php @('artisan', 'config:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'route:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'view:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'event:cache', '--no-ansi')

if (-not $SkipTests) {
    Invoke-Checked $php @('artisan', 'test', '--no-ansi')
}

Invoke-Checked $php @('vendor/bin/pint', '--test')
Invoke-Checked $npm @('run', 'build')

if (-not $KeepCaches) {
    Invoke-Checked $php @('artisan', 'optimize:clear', '--no-ansi')
}

Write-Host 'Contrôle de déploiement RentFleet réussi.' -ForegroundColor Green
