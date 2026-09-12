<?php

namespace App\Http\Controllers;

use App\Models\ModelTrainingDataset;
use App\Models\VisionAnnotation;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\Training\ExportVisionAnnotations;
use App\Support\Intelligence\Training\TrainingWorkbench;
use App\Support\Intelligence\Training\VisionAnnotationWorkbench;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VisionAnnotationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('prediction.view'), 403);
        $data = $request->validate(['tenant_id' => ['prohibited'], 'family' => ['nullable', Rule::in(array_keys(VisionAnnotationWorkbench::MODELS))]]);
        $family = $data['family'] ?? 'color';
        $column = $family.'_run_id';
        $model = VisionAnnotationWorkbench::MODELS[$family];
        $latest = VisionAnnotation::where('family', $family)->selectRaw('MAX(id)')->groupBy($column);
        $annotations = VisionAnnotation::whereIn('id', $latest)->when($request->user()->agency_id, fn ($q, $id) => $q->where('agency_id', $id))->latest('id')->paginate(30)->withQueryString();
        $runs = $model::whereIn('id', $annotations->pluck($column))->get()->keyBy('id');
        $bundles = $request->user()->isTenantOwner() && $request->user()->hasPermission('prediction.export')
            ? DB::table('training_annotation_exports as e')->join('model_training_datasets as d', fn ($join) => $join->on('d.id', '=', 'e.dataset_id')->on('d.tenant_id', '=', 'e.tenant_id'))
                ->where('e.tenant_id', $request->user()->tenant_id)->where('d.family', $family)->latest('e.id')->limit(10)->get(['d.public_id', 'd.name', 'd.revoked_at']) : collect();

        return view('intelligence.annotations.index', compact('family', 'annotations', 'runs', 'column', 'bundles'));
    }

    public function edit(Request $request, string $family, string $run, VisionAnnotationWorkbench $workbench)
    {
        $prediction = $workbench->run($request->user(), $family, $run);
        abort_unless($prediction->status->value === 'succeeded', 409);
        $image = $workbench->image($family, $prediction);
        unset($image['bytes']);
        $history = VisionAnnotation::where($family.'_run_id', $prediction->id)->latest('revision')->limit(10)->get();
        $latest = $history->first();
        $boxes = $latest?->truth['boxes'] ?? ($family === 'damage' ? $workbench->predictedBoxes($prediction) : []);
        $label = $latest?->truth['label'] ?? ($family === 'color' ? $prediction->suggested_color : $prediction->suggested_canonical);

        return view('intelligence.annotations.edit', compact('family', 'prediction', 'image', 'history', 'latest', 'boxes', 'label'));
    }

    public function store(Request $request, string $family, string $run, VisionAnnotationWorkbench $workbench)
    {
        $data = $request->all();
        if ($family === 'damage') {
            $request->validate(['boxes_json' => ['required', 'string', 'max:20000']]);
            try {
                $data['boxes'] = json_decode($data['boxes_json'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['boxes_json' => __('Les cadres ne peuvent pas être lus. Rechargez la page et réessayez.')]);
            }
        }
        $workbench->annotate($request->user(), $family, $run, $data);

        return back()->with('status', __('Annotation validée. Elle est disponible pour la préparation d’un jeu d’apprentissage.'));
    }

    public function image(Request $request, string $family, string $run, VisionAnnotationWorkbench $workbench, AuditRecorder $audit)
    {
        $prediction = $workbench->run($request->user(), $family, $run);
        $image = $workbench->image($family, $prediction);
        $audit->record('prediction.annotation.image_viewed', $prediction);

        return response($image['bytes'], 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, ExportVisionAnnotations $export)
    {
        TrainingWorkbench::owner($request->user());
        $data = $request->validate(['tenant_id' => ['prohibited'], 'family' => ['required', Rule::in(array_keys(VisionAnnotationWorkbench::MODELS))], 'name' => ['required', 'string', 'max:100'], 'annotations' => ['required', 'array', 'list', 'min:1', 'max:50'], 'annotations.*' => ['required', 'integer', 'distinct'], 'rights_confirmed' => ['accepted'], 'labels_confirmed' => ['accepted']]);
        $export->handle($request->user(), $data['family'], $data['annotations'], $data['name']);

        return back()->with('status', __('Jeu privé préparé avec ses images. Vous pouvez télécharger le ZIP et autoriser séparément le partage du jeu.'));
    }

    public function download(Request $request, ModelTrainingDataset $dataset, AuditRecorder $audit)
    {
        Gate::forUser($request->user())->authorize('view', $dataset);
        abort_if($dataset->revoked_at, 410);
        $export = DB::table('training_annotation_exports')->where('tenant_id', $request->user()->tenant_id)->where('dataset_id', $dataset->id)->first();
        abort_unless($export, 404);
        $path = IntelligencePrivateStorage::path('model_training.disk', $export->stored_path);
        abort_unless(hash_equals($export->sha256, hash_file('sha256', $path)), 409);
        $audit->record('training.annotations.downloaded', $dataset);

        return response()->download($path, 'annotations-'.$dataset->public_id.'.zip', ['Cache-Control' => 'no-store, private', 'Content-Type' => 'application/zip']);
    }
}
