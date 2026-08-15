<?php

namespace App\Console\Commands;

use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastModelArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class InstallDemandForecastModel extends Command
{
    protected $signature = 'rentfleet:demand-model:install
                            {source : Chemin local du bundle J5 authentique}
                            {--replace : Remplacer un fichier cible invalide existant}';

    protected $description = 'Vérifie puis installe le bundle HGB J5 exact dans le stockage privé.';

    public function handle(DemandForecastModelArtifact $artifact): int
    {
        $sourceInput = (string) $this->argument('source');
        $source = realpath($sourceInput);
        if (! is_string($source) || ! $artifact->valid($source)) {
            $this->components->error(
                'Fichier refusé : le bundle doit mesurer exactement '
                .number_format(DemandForecastContract::MODEL_ARTIFACT_BYTES, 0, ',', ' ')
                .' octets et porter le SHA-256 J5 attendu.',
            );

            return self::FAILURE;
        }

        $target = $artifact->configuredPath();
        if ($target === '') {
            $this->components->error('DEMAND_FORECAST_MODEL_PATH est vide.');

            return self::FAILURE;
        }
        if (! $artifact->configuredPathIsPrivate()) {
            $this->components->error('Le chemin cible doit être absolu, privé et hors du dossier public.');

            return self::FAILURE;
        }
        if ($artifact->valid($target)) {
            $this->components->info('Le bundle HGB J5 authentique est déjà installé et vérifié.');

            return self::SUCCESS;
        }
        if (file_exists($target) && ! $this->option('replace')) {
            $this->components->error(
                'Un fichier cible invalide existe déjà. Contrôlez-le puis relancez avec --replace.',
            );

            return self::FAILURE;
        }

        $directory = dirname($target);
        $temporary = $target.'.installing-'.Str::uuid();
        $backup = $target.'.replaced-'.Str::uuid();

        try {
            File::ensureDirectoryExists($directory, 0700, true);
            if (! copy($source, $temporary)) {
                throw new \RuntimeException('copy_failed');
            }
            chmod($temporary, 0600);
            if (! $artifact->valid($temporary)) {
                throw new \RuntimeException('verification_failed');
            }

            $hadTarget = file_exists($target);
            if ($hadTarget && ! File::move($target, $backup)) {
                throw new \RuntimeException('backup_failed');
            }
            try {
                if (! File::move($temporary, $target)) {
                    throw new \RuntimeException('install_failed');
                }
            } catch (Throwable $exception) {
                if ($hadTarget && file_exists($backup)) {
                    File::move($backup, $target);
                }
                throw $exception;
            }
            if (file_exists($backup)) {
                File::delete($backup);
            }
            chmod($target, 0600);
        } catch (Throwable) {
            if (file_exists($temporary)) {
                File::delete($temporary);
            }
            $this->components->error('Installation privée interrompue sans activer de bundle non vérifié.');

            return self::FAILURE;
        }

        if (! $artifact->configuredIsValid()) {
            $this->components->error('La vérification finale du bundle installé a échoué.');

            return self::FAILURE;
        }

        $this->components->info(
            'Bundle HGB J5 authentique installé dans le stockage privé (SHA-256 vérifié).',
        );

        return self::SUCCESS;
    }
}
