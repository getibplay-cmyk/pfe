<?php

namespace App\Console\Commands;

use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class InstallVehicleColorModel extends Command
{
    protected $signature = 'rentfleet:color-v8:install
                            {source : Dossier local extrait du bundle final S7 couleur v8}
                            {--replace : Remplacer une paire cible invalide existante}';

    protected $description = 'Vérifie puis installe la paire ONNX/métadonnées couleur v8 dans le stockage privé.';

    public function handle(VehicleColorModelArtifact $artifact): int
    {
        $directory = realpath((string) $this->argument('source'));
        if (! is_string($directory) || ! is_dir($directory)) {
            $this->components->error('Dossier refusé : extrayez d’abord le bundle final couleur v8.');

            return self::FAILURE;
        }
        $sourceModel = $directory.DIRECTORY_SEPARATOR.VehicleColorContract::MODEL_FILENAME;
        $sourceMetadata = $directory.DIRECTORY_SEPARATOR.VehicleColorContract::METADATA_FILENAME;
        if (! $artifact->validPair($sourceModel, $sourceMetadata)) {
            $this->components->error(
                'Bundle refusé : tailles, SHA-256 ou contrat des métadonnées S7 couleur v8 invalides.',
            );

            return self::FAILURE;
        }

        $targetModel = $artifact->configuredModelPath();
        $targetMetadata = $artifact->configuredMetadataPath();
        if ($targetModel === ''
            || $targetMetadata === ''
            || $targetModel === $targetMetadata
            || ! $artifact->configuredPathsArePrivate()) {
            $this->components->error('Les deux chemins cibles doivent être absolus, privés et hors du dossier public.');

            return self::FAILURE;
        }
        if ($artifact->configuredIsValid()) {
            $this->components->info('La paire couleur v8 authentique est déjà installée et vérifiée.');

            return self::SUCCESS;
        }
        if ((file_exists($targetModel) || file_exists($targetMetadata)) && ! $this->option('replace')) {
            $this->components->error(
                'Une paire cible incomplète ou invalide existe. Contrôlez-la puis relancez avec --replace.',
            );

            return self::FAILURE;
        }

        $token = (string) Str::uuid();
        $temporaryModel = $targetModel.'.installing-'.$token;
        $temporaryMetadata = $targetMetadata.'.installing-'.$token;
        $backupModel = $targetModel.'.replaced-'.$token;
        $backupMetadata = $targetMetadata.'.replaced-'.$token;
        $modelBackedUp = false;
        $metadataBackedUp = false;
        $modelInstalled = false;
        $metadataInstalled = false;

        try {
            File::ensureDirectoryExists(dirname($targetModel), 0700, true);
            File::ensureDirectoryExists(dirname($targetMetadata), 0700, true);
            if (! copy($sourceModel, $temporaryModel) || ! copy($sourceMetadata, $temporaryMetadata)) {
                throw new \RuntimeException('copy_failed');
            }
            chmod($temporaryModel, 0600);
            chmod($temporaryMetadata, 0600);
            if (! $artifact->validPair($temporaryModel, $temporaryMetadata)) {
                throw new \RuntimeException('verification_failed');
            }

            if (file_exists($targetModel)) {
                if (! File::move($targetModel, $backupModel)) {
                    throw new \RuntimeException('model_backup_failed');
                }
                $modelBackedUp = true;
            }
            if (file_exists($targetMetadata)) {
                if (! File::move($targetMetadata, $backupMetadata)) {
                    throw new \RuntimeException('metadata_backup_failed');
                }
                $metadataBackedUp = true;
            }
            if (! File::move($temporaryModel, $targetModel)) {
                throw new \RuntimeException('install_failed');
            }
            $modelInstalled = true;
            if (! File::move($temporaryMetadata, $targetMetadata)) {
                throw new \RuntimeException('install_failed');
            }
            $metadataInstalled = true;
            if (! $artifact->configuredIsValid()) {
                throw new \RuntimeException('install_failed');
            }

            if (! chmod($targetModel, 0600) || ! chmod($targetMetadata, 0600)) {
                throw new \RuntimeException('permissions_failed');
            }
            File::delete([$backupModel, $backupMetadata]);
        } catch (Throwable) {
            File::delete([$temporaryModel, $temporaryMetadata]);
            if ($modelInstalled) {
                File::delete($targetModel);
            }
            if ($metadataInstalled) {
                File::delete($targetMetadata);
            }
            if ($modelBackedUp) {
                File::move($backupModel, $targetModel);
            }
            if ($metadataBackedUp) {
                File::move($backupMetadata, $targetMetadata);
            }
            $this->components->error('Installation privée interrompue sans activer de paire non vérifiée.');

            return self::FAILURE;
        }

        $this->components->info(
            'Paire ONNX/métadonnées couleur v8 installée dans le stockage privé (SHA-256 vérifiés).',
        );

        return self::SUCCESS;
    }
}
