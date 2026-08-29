<?php

namespace App\Console\Commands;

use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class InstallVehicleDamageModel extends Command
{
    protected $signature = 'rentfleet:damage-v1:install
                            {source : Dossier local du bundle ONNX correspondant au backend configuré}
                            {--replace : Remplacer une paire cible invalide existante}';

    protected $description = 'Vérifie puis installe l’ONNX dommages et sa carte dans le stockage privé.';

    public function handle(VehicleDamageModelArtifact $artifact): int
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifact->configuredModelSha256()) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $artifact->configuredModelCardSha256()) !== 1) {
            $this->components->error(
                'Installation refusée : renseignez localement les deux SHA-256 privés du run qualifié.',
            );

            return self::FAILURE;
        }

        $directory = realpath((string) $this->argument('source'));
        if (! is_string($directory) || ! is_dir($directory)) {
            $this->components->error('Dossier refusé : indiquez le dossier privé du run qualifié.');

            return self::FAILURE;
        }
        $sourceModel = $directory.DIRECTORY_SEPARATOR.VehicleDamageContract::MODEL_FILENAME;
        $sourceModelCard = $directory.DIRECTORY_SEPARATOR.VehicleDamageContract::MODEL_CARD_FILENAME;
        if (! $artifact->validPair($sourceModel, $sourceModelCard)) {
            $this->components->error(
                'Paire refusée : SHA-256 privés ou contrat fermé du modèle invalides.',
            );

            return self::FAILURE;
        }

        $targetModel = $artifact->configuredModelPath();
        $targetModelCard = $artifact->configuredModelCardPath();
        if ($targetModel === ''
            || $targetModelCard === ''
            || $targetModel === $targetModelCard
            || ! $artifact->configuredPathsArePrivate()) {
            $this->components->error('Les deux chemins cibles doivent être absolus, privés et hors du dossier public.');

            return self::FAILURE;
        }
        if ($artifact->configuredIsValid()) {
            $this->components->info('La paire dommages authentique est déjà installée et vérifiée.');

            return self::SUCCESS;
        }
        if ((file_exists($targetModel) || file_exists($targetModelCard)) && ! $this->option('replace')) {
            $this->components->error(
                'Une paire cible incomplète ou invalide existe. Contrôlez-la puis relancez avec --replace.',
            );

            return self::FAILURE;
        }

        $token = (string) Str::uuid();
        $temporaryModel = $targetModel.'.installing-'.$token;
        $temporaryCard = $targetModelCard.'.installing-'.$token;
        $backupModel = $targetModel.'.replaced-'.$token;
        $backupCard = $targetModelCard.'.replaced-'.$token;
        $modelBackedUp = false;
        $cardBackedUp = false;
        $modelInstalled = false;
        $cardInstalled = false;

        try {
            File::ensureDirectoryExists(dirname($targetModel), 0700, true);
            File::ensureDirectoryExists(dirname($targetModelCard), 0700, true);
            if (! copy($sourceModel, $temporaryModel) || ! copy($sourceModelCard, $temporaryCard)) {
                throw new \RuntimeException('copy_failed');
            }
            chmod($temporaryModel, 0600);
            chmod($temporaryCard, 0600);
            if (! $artifact->validPair($temporaryModel, $temporaryCard)) {
                throw new \RuntimeException('verification_failed');
            }

            if (file_exists($targetModel)) {
                if (! File::move($targetModel, $backupModel)) {
                    throw new \RuntimeException('model_backup_failed');
                }
                $modelBackedUp = true;
            }
            if (file_exists($targetModelCard)) {
                if (! File::move($targetModelCard, $backupCard)) {
                    throw new \RuntimeException('card_backup_failed');
                }
                $cardBackedUp = true;
            }
            if (! File::move($temporaryModel, $targetModel)) {
                throw new \RuntimeException('install_failed');
            }
            $modelInstalled = true;
            if (! File::move($temporaryCard, $targetModelCard)) {
                throw new \RuntimeException('install_failed');
            }
            $cardInstalled = true;
            if (! $artifact->configuredIsValid()
                || ! chmod($targetModel, 0600)
                || ! chmod($targetModelCard, 0600)) {
                throw new \RuntimeException('install_failed');
            }
            File::delete([$backupModel, $backupCard]);
        } catch (Throwable) {
            File::delete([$temporaryModel, $temporaryCard]);
            if ($modelInstalled) {
                File::delete($targetModel);
            }
            if ($cardInstalled) {
                File::delete($targetModelCard);
            }
            if ($modelBackedUp) {
                File::move($backupModel, $targetModel);
            }
            if ($cardBackedUp) {
                File::move($backupCard, $targetModelCard);
            }
            $this->components->error('Installation privée interrompue sans activer de paire non vérifiée.');

            return self::FAILURE;
        }

        $this->components->info('ONNX dommages et carte du modèle installés dans le stockage privé.');

        return self::SUCCESS;
    }
}
