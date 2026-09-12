<?php

namespace App\Http\Controllers;

use App\Models\ModelTrainingCampaign;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\Training\TrainingCatalog;
use App\Support\Intelligence\Training\TrainingDatasetSchema;
use App\Support\Intelligence\Training\TrainingWorkbench;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformModelTrainingController extends Controller
{
    public function index(Request $request)
    {
        TrainingWorkbench::platform($request->user());

        return view('platform.training.index', [
            'datasets' => TrainingWorkbench::shared()->orderByDesc('d.id')->paginate(25, ['d.public_id', 'd.name', 'd.family', 'd.row_count', 'd.created_at', 't.name as tenant_name'], 'datasets_page'),
            'campaigns' => ModelTrainingCampaign::with('result.review')->latest('id')->paginate(10),
            'catalog' => TrainingCatalog::all(),
        ]);
    }

    public function store(Request $request, TrainingWorkbench $workbench)
    {
        TrainingWorkbench::platform($request->user());
        $data = $request->validate([
            'tenant_id' => ['prohibited'], 'name' => ['required', 'string', 'max:100'],
            'datasets' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'datasets.*' => ['required', 'uuid', 'distinct:strict'],
        ]);
        $campaign = $workbench->createCampaign($request->user(), $data['name'], $data['datasets']);

        return to_route('platform.training.show', $campaign)->with('status', __('Campagne préparée. Téléchargez le manifeste et ouvrez le notebook Colab.'));
    }

    public function show(Request $request, ModelTrainingCampaign $campaign)
    {
        TrainingWorkbench::platform($request->user());
        $campaign->load('result.review');
        $notebookUrl = (string) config('model_training.notebook_url');
        abort_unless(str_starts_with($notebookUrl, 'https://colab.research.google.com/'), 503, __('Le lien Colab de la plateforme doit être configuré.'));
        $count = DB::table('model_training_contributions')->where('campaign_id', $campaign->id)->count();
        $activeCount = TrainingWorkbench::shared()->join('model_training_contributions as c', 'c.dataset_id', '=', 'd.id')->where('c.campaign_id', $campaign->id)->count();

        return view('platform.training.show', ['campaign' => $campaign, 'definition' => TrainingCatalog::get($campaign->family), 'available' => $count > 0 && $count === $activeCount, 'notebookUrl' => $notebookUrl]);
    }

    public function download(Request $request, ModelTrainingCampaign $campaign, TrainingWorkbench $workbench, AuditRecorder $audit)
    {
        $manifestJson = $workbench->campaignJson($request->user(), $campaign);
        $audit->record('platform.training.campaign.downloaded', $campaign);

        // The envelope binds the exact canonical manifest bytes used by the SaaS.
        return response()->json(['manifest_sha256' => $campaign->sha256, 'manifest_json' => $manifestJson])
            ->withHeaders(['Content-Disposition' => 'attachment; filename="campaign-'.$campaign->public_id.'.json"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function result(Request $request, ModelTrainingCampaign $campaign, TrainingWorkbench $workbench, TrainingDatasetSchema $schema)
    {
        TrainingWorkbench::platform($request->user());
        $request->validate([
            'tenant_id' => ['prohibited'], 'report' => ['required', 'file', 'extensions:json', 'mimetypes:application/json,text/plain', 'max:5120'],
            'protocol_confirmed' => ['accepted'],
        ]);
        $workbench->importResult($request->user(), $campaign, $schema->decode($request->file('report')->get()));

        return back()->with('status', __('Comparaison recalculée sur les prédictions importées. Le candidat reste à examiner.'));
    }

    public function report(Request $request, ModelTrainingCampaign $campaign, TrainingWorkbench $workbench, AuditRecorder $audit)
    {
        TrainingWorkbench::platform($request->user());
        $workbench->assertContributions($campaign);
        $result = $campaign->result()->firstOrFail();
        $data = $workbench->read($result->stored_path, $result->report_sha256);
        $audit->record('platform.training.report.downloaded', $result);

        return response()->json($data)->withHeaders(['Content-Disposition' => 'attachment; filename="report-'.$campaign->public_id.'.json"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function review(Request $request, ModelTrainingCampaign $campaign, TrainingWorkbench $workbench)
    {
        TrainingWorkbench::platform($request->user());
        $data = $request->validate(['decision' => ['required', 'in:qualified,rejected'], 'note' => ['required', 'string', 'min:10', 'max:500']]);
        $workbench->review($request->user(), $campaign, $data['decision'], $data['note']);

        return back()->with('status', __('Décision enregistrée. Un candidat retenu passe ensuite par la qualification technique et le déploiement versionné.'));
    }

    public function retry(Request $request, ModelTrainingCampaign $campaign, TrainingWorkbench $workbench)
    {
        TrainingWorkbench::platform($request->user());
        $workbench->assertContributions($campaign);
        $ids = TrainingWorkbench::shared()->join('model_training_contributions as c', 'c.dataset_id', '=', 'd.id')->where('c.campaign_id', $campaign->id)->pluck('d.public_id')->all();
        $next = $workbench->createCampaign($request->user(), mb_substr($campaign->name, 0, 75).' — nouvelle tentative', $ids);

        return to_route('platform.training.show', $next);
    }
}
