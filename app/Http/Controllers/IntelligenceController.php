<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntelligenceFilterRequest;
use App\Models\Agency;
use App\Models\IntelligenceDatasetExportRun;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Intelligence\J11\J11ContractDemoGate;
use App\Support\Intelligence\J13\J13ConsultativeEvidenceCatalog;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class IntelligenceController extends Controller
{
    public function __invoke(
        IntelligenceFilterRequest $request,
        ResolveReportCriteria $resolver,
        IntelligencePseudonymizer $pseudonymizer,
        J11ContractDemoGate $contractDemoGate,
        J13ConsultativeEvidenceCatalog $consultativeEvidence,
        TenantContext $context,
    ): View {
        $data = $request->validated();
        $criteria = $resolver->handle($data);
        $agencies = Agency::query()
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->whereKey($agencyId))
            ->orderBy('name')
            ->get();
        $exportRuns = collect();

        if ($request->user()->hasPermission('prediction.export')) {
            $exportRuns = IntelligenceDatasetExportRun::query()
                ->when($context->agencyId(), fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
                ->latest('created_at')
                ->latest('id')
                ->limit(10)
                ->get();
        }

        return view('intelligence.index', [
            'agencies' => $agencies,
            'configured' => $pseudonymizer->configured(),
            'contractDemo' => $contractDemoGate->status(),
            'consultativeModules' => $consultativeEvidence->cards(),
            'consultativeGate' => $consultativeEvidence->gate(),
            'anomalyLineage' => $consultativeEvidence->anomalyLineage(),
            'exportRuns' => $exportRuns,
            'filters' => [
                ...$data,
                'agency_id' => count($criteria->agencyIds) === 1 ? $criteria->agencyIds[0] : null,
            ],
        ]);
    }
}
