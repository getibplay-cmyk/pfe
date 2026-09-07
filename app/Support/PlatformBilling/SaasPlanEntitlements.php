<?php

namespace App\Support\PlatformBilling;

use App\Enums\IntelligenceCapability;
use Illuminate\Validation\ValidationException;

final class SaasPlanEntitlements
{
    /** @return array{max_agencies: null, max_users: null, max_vehicles: null, monthly_intelligence_runs: null, intelligence_capabilities: list<string>} */
    public function defaults(): array
    {
        return [
            'max_agencies' => null,
            'max_users' => null,
            'max_vehicles' => null,
            'monthly_intelligence_runs' => null,
            'intelligence_capabilities' => array_map(
                static fn (IntelligenceCapability $capability): string => $capability->value,
                IntelligenceCapability::cases(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $fallback
     * @return array{max_agencies: ?int, max_users: ?int, max_vehicles: ?int, monthly_intelligence_runs: ?int, intelligence_capabilities: list<string>}
     */
    public function fromInput(array $data, ?array $fallback = null): array
    {
        if (! array_key_exists('entitlements_configured', $data)) {
            return $this->normalize($fallback ?? $this->defaults());
        }

        $capabilities = [];
        foreach (array_values($data['intelligence_capabilities'] ?? []) as $value) {
            $capability = IntelligenceCapability::tryFrom((string) $value);
            if ($capability === null) {
                throw ValidationException::withMessages([
                    'intelligence_capabilities' => 'Une fonctionnalité intelligente sélectionnée est inconnue.',
                ]);
            }
            $capabilities[$capability->value] = true;
        }

        return [
            'max_agencies' => $this->quota($data['max_agencies'] ?? null, 'max_agencies'),
            'max_users' => $this->quota($data['max_users'] ?? null, 'max_users'),
            'max_vehicles' => $this->quota($data['max_vehicles'] ?? null, 'max_vehicles'),
            'monthly_intelligence_runs' => $this->quota(
                $data['monthly_intelligence_runs'] ?? null,
                'monthly_intelligence_runs',
            ),
            'intelligence_capabilities' => collect(IntelligenceCapability::cases())
                ->map(fn (IntelligenceCapability $capability): string => $capability->value)
                ->filter(fn (string $capability): bool => isset($capabilities[$capability]))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $entitlements
     * @return array{max_agencies: ?int, max_users: ?int, max_vehicles: ?int, monthly_intelligence_runs: ?int, intelligence_capabilities: list<string>}
     */
    public function normalize(?array $entitlements): array
    {
        $defaults = $this->defaults();
        $entitlements ??= [];
        $storedCapabilities = $entitlements['intelligence_capabilities'] ?? $defaults['intelligence_capabilities'];
        if (! is_array($storedCapabilities)) {
            $storedCapabilities = $defaults['intelligence_capabilities'];
        }
        $capabilities = array_values(array_unique(array_filter(
            $storedCapabilities,
            static fn (mixed $value): bool => is_string($value) && IntelligenceCapability::tryFrom($value) !== null,
        )));

        return [
            'max_agencies' => $this->storedQuota($entitlements['max_agencies'] ?? null),
            'max_users' => $this->storedQuota($entitlements['max_users'] ?? null),
            'max_vehicles' => $this->storedQuota($entitlements['max_vehicles'] ?? null),
            'monthly_intelligence_runs' => $this->storedQuota($entitlements['monthly_intelligence_runs'] ?? null),
            'intelligence_capabilities' => $capabilities,
        ];
    }

    private function quota(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw ValidationException::withMessages([$field => 'Le quota doit être un entier positif ou nul.']);
        }

        return (int) $value;
    }

    private function storedQuota(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
