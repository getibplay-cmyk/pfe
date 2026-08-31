<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class DemandForecastRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(
            null,
            'Le service de prévision est temporairement indisponible. Contactez l’administrateur de la plateforme.',
        );
    }
}
