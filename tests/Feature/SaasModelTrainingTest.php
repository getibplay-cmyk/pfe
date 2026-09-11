<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\ModelTrainingCampaign;
use App\Models\ModelTrainingDataset;
use App\Models\ModelTrainingResult;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\Training\TrainingWorkbench;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaasModelTrainingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        Storage::fake(IntelligencePrivateStorage::DISK);
        config(['intelligence.export_hmac_key' => str_repeat('training-test-only-', 4)]);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_owner_can_prepare_private_dataset_and_groups_are_pseudonymized(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->post(route('model-training.store'), $this->upload())->assertRedirect()->assertSessionHasNoErrors();
        $dataset = ModelTrainingDataset::withoutGlobalScopes()->sole();
        $this->assertFalse($dataset->shared);
        $data = app(TrainingWorkbench::class)->read($dataset->stored_path, $dataset->sha256);
        $this->assertSame(240, count($data['rows']));
        $this->assertNotSame('agency_1', $data['rows'][0]['group']);
        $this->get(route('model-training.index'))->assertOk()->assertSee('Données d’apprentissage');
        $this->get(route('model-training.download', $dataset))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_upload_requires_rights_and_labels_and_rejects_tenant_injection(): void
    {
        $f = $this->fixture();
        $data = $this->upload();
        unset($data['rights_confirmed']);
        $data['tenant_id'] = $f['tenant']->id;
        $this->actingAs($f['user'])->post(route('model-training.store'), $data)->assertSessionHasErrors(['rights_confirmed', 'tenant_id']);
        $this->assertDatabaseCount('model_training_datasets', 0);
    }

    public function test_private_dataset_requires_explicit_consent_to_be_shared_later(): void
    {
        $f = $this->fixture();
        $dataset = $this->stage($f, false);
        $this->actingAs($f['user'])->post(route('model-training.share', $dataset))->assertSessionHasErrors('share_confirmed');
        $this->post(route('model-training.share', $dataset), ['share_confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $shared = ModelTrainingDataset::withoutGlobalScopes()->findOrFail($dataset->id);
        $this->assertTrue($shared->shared);
        $this->assertSame($f['user']->id, $shared->shared_by);
        $this->assertSame($dataset->sha256, $shared->sha256);
        $this->assertDatabaseCount('model_training_datasets', 1);
        $this->post(route('model-training.revoke', $dataset))->assertRedirect();
        $this->post(route('model-training.share', $dataset), ['share_confirmed' => 1])->assertConflict();
    }

    public function test_saas_demand_export_is_scoped_and_has_distinct_keys_per_agency(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $input = ['mode' => 'demand', 'name' => 'Départs', 'source_note' => 'Historique des événements', 'rights_confirmed' => 1, 'labels_confirmed' => 1, 'date_from' => '2025-01-01', 'date_to' => '2025-08-28', 'agency_id' => $other['agency']->id];
        $this->actingAs($f['user'])->post(route('model-training.store'), $input)->assertNotFound();
        $input['agency_id'] = $f['agency']->id;
        $this->post(route('model-training.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $dataset = ModelTrainingDataset::withoutGlobalScopes()->sole();
        $this->assertSame(240, $dataset->row_count);
        $this->assertSame($f['tenant']->id, $dataset->tenant_id);
        $first = app(TrainingWorkbench::class)->read($dataset->stored_path, $dataset->sha256);
        $this->assertSame(0, array_sum(array_column($first['rows'], 'value')));
        $secondAgency = app(TenantContext::class)->run($f['tenant'], fn () => Agency::factory()->create());
        $input['agency_id'] = $secondAgency->id;
        $this->post(route('model-training.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $second = ModelTrainingDataset::withoutGlobalScopes()->latest('id')->firstOrFail();
        $secondData = app(TrainingWorkbench::class)->read($second->stored_path, $second->sha256);
        $this->assertNotSame($first['rows'][0]['group'], $secondData['rows'][0]['group']);
        $this->assertEmpty(array_intersect(array_column($first['rows'], 'key'), array_column($secondData['rows'], 'key')));
    }

    public function test_unexpected_sensitive_columns_and_binary_files_are_rejected(): void
    {
        $f = $this->fixture();
        $data = $this->data();
        $data['rows'][0]['customer_name'] = 'Private Name';
        $this->actingAs($f['user'])->post(route('model-training.store'), $this->upload($data))->assertSessionHasErrors();
        $upload = $this->upload();
        $upload['dataset'] = UploadedFile::fake()->createWithContent('model.joblib', 'binary');
        $this->post(route('model-training.store'), $upload)->assertSessionHasErrors('dataset');
        $this->assertDatabaseCount('model_training_datasets', 0);
    }

    public function test_agency_manager_and_platform_admin_cannot_use_tenant_workbench(): void
    {
        $f = $this->fixture('agency-manager');
        $this->actingAs($f['user'])->get(route('model-training.index'))->assertForbidden();
        $this->post(route('model-training.store'), $this->upload())->assertForbidden();
        $this->actingAs($this->admin())->get(route('model-training.index'))->assertForbidden();
    }

    public function test_cross_tenant_download_and_revocation_are_not_found(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $dataset = $this->stage($a);
        $this->actingAs($b['user'])->get(route('model-training.download', $dataset))->assertNotFound();
        $this->post(route('model-training.revoke', $dataset))->assertNotFound();
        $this->assertNull($dataset->fresh()->revoked_at);
    }

    public function test_platform_only_sees_explicit_shares_from_active_tenants(): void
    {
        $a = $this->fixture();
        $private = $this->stage($a, false);
        $shared = $this->stage($a, true);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('platform.training.index'))->assertOk()->assertSee($shared->public_id)->assertDontSee($private->public_id);
        $this->post(route('platform.training.store'), ['name' => 'Interdit', 'datasets' => [$private->public_id]])->assertSessionHasErrors('dataset');
        DB::table('tenants')->where('id', $a['tenant']->id)->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->post(route('platform.training.store'), ['name' => 'Interdit', 'datasets' => [$shared->public_id]])->assertSessionHasErrors('dataset');
        $this->assertDatabaseCount('model_training_campaigns', 0);
    }

    public function test_pooling_keeps_a_global_chronological_test_and_deduplicates_snapshots(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $one = $this->stage($a);
        $duplicate = $this->stage($a);
        $two = $this->stage($b);
        $admin = $this->admin();
        $campaign = app(TrainingWorkbench::class)->createCampaign($admin, 'Regroupement', [$one->public_id, $duplicate->public_id, $two->public_id]);
        $manifest = app(TrainingWorkbench::class)->campaignData($admin, $campaign);
        $this->assertCount(480, $manifest['rows']);
        $rows = collect($manifest['rows']);
        $this->assertLessThan($rows->where('split', 'test')->min('date'), $rows->where('split', 'train')->max('date'));
        $this->assertSame(2, $rows->pluck('group')->unique()->count());
        $response = $this->actingAs($admin)->get(route('platform.training.download', $campaign))->assertOk();
        $this->assertSame($response->json('manifest_sha256'), hash('sha256', $response->json('manifest_json')));
        $this->get(route('platform.training.show', $campaign))->assertOk()->assertSee('Ouvrir Colab');
        $this->actingAs($a['user'])->get(route('platform.training.show', $campaign))->assertForbidden();
    }

    public function test_incomplete_and_conflicting_time_series_are_rejected(): void
    {
        $f = $this->fixture();
        $data = $this->data();
        array_splice($data['rows'], 70, 1);
        $dataset = $this->stage($f, true, $data);
        $this->expectException(ValidationException::class);
        app(TrainingWorkbench::class)->createCampaign($this->admin(), 'Dates manquantes', [$dataset->public_id]);
    }

    public function test_revocation_blocks_download_import_approval_and_retry_and_is_idempotent(): void
    {
        [$f, $dataset, $admin, $campaign] = $this->campaign();
        $report = $this->report($admin, $campaign);
        app(TrainingWorkbench::class)->importResult($admin, $campaign, $report);
        $this->actingAs($f['user'])->post(route('model-training.revoke', $dataset))->assertRedirect();
        $this->post(route('model-training.revoke', $dataset))->assertRedirect();
        $this->get(route('model-training.download', $dataset))->assertGone();
        $this->actingAs($admin)->get(route('platform.training.download', $campaign))->assertConflict();
        $this->post(route('platform.training.retry', $campaign))->assertConflict();
        $this->post(route('platform.training.review', $campaign), ['decision' => 'qualified', 'note' => 'Tentative de validation'])->assertConflict();
        $this->post(route('platform.training.review', $campaign), ['decision' => 'rejected', 'note' => 'Partage révoqué par la source'])->assertRedirect();
    }

    public function test_server_recomputes_metrics_and_keeps_runtime_unchanged(): void
    {
        [, , $admin, $campaign] = $this->campaign();
        $report = $this->report($admin, $campaign);
        $runtime = config('intelligence.demand_forecasting');
        $this->actingAs($admin)->post(route('platform.training.result', $campaign), ['report' => UploadedFile::fake()->createWithContent('report.json', json_encode($report)), 'protocol_confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $result = ModelTrainingResult::sole();
        $this->assertEqualsWithDelta(1, $result->metrics['baseline'], .000001);
        $this->assertEquals(0, $result->metrics['candidate']);
        $this->assertTrue($result->eligible);
        $this->post(route('platform.training.review', $campaign), ['decision' => 'qualified', 'note' => 'Gain vérifié ; qualification technique à mener'])->assertRedirect();
        $this->assertSame($runtime, config('intelligence.demand_forecasting'));
        $this->get(route('platform.training.show', $campaign))->assertOk()->assertSee('Retenu pour qualification technique');
        $this->get(route('platform.training.report', $campaign))->assertOk()->assertJsonPath('candidate_version', 'test-candidate-v2');
        $this->post(route('platform.training.review', $campaign), ['decision' => 'rejected', 'note' => 'Écraser la décision existante'])->assertConflict();
    }

    public function test_report_for_another_snapshot_cannot_be_imported(): void
    {
        [, , $admin, $campaign] = $this->campaign();
        $report = $this->report($admin, $campaign);
        $report['manifest_sha256'] = str_repeat('0', 64);
        $this->expectException(ValidationException::class);
        app(TrainingWorkbench::class)->importResult($admin, $campaign, $report);
    }

    public function test_report_cannot_omit_hard_examples_or_supply_a_claimed_pass_flag(): void
    {
        [, , $admin, $campaign] = $this->campaign();
        $report = $this->report($admin, $campaign);
        array_pop($report['predictions']);
        $this->expectException(ValidationException::class);
        app(TrainingWorkbench::class)->importResult($admin, $campaign, $report);
    }

    public function test_regressing_candidate_cannot_be_qualified(): void
    {
        [, , $admin, $campaign] = $this->campaign();
        $report = $this->report($admin, $campaign);
        foreach ($report['predictions'] as &$row) {
            $row['candidate'] = array_fill(0, 7, 8);
        }
        $result = app(TrainingWorkbench::class)->importResult($admin, $campaign, $report);
        $this->assertFalse($result->eligible);
        $this->actingAs($admin)->post(route('platform.training.review', $campaign), ['decision' => 'qualified', 'note' => 'Une régression doit être bloquée'])->assertUnprocessable();
    }

    public function test_retry_preserves_previous_campaign_and_test_partition(): void
    {
        [, , $admin, $campaign] = $this->campaign();
        $this->actingAs($admin)->post(route('platform.training.retry', $campaign))->assertRedirect();
        $next = ModelTrainingCampaign::latest('id')->first();
        $this->assertNotSame($next->public_id, $campaign->public_id);
        $this->assertEquals($next->summary, $campaign->summary);
        $this->assertDatabaseCount('model_training_campaigns', 2);
    }

    public function test_changed_private_manifest_fails_integrity_check(): void
    {
        [, , $admin, $campaign] = $this->campaign();
        Storage::disk(IntelligencePrivateStorage::DISK)->put($campaign->stored_path, '{}');
        $this->actingAs($admin)->get(route('platform.training.download', $campaign))->assertConflict();
    }

    public function test_database_prevents_dataset_mutation(): void
    {
        $f = $this->fixture();
        $dataset = $this->stage($f);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('model_training_datasets')->where('id', $dataset->id)->update(['name' => 'Écrasement']));
    }

    public function test_database_refuses_cross_tenant_contributions(): void
    {
        [, $dataset, , $campaign] = $this->campaign();
        $other = $this->fixture();
        $otherDataset = $this->stage($other);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('model_training_contributions')->insert(['campaign_id' => $campaign->id, 'tenant_id' => $dataset->tenant_id, 'dataset_id' => $otherDataset->id]));
    }

    private function data(): array
    {
        $start = CarbonImmutable::parse('2025-01-01');

        return ['schema_version' => '1.0', 'family' => 'demand', 'rows' => array_map(fn ($i) => ['key' => 'row_'.$i, 'group' => 'agency_1', 'date' => $start->addDays($i)->toDateString(), 'value' => 2], range(0, 239))];
    }

    private function upload(?array $data = null): array
    {
        return ['mode' => 'upload', 'name' => 'Historique vérifié', 'source_note' => 'Données fictives de test', 'rights_confirmed' => 1, 'labels_confirmed' => 1, 'dataset' => UploadedFile::fake()->createWithContent('dataset.json', json_encode($data ?? $this->data()))];
    }

    private function fixture(string $role = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $role === 'tenant-owner' ? null : $agency->id, 'role_id' => Role::where('slug', $role)->value('id'), 'must_change_password' => false]);

        return compact('tenant', 'agency', 'user');
    }

    private function admin(): User
    {
        return User::factory()->create(['tenant_id' => null, 'agency_id' => null, 'role_id' => null, 'is_platform_admin' => true, 'is_active' => true, 'must_change_password' => false]);
    }

    private function stage(array $f, bool $shared = true, ?array $data = null): ModelTrainingDataset
    {
        return app(TenantContext::class)->run($f['tenant'], fn () => app(TrainingWorkbench::class)->stage($f['user'], $data ?? $this->data(), ['name' => 'Contribution '.str()->random(8), 'source_note' => 'Fixture vérifiée', 'shared' => $shared]));
    }

    private function campaign(): array
    {
        $f = $this->fixture();
        $dataset = $this->stage($f);
        $admin = $this->admin();
        $campaign = app(TrainingWorkbench::class)->createCampaign($admin, 'Test réentraînement', [$dataset->public_id]);

        return [$f, $dataset, $admin, $campaign];
    }

    private function report(User $admin, ModelTrainingCampaign $campaign): array
    {
        $manifest = app(TrainingWorkbench::class)->campaignData($admin, $campaign);

        return ['schema_version' => '1.0', 'campaign_id' => $campaign->public_id, 'manifest_sha256' => $campaign->sha256, 'baseline_version' => $campaign->baseline_version, 'candidate_version' => 'test-candidate-v2', 'artifact_sha256' => hash('sha256', 'test artifact'), 'predictions' => collect($manifest['rows'])->where('split', 'test')->map(fn ($row) => ['key' => $row['key'], 'baseline' => array_fill(0, 7, 3), 'candidate' => array_fill(0, 7, 2)])->values()->all()];
    }
}
