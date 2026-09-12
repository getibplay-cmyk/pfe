<?php

namespace App\Support\Intelligence\Training;

use App\Models\ModelTrainingDataset;
use App\Models\User;
use App\Models\VisionAnnotation;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Ui\UiText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class ExportVisionAnnotations
{
    public function __construct(private VisionAnnotationWorkbench $annotations, private TrainingWorkbench $workbench) {}

    public function handle(User $user, string $family, array $ids, string $name): ModelTrainingDataset
    {
        TrainingWorkbench::owner($user);
        abort_unless(isset(VisionAnnotationWorkbench::MODELS[$family]) && count($ids) >= 1 && count($ids) <= 50, 422);
        abort_unless(class_exists(ZipArchive::class) && function_exists('imagecreatefromstring'), 503, UiText::t('La préparation des images est temporairement indisponible.'));
        $disk = IntelligencePrivateStorage::disk('model_training.disk');
        $directory = 'intelligence/training/preparation/'.Str::uuid();
        $disk->makeDirectory($directory);
        $zipPath = 'intelligence/training/exports/'.Str::uuid().'.zip';
        $dataset = null;
        try {
            return DB::transaction(function () use ($user, $family, $ids, $name, $disk, $directory, $zipPath, &$dataset) {
                $records = VisionAnnotation::where('family', $family)->whereIn('id', $ids)->orderBy($family.'_run_id')->get();
                abort_unless($records->count() === count(array_unique($ids)), 404);
                $rows = [];
                $images = [];
                $totalBytes = 0;
                foreach ($records as $annotation) {
                    $model = VisionAnnotationWorkbench::MODELS[$family];
                    $run = $model::whereKey($annotation->{$family.'_run_id'})->lockForUpdate()->firstOrFail();
                    Gate::forUser($user)->authorize('view', $run);
                    abort_unless((int) VisionAnnotation::where($family.'_run_id', $run->id)->max('revision') === (int) $annotation->revision, 409, UiText::t('Une annotation sélectionnée a été remplacée. Sélectionnez sa dernière version.'));
                    if (! $run->vehicle_id) {
                        throw ValidationException::withMessages(['annotations' => UiText::t('Rattachez les analyses à leur véhicule avant de préparer un jeu d’apprentissage.')]);
                    }
                    $image = $this->annotations->image($family, $run);
                    abort_unless(hash_equals($annotation->source_sha256, $image['sha256']), 409);
                    $decoded = @imagecreatefromstring($image['bytes']);
                    if ($decoded === false) {
                        throw ValidationException::withMessages(['annotations' => UiText::t('Une image sélectionnée est illisible.')]);
                    }
                    $temporary = $disk->path($directory.'/'.$annotation->id.'.jpg');
                    try {
                        if (! imagejpeg($decoded, $temporary, 95)) {
                            throw new \RuntimeException('ANNOTATION_IMAGE_ENCODING_FAILED');
                        }
                    } finally {
                        unset($decoded, $image);
                    }
                    chmod($temporary, 0600);
                    $hash = hash_file('sha256', $temporary);
                    $totalBytes += filesize($temporary);
                    if ($totalBytes > 64 * 1024 * 1024) {
                        throw ValidationException::withMessages(['annotations' => UiText::t('Limitez chaque export à 64 Mo d’images. Préparez plusieurs jeux si nécessaire.')]);
                    }
                    $images[$hash] = $temporary;
                    $rows[] = ['key' => 'annotation_'.$annotation->id, 'group' => 'vehicle_'.$run->vehicle_id, 'image_sha256' => $hash, ...$annotation->truth];
                }
                $dataset = $this->workbench->stage($user, ['schema_version' => '1.0', 'family' => $family, 'rows' => $rows], ['name' => $name, 'source_note' => UiText::t('Annotations humaines validées dans le SaaS'), 'shared' => false]);
                $zip = new ZipArchive;
                $zipFile = $disk->path($directory.'/bundle.zip');
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('ANNOTATION_ARCHIVE_CREATE_FAILED');
                }
                try {
                    $manifest = $disk->get($dataset->stored_path);
                    if (! hash_equals($dataset->sha256, hash('sha256', $manifest)) || ! $zip->addFromString('dataset.json', $manifest)) {
                        throw new \RuntimeException('ANNOTATION_MANIFEST_INVALID');
                    }
                    foreach ($images as $hash => $path) {
                        if (! $zip->addFile($path, 'images/'.$hash.'.jpg')) {
                            throw new \RuntimeException('ANNOTATION_ARCHIVE_IMAGE_FAILED');
                        }
                    }
                } finally {
                    if (! $zip->close()) {
                        throw new \RuntimeException('ANNOTATION_ARCHIVE_CLOSE_FAILED');
                    }
                }
                chmod($zipFile, 0600);
                $disk->move($directory.'/bundle.zip', $zipPath);
                $zipHash = hash_file('sha256', IntelligencePrivateStorage::path('model_training.disk', $zipPath));
                DB::table('training_annotation_exports')->insert(['tenant_id' => $user->tenant_id, 'dataset_id' => $dataset->id, 'stored_path' => $zipPath, 'sha256' => $zipHash, 'created_at' => now()]);
                DB::table('training_annotation_export_rows')->insert($records->map(fn ($annotation) => ['tenant_id' => $user->tenant_id, 'dataset_id' => $dataset->id, 'annotation_id' => $annotation->id])->all());

                return $dataset;
            });
        } catch (\Throwable $exception) {
            $disk->delete($zipPath);
            if ($dataset) {
                $disk->delete($dataset->stored_path);
            }
            throw $exception;
        } finally {
            $disk->deleteDirectory($directory);
        }
    }
}
