<?php

namespace App\Support\Intelligence\Training;

use App\Models\Agency;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\UiText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ModelQualityReport
{
    public function build(User $user, int $days, ?int $agency): array
    {
        abort_unless(! $user->is_platform_admin && $user->tenant_id && $user->hasPermission('prediction.view'), 403);
        abort_unless($user->tenant_id === app(TenantContext::class)->tenantId(), 403);
        abort_if($user->agency_id !== null && $agency !== $user->agency_id, 403);
        $end = CarbonImmutable::now();
        $start = $end->subDays($days);
        $current = $this->period($user, $start, $end, $agency);
        $previous = collect($this->period($user, $start->subDays($days), $start, $agency))->keyBy('key');
        $agencies = Agency::whereIn('id', collect($current)->pluck('agency_id'))->pluck('name', 'id');
        foreach ($current as &$row) {
            $row['agency'] = $agencies[$row['agency_id']] ?? UiText::t('Agence');
            $row['label'] = UiText::t(TrainingCatalog::get($row['family'])['label']);
            $row['review_rate'] = $row['completed'] ? $row['reviewed'] / $row['completed'] : null;
            $row['correction_rate'] = $row['comparable'] ? $row['corrected'] / $row['comparable'] : null;
            $row['abstention_rate'] = $row['completed'] ? $row['abstained'] / $row['completed'] : null;
            $denominator = 2 * $row['box_tp'] + $row['box_fp'] + $row['box_fn'];
            $row['box_f1'] = $denominator ? 2 * $row['box_tp'] / $denominator : null;
            $old = $previous[$row['key']] ?? null;
            $row['change'] = $old && $old['comparable'] >= 20 && $row['comparable'] >= 20 ? $row['correction_rate'] - ($old['corrected'] / $old['comparable']) : null;
            $row['review_needed'] = $row['comparable'] >= 20 && ($row['correction_rate'] > .2 || ($row['change'] ?? 0) > .1);
        }
        unset($row);

        return ['rows' => $current, 'start' => $start, 'end' => $end];
    }

    private function period(User $user, CarbonImmutable $start, CarbonImmutable $end, ?int $agency): array
    {
        $rows = [];
        foreach (VisionAnnotationWorkbench::MODELS as $family => $class) {
            $table = (new $class)->getTable();
            $runColumn = $family.'_run_id';
            $latest = DB::table('vision_annotations')->where('tenant_id', $user->tenant_id)->where('family', $family)->selectRaw('MAX(id) AS id')->groupBy($runColumn);
            $annotations = DB::table('vision_annotations')->whereIn('id', $latest)->where('tenant_id', $user->tenant_id);
            $version = $family === 'plate' ? "r.model_name || '::' || COALESCE(r.fallback_version, r.result_schema_version, '')" : 'r.model_version';
            $abstained = match ($family) {
                'color' => 'r.model_accepted = false', 'damage' => "r.quality_status = 'abstained'", 'plate' => "r.suggestion_status NOT IN ('complete_primary_suggestion', 'complete_segmented_suggestion')",
            };
            $results = DB::table($table.' as r')->leftJoinSub($annotations, 'a', fn ($join) => $join->on('a.'.$runColumn, '=', 'r.id')->on('a.tenant_id', '=', 'r.tenant_id'))
                ->where('r.tenant_id', $user->tenant_id)->when($agency !== null, fn ($q) => $q->where('r.agency_id', $agency))
                ->where('r.requested_at', '>=', $start)->where('r.requested_at', '<', $end)
                ->selectRaw("r.agency_id, COALESCE({$version}, '—') AS version, COUNT(*) AS runs, COUNT(*) FILTER (WHERE r.status = 'succeeded') AS completed, COUNT(*) FILTER (WHERE r.status = 'failed') AS failed, COUNT(*) FILTER (WHERE r.status = 'succeeded' AND ({$abstained})) AS abstained, COUNT(a.id) AS reviewed, COUNT(a.matches_prediction) AS comparable, COUNT(*) FILTER (WHERE a.matches_prediction = false) AS corrected, COALESCE(SUM(a.box_tp), 0) AS box_tp, COALESCE(SUM(a.box_fp), 0) AS box_fp, COALESCE(SUM(a.box_fn), 0) AS box_fn")
                ->groupBy('r.agency_id')->groupByRaw("COALESCE({$version}, '—')")->orderBy('r.agency_id')->get();
            foreach ($results as $result) {
                $row = ['family' => $family, 'agency_id' => (int) $result->agency_id, 'version' => $result->version, 'key' => $family.'|'.$result->agency_id.'|'.$result->version];
                foreach (['runs', 'completed', 'failed', 'abstained', 'reviewed', 'comparable', 'corrected', 'box_tp', 'box_fp', 'box_fn'] as $key) {
                    $row[$key] = (int) $result->$key;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
