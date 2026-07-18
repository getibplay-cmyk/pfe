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

function Assert-CommandAvailable {
    param([Parameter(Mandatory)][string]$Command)

    if (-not (Get-Command $Command -ErrorAction SilentlyContinue)) {
        throw "Outil requis introuvable : $Command"
    }
}

if ([string]::IsNullOrWhiteSpace($env:PHP_BINARY)) {
    throw 'PHP_BINARY doit désigner explicitement le binaire PHP 8.5 approuvé.'
}
$php = $env:PHP_BINARY
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw 'PHP_BINARY ne désigne pas un fichier existant.'
}
$composer = if ($env:COMPOSER_BINARY) { $env:COMPOSER_BINARY } else { 'composer' }
$npm = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
$node = 'node'

foreach ($command in @($composer, $npm, $node, 'psql', 'git')) {
    Assert-CommandAvailable -Command $command
}
if (-not (Test-Path -LiteralPath 'vendor/autoload.php' -PathType Leaf)) {
    throw 'vendor/autoload.php est absent. Exécutez composer install avant le contrôle.'
}

Invoke-Checked $php @('-r', "if (version_compare(PHP_VERSION, '8.5.0', '<') || version_compare(PHP_VERSION, '8.6.0', '>=')) { fwrite(STDERR, 'PHP 8.5 requis'.PHP_EOL); exit(1); } echo PHP_VERSION.PHP_EOL;")
Invoke-Checked $php @('-r', "foreach (['pdo_pgsql','pgsql','mbstring','openssl','fileinfo','intl'] as `$extension) { if (!extension_loaded(`$extension)) { fwrite(STDERR, `$extension.' manquante'.PHP_EOL); exit(1); } } echo 'extensions PHP OK'.PHP_EOL;")
Invoke-Checked $composer @('--version', '--no-ansi')
Invoke-Checked $composer @('validate', '--no-check-publish', '--no-interaction')
Invoke-Checked $node @('--version')
Invoke-Checked $npm @('--version')
Invoke-Checked 'psql' @('--version')
Invoke-Checked $php @('artisan', '--version', '--no-ansi')
Invoke-Checked $php @('artisan', 'migrate:status', '--no-ansi')
Invoke-Checked $php @('artisan', 'rentfleet:doctor', '--production', '--no-ansi')
Invoke-Checked $php @('artisan', 'schedule:list', '--no-ansi')

if (-not $SkipTests) {
    Invoke-Checked $php @('artisan', 'optimize:clear', '--no-ansi')
    Invoke-Checked $php @('artisan', 'rentfleet:doctor', '--env=testing', '--expect-database=rentfleet_test', '--no-ansi')
    Invoke-Checked $php @('artisan', 'test', '--no-ansi')
}

foreach ($path in @('storage', 'storage/app/private', 'bootstrap/cache')) {
    if (-not (Test-Path -LiteralPath $path -PathType Container)) {
        throw "Répertoire requis absent : $path"
    }
    $probe = Join-Path $path ('.rentfleet-write-probe-' + [Guid]::NewGuid().ToString('N'))
    try {
        New-Item -ItemType File -Path $probe | Out-Null
    } finally {
        if (Test-Path -LiteralPath $probe) {
            Remove-Item -LiteralPath $probe -Force
        }
    }
}

Invoke-Checked $php @('artisan', 'config:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'route:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'view:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'event:cache', '--no-ansi')
Invoke-Checked $php @('artisan', 'optimize', '--no-ansi')

$routesJson = & $php artisan route:list --json
if ($LASTEXITCODE -ne 0) {
    throw 'Impossible de lire la liste des routes.'
}
$forbiddenRoutes = @($routesJson | ConvertFrom-Json | Where-Object { $_.uri -match '(^|/)(register|signup)(/|$)|^storage/' })
if ($forbiddenRoutes.Count -gt 0) {
    throw 'Une route register, signup ou storage/* interdite est présente.'
}

$tracked = @(& git -c "safe.directory=$($root.Replace('\', '/'))" ls-files)
if ($LASTEXITCODE -ne 0) {
    throw 'Impossible de contrôler les fichiers suivis par Git.'
}
$forbiddenTracked = @($tracked | Where-Object {
    $_ -match '(^|/)(\.env|\.env\.testing|\.env\.production|pgpass\.conf|\.pgpass)$' -or
    $_ -match '(^|/)backups/' -or $_ -match '\.(dump|backup)$'
})
if ($forbiddenTracked.Count -gt 0) {
    throw 'Git suit au moins un secret ou artefact de sauvegarde interdit.'
}

Invoke-Checked $php @('vendor/bin/pint', '--test')
Invoke-Checked $npm @('run', 'build')
if (-not (Test-Path -LiteralPath 'public/build/manifest.json' -PathType Leaf)) {
    throw 'Le manifeste Vite est absent après le build.'
}

if (-not $KeepCaches) {
    Invoke-Checked $php @('artisan', 'optimize:clear', '--no-ansi')
}

Write-Host 'Contrôle non destructif de déploiement RentFleet réussi.' -ForegroundColor Green
