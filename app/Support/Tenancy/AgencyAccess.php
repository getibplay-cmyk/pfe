<?php

namespace App\Support\Tenancy;

use App\Models\Agency;
use Illuminate\Validation\ValidationException;

class AgencyAccess
{
    public function __construct(private readonly TenantContext $context) {}

    public function required(mixed $requestedAgencyId): int
    {
        if ($requestedAgencyId === null || $requestedAgencyId === '') {
            throw ValidationException::withMessages(['agency_id' => 'Une agence accessible est obligatoire.']);
        }

        return $this->resolve((int) $requestedAgencyId);
    }

    public function optional(mixed $requestedAgencyId): ?int
    {
        if ($requestedAgencyId === null || $requestedAgencyId === '') {
            if ($this->context->agencyId() !== null) {
                throw ValidationException::withMessages(['agency_id' => 'La ressource doit rester dans votre agence.']);
            }

            return null;
        }

        return $this->resolve((int) $requestedAgencyId);
    }

    private function resolve(int $requestedAgencyId): int
    {
        $contextAgencyId = $this->context->agencyId();

        if ($contextAgencyId !== null && $contextAgencyId !== $requestedAgencyId) {
            throw ValidationException::withMessages(['agency_id' => 'Cette agence ne fait pas partie du contexte actif.']);
        }

        Agency::findOrFail($requestedAgencyId);

        return $contextAgencyId ?? $requestedAgencyId;
    }
}
