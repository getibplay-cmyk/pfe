<?php

namespace App\Http\Controllers;

use App\Actions\Intelligence\ImportJ11SyntheticAdvisory;
use App\Actions\Intelligence\RecordJ11DemoDecision;
use App\Enums\J11AdvisoryModule;
use App\Enums\J11DemoDecision;
use App\Http\Requests\ImportJ11SyntheticAdvisoryRequest;
use App\Http\Requests\RecordJ11DemoDecisionRequest;
use App\Models\AiAdvisoryRecordDemo;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class J11ContractDemoController extends Controller
{
    public function index(Request $request, TenantContext $context): View
    {
        $this->authorize('viewAny', AiAdvisoryRecordDemo::class);

        $records = AiAdvisoryRecordDemo::query()
            ->with(['agency', 'creator', 'decisions.actor'])
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->latest('id')
            ->paginate(25);

        return view('intelligence.contracts-demo.index', [
            'records' => $records,
            'modules' => J11AdvisoryModule::cases(),
            'canReview' => $request->user()->can('create', AiAdvisoryRecordDemo::class),
            'reasonCodes' => RecordJ11DemoDecisionRequest::REASON_CODES,
        ]);
    }

    public function store(
        ImportJ11SyntheticAdvisoryRequest $request,
        ImportJ11SyntheticAdvisory $import,
    ): RedirectResponse {
        $module = J11AdvisoryModule::from($request->validated('module_id'));
        $result = $import->handle($module, $request->user());

        return redirect()->route('intelligence.contract-demo.index')->with(
            'status',
            $result->created
                ? 'Fixture synthétique validée et ajoutée sans effet opérationnel.'
                : 'Fixture déjà présente : rejeu idempotent sans duplication.',
        );
    }

    public function decide(
        RecordJ11DemoDecisionRequest $request,
        AiAdvisoryRecordDemo $record,
        RecordJ11DemoDecision $recordDecision,
    ): RedirectResponse {
        $data = $request->validated();
        $recordDecision->handle(
            $record,
            $request->user(),
            J11DemoDecision::from($data['decision']),
            $data['reason_code'],
        );

        return redirect()->route('intelligence.contract-demo.index')
            ->with('status', 'Décision humaine de démonstration enregistrée, sans action métier.');
    }
}
