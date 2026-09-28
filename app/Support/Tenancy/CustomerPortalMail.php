<?php

namespace App\Support\Tenancy;

use App\Support\Security\PrivateMailTransport;

final class CustomerPortalMail
{
    public function ready(): bool
    {
        return PrivateMailTransport::allows(config('mail', []), requireTls: app()->environment('production'));
    }
}
