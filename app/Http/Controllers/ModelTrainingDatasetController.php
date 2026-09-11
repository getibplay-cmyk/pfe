<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\CreateDemandHistoryExport;
use App\Models\Agency;
use App\Models\ModelTrainingDataset;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\Training\TrainingCatalog;
use App\Support\Intelligence\Training\TrainingDatasetSchema;
use App\Support\Intelligence\Training\TrainingWorkbench;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ModelTrainingDatasetController extends Controller
{
    public function index(Request $request)
    {
        TrainingWorkbench::owner($request->user());

        return view('intelligence.training.datasets', [
            'datasets' => ModelTrainingDataset::query()->latest('id')->paginate(15),
            'agencies' => Agency::query()->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name']),
            'catalog' => TrainingCatalog::all(),
        ]);
    }

    public function store(Request $request, TrainingWorkbench $workbench, TrainingDatasetSchema $schema)
    {
        TrainingWorkbench::owner($request->user());
        $data = $request->validate([
            'tenant_id' => ['prohibited'], 'name' => ['required', 'string', 'max:100'],
            'source_note' => ['required', 'string', 'max:300'],
            'rights_confirmed' => ['accepted'], 'labels_confirmed' => ['accepted'],
            'shared' => ['sometimes', 'boolean'], 'mode' => ['required', 'in:upload,demand'],
            'dataset' => ['required_if:mode,upload', 'prohibited_if:mode,demand', 'file', 'extensions:json', 'mimetypes:application/json,text/plain', 'max:5120'],
            'agency_id' => ['required_if:mode,demand', 'prohibited_if:mode,upload', 'integer'],
            'date_from' => ['required_if:mode,demand', 'prohibited_if:mode,upload', 'date_format:Y-m-d', 'before:today'],
            'date_to' => ['required_if:mode,demand', 'prohibited_if:mode,upload', 'date_format:Y-m-d', 'before:today', 'after:date_from'],
        ]);
        if ($data['mode'] === 'demand') {
            $days = CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to'])) + 1;
            if ($days < 120 || $days > 731) {
                throw ValidationException::withMessages(['date_from' => 'Choisissez entre 120 et 731 jours consécutifs terminés.']);
            }
            Agency::query()->whereKey($data['agency_id'])->where('is_active', true)->firstOrFail();
            $run = app(CreateDemandHistoryExport::class)->handle((int) $data['agency_id'], $data['date_from'], $data['date_to'], $request->user());
            $input = $workbench->demandData($request->user(), $run);
        } else {
            $input = $schema->decode($request->file('dataset')->get());
        }
        $workbench->stage($request->user(), $input, $data);

        return back()->with('status', 'Jeu de données figé. Il est disponible pour vos prochaines campagnes selon le partage choisi.');
    }

    public function download(Request $request, ModelTrainingDataset $dataset, TrainingWorkbench $workbench, AuditRecorder $audit)
    {
        TrainingWorkbench::owner($request->user());
        abort_unless($dataset->tenant_id === $request->user()->tenant_id, 404);
        abort_if($dataset->revoked_at !== null, 410, 'Ce jeu de données a été révoqué.');
        $data = $workbench->read($dataset->stored_path, $dataset->sha256);
        $audit->record('training.dataset.downloaded', $dataset);

        return response()->json($data)->withHeaders([
            'Content-Disposition' => 'attachment; filename="dataset-'.$dataset->public_id.'.json"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function revoke(Request $request, ModelTrainingDataset $dataset, TrainingWorkbench $workbench)
    {
        $workbench->revoke($request->user(), $dataset);

        return back()->with('status', 'Contribution révoquée. Les nouveaux téléchargements et validations de ses campagnes sont bloqués.');
    }

    public function share(Request $request, ModelTrainingDataset $dataset, TrainingWorkbench $workbench)
    {
        TrainingWorkbench::owner($request->user());
        $request->validate(['share_confirmed' => ['accepted'], 'tenant_id' => ['prohibited']]);
        $workbench->share($request->user(), $dataset);

        return back()->with('status', 'Le partage pour les campagnes est autorisé. Vous pouvez le révoquer depuis cette page.');
    }

    public function template(Request $request, string $family)
    {
        TrainingWorkbench::owner($request->user());
        TrainingCatalog::get($family);
        $sample = ['key' => 'observation_001', 'group' => 'groupe_001'];
        $sample += match ($family) {
            'demand' => ['date' => '2026-01-01', 'value' => 3],
            'anomaly' => ['date' => '2026-01-01', 'late_hours' => 2, 'km_per_day' => 100, 'fuel_drop_pct' => 10, 'label' => 0],
            'color' => ['image_sha256' => str_repeat('0', 64), 'label' => 'gray'],
            'plate' => ['image_sha256' => str_repeat('0', 64), 'label' => '12345|أ|6'],
            'damage' => ['image_sha256' => str_repeat('0', 64), 'boxes' => [['x' => .2, 'y' => .3, 'w' => .2, 'h' => .1]]],
        };

        return response()->json(['schema_version' => '1.0', 'family' => $family, 'rows' => [$sample]])
            ->withHeaders(['Content-Disposition' => 'attachment; filename="format-'.$family.'.json"', 'Cache-Control' => 'no-store, private']);
    }
}
