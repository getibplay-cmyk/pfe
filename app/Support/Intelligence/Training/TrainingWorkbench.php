<?php

namespace App\Support\Intelligence\Training;

use App\Models\ModelTrainingCampaign;
use App\Models\ModelTrainingDataset;
use App\Models\ModelTrainingResult;
use App\Models\ModelTrainingReview;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class TrainingWorkbench
{
    public function __construct(private TrainingDatasetSchema $schema, private AuditRecorder $audit) {}

    public static function owner(User $user): void
    {
        Gate::forUser($user)->authorize('create', ModelTrainingDataset::class);
    }

    public static function platform(User $user): void
    {
        Gate::forUser($user)->authorize('create', ModelTrainingCampaign::class);
    }

    public static function shared(): Builder
    {
        return DB::table('model_training_datasets as d')
            ->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->where('d.shared', true)->whereNull('d.revoked_at')
            ->where('t.status', 'active')->whereNull('t.deleted_at');
    }

    public function stage(User $user, array $data, array $attributes): ModelTrainingDataset
    {
        self::owner($user);
        $normalized = $this->schema->normalize($data, $user->tenant_id);
        $id = (string) Str::uuid();
        $path = 'intelligence/training/datasets/'.$id.'.json';
        $encoded = $this->encode($normalized);
        $disk = IntelligencePrivateStorage::disk('model_training.disk');
        $disk->put($path, $encoded);
        try {
            return DB::transaction(function () use ($user, $attributes, $normalized, $id, $path, $encoded): ModelTrainingDataset {
                $dataset = ModelTrainingDataset::create([
                    'public_id' => $id, 'name' => $attributes['name'], 'source_note' => $attributes['source_note'],
                    'family' => $normalized['family'], 'stored_path' => $path,
                    'sha256' => hash('sha256', $encoded), 'row_count' => count($normalized['rows']),
                    'shared' => (bool) ($attributes['shared'] ?? false), 'created_by' => $user->id,
                    'shared_at' => ! empty($attributes['shared']) ? now() : null, 'shared_by' => ! empty($attributes['shared']) ? $user->id : null,
                ]);
                $this->audit->record('training.dataset.created', $dataset, [], [
                    'family' => $dataset->family, 'row_count' => $dataset->row_count,
                    'shared' => $dataset->shared, 'rights_and_labels_confirmed' => true,
                ]);

                return $dataset;
            });
        } catch (Throwable $e) {
            IntelligencePrivateStorage::deleteAfterFailure('model_training.disk', $path);
            throw $e;
        }
    }

    public function revoke(User $user, ModelTrainingDataset $dataset): void
    {
        Gate::forUser($user)->authorize('revoke', $dataset);
        DB::transaction(function () use ($dataset): void {
            $locked = ModelTrainingDataset::query()->whereKey($dataset->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                return;
            }
            $locked->update(['revoked_at' => now()]);
            $this->audit->record('training.dataset.revoked', $locked);
        }, 3);
    }

    public function share(User $user, ModelTrainingDataset $dataset): ModelTrainingDataset
    {
        Gate::forUser($user)->authorize('share', $dataset);

        return DB::transaction(function () use ($user, $dataset): ModelTrainingDataset {
            $locked = ModelTrainingDataset::query()->whereKey($dataset->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->revoked_at !== null || $locked->shared, 409, 'Ce jeu ne peut plus être proposé au partage.');
            $this->read($locked->stored_path, $locked->sha256);
            $locked->update(['shared' => true, 'shared_at' => now(), 'shared_by' => $user->id]);
            $this->audit->record('training.dataset.share_authorized', $locked, [], ['shared' => true]);

            return $locked;
        }, 3);
    }

    public function createCampaign(User $user, string $name, array $ids): ModelTrainingCampaign
    {
        self::platform($user);
        $path = 'intelligence/training/campaigns/'.Str::uuid().'.json';
        try {
            return DB::transaction(function () use ($user, $name, $ids, $path): ModelTrainingCampaign {
                $datasets = self::shared()->whereIn('d.public_id', $ids)->orderBy('d.id')->lockForUpdate()->get('d.*');
                if ($datasets->count() !== count($ids)) {
                    TrainingDatasetSchema::fail('Une contribution n’est plus partagée ou son entreprise est inactive.');
                }
                $family = $datasets->first()->family;
                $partition = $this->schema->partition($datasets->map(fn ($d) => $this->read($d->stored_path, $d->sha256))->all(), $family);
                $id = (string) Str::uuid();
                $manifest = [
                    'schema_version' => '1.0', 'campaign_id' => $id, 'family' => $family,
                    'baseline_version' => TrainingCatalog::get($family)['baseline'],
                    'seed' => 20260911, 'split_policy' => $family === 'demand' ? 'global_chronological_60_20_20' : 'stable_group_60_20_20',
                    'contributions' => $datasets->map(fn ($d) => ['id' => $d->public_id, 'sha256' => $d->sha256])->all(),
                    'rows' => $partition['rows'], 'counts' => $partition['counts'],
                    'operational_effect' => 'NO_OPERATIONAL_ACTION',
                ];
                $encoded = $this->encode($manifest);
                abort_if(strlen($encoded) > 20 * 1024 * 1024, 422, 'Réduisez le nombre de contributions.');
                IntelligencePrivateStorage::disk('model_training.disk')->put($path, $encoded);
                $campaign = ModelTrainingCampaign::create([
                    'public_id' => $id, 'name' => $name, 'family' => $family,
                    'baseline_version' => $manifest['baseline_version'], 'stored_path' => $path,
                    'sha256' => hash('sha256', $encoded), 'summary' => $partition['counts'], 'created_by' => $user->id,
                ]);
                foreach ($datasets as $dataset) {
                    DB::table('model_training_contributions')->insert(['campaign_id' => $campaign->id, 'tenant_id' => $dataset->tenant_id, 'dataset_id' => $dataset->id]);
                }
                $this->audit->record('platform.training.campaign.prepared', $campaign, [], ['family' => $family, 'contribution_count' => count($ids)]);

                return $campaign;
            }, 3);
        } catch (Throwable $e) {
            IntelligencePrivateStorage::deleteAfterFailure('model_training.disk', $path);
            throw $e;
        }
    }

    public function assertContributions(ModelTrainingCampaign $campaign, bool $lock = false): void
    {
        $ids = DB::table('model_training_contributions')->where('campaign_id', $campaign->id)->pluck('dataset_id');
        $query = self::shared()->whereIn('d.id', $ids)->orderBy('d.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        abort_unless($ids->isNotEmpty() && $query->get('d.id')->count() === $ids->count(), 409, 'Une contribution a été révoquée ou suspendue. Préparez une nouvelle campagne.');
    }

    public function campaignData(User $user, ModelTrainingCampaign $campaign): array
    {
        self::platform($user);
        $this->assertContributions($campaign);

        return $this->read($campaign->stored_path, $campaign->sha256);
    }

    public function read(string $path, string $hash): array
    {
        $resolved = IntelligencePrivateStorage::path('model_training.disk', $path);
        abort_if(filesize($resolved) > 20 * 1024 * 1024, 409);
        $contents = file_get_contents($resolved);
        abort_unless(hash_equals($hash, hash('sha256', $contents)), 409, 'Le contrôle d’intégrité a échoué.');

        return json_decode($contents, true, 24, JSON_THROW_ON_ERROR);
    }

    public function importResult(User $user, ModelTrainingCampaign $campaign, array $report): ModelTrainingResult
    {
        self::platform($user);

        $path = 'intelligence/training/reports/'.Str::uuid().'.json';
        try {
            return DB::transaction(function () use ($user, $campaign, $report, $path): ModelTrainingResult {
                $locked = ModelTrainingCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $this->assertContributions($locked, true);
                abort_if($locked->result()->exists(), 409, 'Cette tentative a déjà un résultat. Créez une nouvelle tentative.');
                $manifest = $this->read($locked->stored_path, $locked->sha256);
                $evaluation = app(TrainingEvaluation::class)->evaluate($manifest, $locked->sha256, $report);
                $encoded = $this->encode($report);
                IntelligencePrivateStorage::disk('model_training.disk')->put($path, $encoded);
                $result = ModelTrainingResult::create([
                    'campaign_id' => $locked->id, 'candidate_version' => $report['candidate_version'],
                    'artifact_sha256' => $report['artifact_sha256'], 'report_sha256' => hash('sha256', $encoded), 'stored_path' => $path,
                    'metrics' => $evaluation['metrics'], 'eligible' => $evaluation['eligible'], 'created_by' => $user->id,
                ]);
                $this->audit->record('platform.training.result.imported', $result, [], ['eligible' => $result->eligible, 'candidate_version' => $result->candidate_version]);

                return $result;
            }, 3);
        } catch (Throwable $e) {
            IntelligencePrivateStorage::deleteAfterFailure('model_training.disk', $path);
            throw $e;
        }
    }

    public function review(User $user, ModelTrainingCampaign $campaign, string $decision, string $note): void
    {
        self::platform($user);
        abort_unless(in_array($decision, ['qualified', 'rejected'], true), 422);
        DB::transaction(function () use ($user, $campaign, $decision, $note): void {
            $locked = ModelTrainingCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $result = $locked->result()->firstOrFail();
            abort_if($result->review()->exists(), 409, 'La décision est déjà enregistrée.');
            if ($decision === 'qualified') {
                $this->assertContributions($locked, true);
                abort_unless($locked->baseline_version === TrainingCatalog::get($locked->family)['baseline'], 409, 'La référence a changé. Préparez une nouvelle comparaison.');
                abort_unless($result->eligible, 422, 'Le candidat ne franchit pas les critères de comparaison.');
            }
            $review = ModelTrainingReview::create(['result_id' => $result->id, 'decision' => $decision, 'note' => $note, 'created_by' => $user->id]);
            $this->audit->record('platform.training.candidate.reviewed', $review, [], ['decision' => $decision, 'operational_effect' => 'NO_OPERATIONAL_ACTION']);
        }, 3);
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
