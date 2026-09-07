<?php

namespace App\Actions\Platform;

use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Models\Platform\TenantOnboardingInvitation;
use Illuminate\Support\Facades\DB;

class ExpireTenantOnboardingInvitations
{
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $invitations = TenantOnboardingInvitation::query()
                ->where('status', TenantOnboardingInvitationStatus::Pending->value)
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            $invitations->each(fn (TenantOnboardingInvitation $invitation) => $invitation->forceFill([
                'status' => TenantOnboardingInvitationStatus::Expired,
            ])->save());

            return $invitations->count();
        });
    }
}
