<?php

namespace App\Support\Intelligence\J11;

use App\Exceptions\J11ContractDemoDisabledException;

final class J11ContractDemoGate
{
    public function enabled(): bool
    {
        return ! app()->environment('production')
            && config('intelligence.contract_demo.enabled') === true
            && config('intelligence.contract_demo.synthetic_only') === true
            && config('intelligence.contract_demo.operational_actions_allowed') === false
            && config('intelligence.contract_demo.ready_for_saas') === false;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new J11ContractDemoDisabledException;
        }
    }

    /** @return array<string, bool|int|string> */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'synthetic_only' => config('intelligence.contract_demo.synthetic_only') === true,
            'operational_actions_allowed' => false,
            'ready_for_saas' => false,
            'contract_count' => 4,
            'decision_effect' => 'NO_OPERATIONAL_ACTION',
        ];
    }
}
