<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class DemandForecastRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(
            null,
            'Runtime HGB indisponible : installez le bundle J5 exact puis vérifiez rentfleet:doctor.',
        );
    }
}
