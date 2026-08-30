<?php

namespace Database\Factories;

use App\Enums\IntelligenceCapability;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantIntelligenceAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $this->setIntelligenceAccess($tenant, true);
        });
    }

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999),
            'legal_name' => $name,
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => TenantStatus::Active,
            'settings' => [],
        ];
    }

    public function withIntelligenceDisabled(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $this->setIntelligenceAccess($tenant, false);
        });
    }

    private function setIntelligenceAccess(Tenant $tenant, bool $enabled): void
    {
        foreach (IntelligenceCapability::cases() as $capability) {
            $access = TenantIntelligenceAccess::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('capability', $capability->value)
                ->first() ?? new TenantIntelligenceAccess;
            $access->forceFill([
                'tenant_id' => $tenant->getKey(),
                'capability' => $capability,
                'enabled' => $enabled,
                'updated_by' => null,
                'changed_at' => now(),
            ])->save();
        }
    }
}
