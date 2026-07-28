<?php

namespace App\Http\Controllers;

use App\Http\Requests\IntelligenceFilterRequest;
use App\Models\Agency;
use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Reporting\ResolveReportCriteria;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class IntelligenceController extends Controller
{
    public function __invoke(
        IntelligenceFilterRequest $request,
        ResolveReportCriteria $resolver,
        IntelligencePseudonymizer $pseudonymizer,
        TenantContext $context,
    ): View {
        $data = $request->validated();
        $criteria = $resolver->handle($data);
        $agencies = Agency::query()
            ->when($context->agencyId(), fn ($query, $agencyId) => $query->whereKey($agencyId))
            ->orderBy('name')
            ->get();

        return view('intelligence.index', [
            'agencies' => $agencies,
            'configured' => $pseudonymizer->configured(),
            'filters' => [
                ...$data,
                'agency_id' => count($criteria->agencyIds) === 1 ? $criteria->agencyIds[0] : null,
            ],
        ]);
    }
}
