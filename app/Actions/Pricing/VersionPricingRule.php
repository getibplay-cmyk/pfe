<?php

namespace App\Actions\Pricing;

use App\Models\PricingRule;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

class VersionPricingRule
{
    public function __construct(private CreatePricingRule $create, private AuditRecorder $audit) {}

    public function handle(PricingRule $rule, array $data, int $actorId): PricingRule
    {
        return DB::transaction(function () use ($rule, $data, $actorId) {
            $locked = PricingRule::whereKey($rule)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => false])->save();
            $version = $this->create->handle($data, $actorId);
            $this->audit->record('pricing_rule.versioned', $locked, ['is_active' => true], ['is_active' => false, 'replacement_id' => $version->id]);

            return $version;
        });
    }
}
