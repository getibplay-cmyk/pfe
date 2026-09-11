<?php

namespace App\Support\Tenancy;

final class CustomerPortalMail
{
    public function ready(): bool
    {
        $mailer = (string) config('mail.default');
        $url = config('mail.mailers.'.$mailer.'.url');
        if (filled($url) && ! in_array(parse_url($url, PHP_URL_SCHEME), ['smtp', 'smtps'], true)) {
            return false;
        }

        // Never send bearer links through a log/array transport or a logging fallback.
        return config('mail.mailers.'.$mailer.'.transport') === 'smtp'
            && (filled(config('mail.mailers.'.$mailer.'.host')) || filled(config('mail.mailers.'.$mailer.'.url')))
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }
}
