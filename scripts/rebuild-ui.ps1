[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

$npm = if ($env:OS -eq 'Windows_NT') { 'npm.cmd' } else { 'npm' }
$php = if ($env:PHP_BINARY) { $env:PHP_BINARY } else { 'php' }

foreach ($command in @($npm, $php)) {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "Outil requis introuvable : $command"
    }
}
if (-not (Test-Path -LiteralPath 'vendor/autoload.php' -PathType Leaf)) {
    throw 'Exécutez composer install avant de reconstruire l’interface.'
}
if (Test-Path -LiteralPath 'public/hot') {
    throw 'Un serveur Vite de développement est déclaré. Arrêtez npm run dev avant cette reconstruction. Si le serveur est déjà arrêté, retirez uniquement le fichier public/hot obsolète.'
}

function Invoke-Checked {
    param([string]$File, [string[]]$Arguments)
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Échec de $File ; la reconstruction est interrompue."
    }
}

Invoke-Checked $npm @('ci')
Invoke-Checked $npm @('run', 'build')
Invoke-Checked $php @('artisan', 'view:clear')
Invoke-Checked $php @('artisan', 'config:clear')

Write-Host 'Interface reconstruite. Rechargez la page avec Ctrl+F5. Aucune donnée métier n’a été modifiée.' -ForegroundColor Green
