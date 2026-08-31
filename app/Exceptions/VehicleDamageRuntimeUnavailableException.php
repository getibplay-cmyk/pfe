<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class VehicleDamageRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(null, 'Le service d’analyse des dommages est temporairement indisponible.');
    }
}
