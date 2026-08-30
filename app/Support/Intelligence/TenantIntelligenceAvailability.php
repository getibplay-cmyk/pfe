<?php

namespace App\Support\Intelligence;

use App\Enums\IntelligenceCapability;

final readonly class TenantIntelligenceAvailability
{
    public function __construct(
        public IntelligenceCapability $capability,
        public bool $globallyEnabled,
        public bool $runtimeReady,
        public bool $tenantActive,
        public bool $tenantAuthorized,
        public string $message,
    ) {}

    public function usable(): bool
    {
        return $this->globallyEnabled
            && $this->runtimeReady
            && $this->tenantActive
            && $this->tenantAuthorized;
    }
}
