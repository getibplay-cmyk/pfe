<?php

namespace App\Policies;

use App\Models\FleetReallocationProposal;
use App\Models\User;

class FleetReallocationProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->agency_id === null && $user->hasPermission('prediction.view');
    }

    public function view(User $user, FleetReallocationProposal $proposal): bool
    {
        return $this->sameTenant($user, $proposal) && $this->viewAny($user);
    }

    public function review(User $user, FleetReallocationProposal $proposal): bool
    {
        return $this->sameTenant($user, $proposal)
            && $user->agency_id === null
            && $user->hasPermission('prediction.demo.review');
    }

    private function sameTenant(User $user, FleetReallocationProposal $proposal): bool
    {
        return $user->tenant_id === $proposal->tenant_id;
    }
}
