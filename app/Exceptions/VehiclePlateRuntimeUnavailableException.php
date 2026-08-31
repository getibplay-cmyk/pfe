<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class VehiclePlateRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(
            null,
            'Le service de lecture de l’immatriculation est temporairement indisponible. Contactez l’administrateur de la plateforme.',
        );
    }
}
