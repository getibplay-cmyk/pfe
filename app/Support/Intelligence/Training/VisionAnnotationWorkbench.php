<?php

namespace App\Support\Intelligence\Training;

use App\Models\ModelTrainingDataset;
use App\Models\User;
use App\Models\VehicleColorPredictionRun;
use App\Models\VehicleDamagePredictionRun;
use App\Models\VehiclePlatePredictionRun;
use App\Models\VisionAnnotation;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateInputArtifact;
use App\Support\Ui\UiText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VisionAnnotationWorkbench
{
    public const MODELS = ['color' => VehicleColorPredictionRun::class, 'plate' => VehiclePlatePredictionRun::class, 'damage' => VehicleDamagePredictionRun::class];

    public function run(User $user, string $family, string $key, string $ability = 'view'): Model
    {
        abort_unless(isset(self::MODELS[$family]), 404);
        $model = self::MODELS[$family];
        $run = $model::where('run_id', $key)->firstOrFail();
        Gate::forUser($user)->authorize($ability, $run);

        return $run;
    }

    public function image(string $family, Model $run): array
    {
        $valid = match ($family) {
            'color' => app(VehicleColorInputArtifact::class)->valid($run),
            'damage' => app(VehicleDamageInputArtifact::class)->valid($run),
            'plate' => app(VehiclePlateInputArtifact::class)->validReviewCrop($run),
        };
        abort_unless($valid, 409, UiText::t('La photo source ne peut plus être vérifiée. Relancez une analyse avec une photo exploitable.'));
        $config = match ($family) {
            'color' => 'intelligence.vehicle_color_v8.disk', 'damage' => 'intelligence.vehicle_damage_v1.disk', 'plate' => 'intelligence.vehicle_plate_hybrid_review.disk',
        };
        $path = $family === 'plate' ? app(VehiclePlateInputArtifact::class)->reviewCropStoredPath($run) : $run->input_stored_path;
        $expected = $family === 'plate' && $run->usesDetector() ? $run->crop_sha256 : $run->input_sha256;
        $bytes = IntelligencePrivateStorage::disk($config)->get($path);
        abort_unless(is_string($bytes) && strlen($bytes) <= 8388608 && hash_equals((string) $expected, hash('sha256', $bytes)), 409);
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 20000000 && in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true), 409);

        return ['bytes' => $bytes, 'sha256' => $expected, 'width' => $size[0], 'height' => $size[1], 'mime' => $size['mime']];
    }

    public function predictedBoxes(Model $run): array
    {
        if (! $run->input_width || ! $run->input_height || $run->quality_status !== 'usable') {
            return [];
        }

        return collect($run->candidate_regions ?? [])->map(fn ($box) => ['x' => $box['x'] / $run->input_width, 'y' => $box['y'] / $run->input_height, 'w' => $box['width'] / $run->input_width, 'h' => $box['height'] / $run->input_height])->all();
    }

    public function annotate(User $user, string $family, string $key, array $data): VisionAnnotation
    {
        $run = $this->run($user, $family, $key, 'review');
        $rules = ['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'previous_revision' => ['required', 'integer', 'min:0'], 'validated' => ['accepted']];
        if ($family === 'damage') {
            $rules += TrainingDatasetSchema::boxRules('boxes');
            $rules['complete_image_review'] = ['accepted'];
        } else {
            $rules['label'] = ['required', 'string', $family === 'color' ? Rule::in(VehicleColorContract::CLASSES) : 'max:30'];
        }
        $data = Validator::make($data, $rules)->validate();
        if ($family === 'plate' && ! VehiclePlateHybridContract::isCanonical($data['label'])) {
            throw ValidationException::withMessages(['label' => UiText::t('Saisissez une immatriculation canonique vérifiée, par exemple 12345|أ|6.')]);
        }
        if ($family === 'damage') {
            TrainingDatasetSchema::checkBoxes($data['boxes']);
        }

        return DB::transaction(function () use ($user, $run, $family, $data) {
            $locked = $run::query()->whereKey($run)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('review', $locked);
            abort_unless($locked->status->value === 'succeeded', 409, UiText::t('L’analyse doit être terminée avant la correction.'));
            $latest = VisionAnnotation::where($family.'_run_id', $run->id)->latest('revision')->first();
            abort_unless(($latest?->revision ?? 0) === (int) $data['previous_revision'], 409, UiText::t('Une correction plus récente existe. Rechargez la page avant de valider.'));
            $image = $this->image($family, $locked);
            $abstained = match ($family) {
                'color' => ! $locked->model_accepted,
                'damage' => $locked->quality_status === 'abstained',
                'plate' => ! in_array($locked->suggestion_status, ['complete_primary_suggestion', 'complete_segmented_suggestion'], true),
            };
            $counts = $family === 'damage' && ! $abstained ? app(TrainingEvaluation::class)->matchBoxes($data['boxes'], $this->predictedBoxes($locked)) : [null, null, null];
            $matches = match ($family) {
                'color' => $locked->suggested_color === null ? null : $data['label'] === $locked->suggested_color,
                'plate' => $locked->suggested_canonical === null ? null : $data['label'] === $locked->suggested_canonical,
                'damage' => $abstained ? null : $counts[1] === 0 && $counts[2] === 0,
            };
            $annotation = VisionAnnotation::create([
                'agency_id' => $locked->agency_id, 'family' => $family, $family.'_run_id' => $locked->id,
                'revision' => ($latest?->revision ?? 0) + 1,
                'truth' => $family === 'damage' ? ['boxes' => $data['boxes']] : ['label' => $data['label']],
                'source_sha256' => $image['sha256'], 'image_width' => $image['width'], 'image_height' => $image['height'],
                'model_version' => $family === 'plate' ? $locked->model_name.'::'.($locked->fallback_version ?? $locked->result_schema_version) : $locked->model_version,
                'was_abstained' => $abstained, 'matches_prediction' => $matches,
                'box_tp' => $counts[0], 'box_fp' => $counts[1], 'box_fn' => $counts[2], 'validated_by' => $user->id, 'created_at' => now(),
            ]);
            if ($latest) {
                // Superseded labels cannot remain eligible in previously shared training contributions.
                $datasets = ModelTrainingDataset::whereIn('id', DB::table('training_annotation_export_rows')->where('tenant_id', $user->tenant_id)->where('annotation_id', $latest->id)->select('dataset_id'))->whereNull('revoked_at')->lockForUpdate()->get();
                foreach ($datasets as $dataset) {
                    $dataset->forceFill(['revoked_at' => now()])->save();
                    app(AuditRecorder::class)->record('training.dataset.annotation_superseded', $dataset);
                }
            }
            app(AuditRecorder::class)->record('prediction.annotation.validated', $annotation, [], ['family' => $family, 'revision' => $annotation->revision, 'matches_prediction' => $matches]);

            return $annotation;
        });
    }
}
