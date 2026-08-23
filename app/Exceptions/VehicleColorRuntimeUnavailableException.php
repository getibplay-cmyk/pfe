<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class VehicleColorRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(
            null,
            'Modèle couleur indisponible : installez le bundle v8 exact puis vérifiez rentfleet:doctor.',
        );
    }
}
