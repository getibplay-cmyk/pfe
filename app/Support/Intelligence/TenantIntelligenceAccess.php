<?php

namespace App\Support\Intelligence;

use App\Enums\IntelligenceCapability;
use App\Enums\TenantStatus;
use App\Exceptions\TenantIntelligenceUnavailableException;
use App\Models\TenantIntelligenceAccess as TenantIntelligenceAccessModel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class TenantIntelligenceAccess
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly IntelligenceCapabilityCatalog $catalog,
    ) {}

    public function status(
        IntelligenceCapability $capability,
        ?int $tenantId = null,
    ): TenantIntelligenceAvailability {
        $tenantId ??= $this->context->tenantId();
        $tenantActive = DB::table('tenants')
            ->where('id', $tenantId)
            ->where('status', TenantStatus::Active->value)
            ->whereNull('deleted_at')
            ->exists();
        $tenantAuthorized = TenantIntelligenceAccessModel::query()
            ->where('tenant_id', $tenantId)
            ->where('capability', $capability->value)
            ->where('enabled', true)
            ->exists();
        $globallyEnabled = $tenantActive && $tenantAuthorized
            ? $this->catalog->globallyEnabled($capability)
            : false;
        $runtimeReady = $globallyEnabled
            ? $this->catalog->runtimeReady($capability)
            : false;

        return new TenantIntelligenceAvailability(
            capability: $capability,
            globallyEnabled: $globallyEnabled,
            runtimeReady: $runtimeReady,
            tenantActive: $tenantActive,
            tenantAuthorized: $tenantAuthorized,
            message: $this->message(
                $globallyEnabled,
                $runtimeReady,
                $tenantActive,
                $tenantAuthorized,
            ),
        );
    }

    public function usable(IntelligenceCapability $capability, ?int $tenantId = null): bool
    {
        return $this->status($capability, $tenantId)->usable();
    }

    public function authorized(IntelligenceCapability $capability, ?int $tenantId = null): bool
    {
        $tenantId ??= $this->context->tenantId();

        return DB::table('tenants')
            ->where('id', $tenantId)
            ->where('status', TenantStatus::Active->value)
            ->whereNull('deleted_at')
            ->exists()
            && TenantIntelligenceAccessModel::query()
                ->where('tenant_id', $tenantId)
                ->where('capability', $capability->value)
                ->where('enabled', true)
                ->exists();
    }

    public function ensureAuthorized(IntelligenceCapability $capability, ?int $tenantId = null): void
    {
        if (! $this->authorized($capability, $tenantId)) {
            throw new TenantIntelligenceUnavailableException;
        }
    }

    public function ensureUsable(IntelligenceCapability $capability, ?int $tenantId = null): void
    {
        if (! $this->usable($capability, $tenantId)) {
            throw new TenantIntelligenceUnavailableException;
        }
    }

    private function message(
        bool $globallyEnabled,
        bool $runtimeReady,
        bool $tenantActive,
        bool $tenantAuthorized,
    ): string {
        return match (true) {
            ! $tenantActive => 'Cette entreprise n’est pas active.',
            ! $tenantAuthorized => 'Cette fonctionnalité n’est pas autorisée pour cette entreprise.',
            ! $globallyEnabled, ! $runtimeReady => 'Cette fonctionnalité est temporairement indisponible.',
            default => 'Disponible pour cette entreprise.',
        };
    }
}
