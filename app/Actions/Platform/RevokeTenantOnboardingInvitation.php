<?php

namespace App\Actions\Platform;

use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Models\Platform\TenantOnboardingInvitation;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeTenantOnboardingInvitation
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
    ) {}

    public function handle(TenantOnboardingInvitation $invitation, int $actorId): TenantOnboardingInvitation
    {
        $this->platformAdmin->actor($actorId);

        return DB::transaction(function () use ($invitation): TenantOnboardingInvitation {
            $locked = TenantOnboardingInvitation::query()->whereKey($invitation)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TenantOnboardingInvitationStatus::Pending) {
                throw ValidationException::withMessages(['invitation' => __('Cette invitation ne peut plus être révoquée.')]);
            }

            $locked->forceFill([
                'status' => TenantOnboardingInvitationStatus::Revoked,
                'revoked_at' => now(),
            ])->save();
            $this->audit->record('platform.onboarding_invitation.revoked', $locked->plan, [], [
                'invitation_id' => $locked->getKey(),
                'email' => $locked->email,
            ]);

            return $locked->refresh();
        });
    }
}
