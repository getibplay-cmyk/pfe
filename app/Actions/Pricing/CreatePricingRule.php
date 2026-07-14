<?php

namespace App\Actions\Pricing;

use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\VehicleCategory;
use App\Support\Audit\AuditRecorder;

class CreatePricingRule
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(array $data, int $actorId): PricingRule
    {
        if (! empty($data['agency_id'])) {
            Agency::findOrFail($data['agency_id']);
        }
        VehicleCategory::findOrFail($data['vehicle_category_id']);
        $rule = PricingRule::create([...$data, 'conditions' => $data['conditions'] ?? [], 'created_by' => $actorId]);
        $this->audit->record('pricing_rule.created', $rule, [], $rule->only(['agency_id', 'vehicle_category_id', 'name', 'daily_rate', 'valid_from', 'valid_to', 'priority', 'currency']));

        return $rule;
    }
}
