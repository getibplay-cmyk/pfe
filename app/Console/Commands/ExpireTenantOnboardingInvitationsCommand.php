<?php

namespace App\Console\Commands;

use App\Actions\Platform\ExpireTenantOnboardingInvitations;
use Illuminate\Console\Command;

class ExpireTenantOnboardingInvitationsCommand extends Command
{
    protected $signature = 'onboarding:expire-invitations';

    protected $description = 'Clôture les invitations SaaS dont le délai est dépassé';

    public function handle(ExpireTenantOnboardingInvitations $expire): int
    {
        $count = $expire->handle();
        $this->info("{$count} invitation(s) expirée(s) clôturée(s).");

        return self::SUCCESS;
    }
}
