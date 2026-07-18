<?php

namespace App\Support\Reporting;

use App\Models\Agency;
use App\Models\Tenant;
use App\Support\Tenancy\AgencyAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class ResolveReportCriteria
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AgencyAccess $agencyAccess,
    ) {}

    public function handle(array $filters): ReportCriteria
    {
        $requestedAgencyId = $filters['agency_id'] ?? null;
        $contextAgencyId = $this->context->agencyId();

        if ($contextAgencyId !== null) {
            $agencyIds = [$this->agencyAccess->required($requestedAgencyId ?: $contextAgencyId)];
        } elseif ($requestedAgencyId !== null && $requestedAgencyId !== '') {
            $agencyIds = [$this->agencyAccess->required($requestedAgencyId)];
        } else {
            $agencyIds = Agency::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        if ($agencyIds === []) {
            throw ValidationException::withMessages(['agency_id' => 'Aucune agence autorisée ne peut alimenter ce rapport.']);
        }

        $tenant = Tenant::query()->findOrFail($this->context->tenantId());
        $timezone = (string) ($tenant->settings['timezone'] ?? config('app.timezone', 'Africa/Casablanca'));

        return ReportCriteria::fromInclusiveDates(
            $tenant->id,
            $agencyIds,
            $filters['date_from'],
            $filters['date_to'],
            $timezone,
            $filters['currency'] ?? null,
        );
    }
}
