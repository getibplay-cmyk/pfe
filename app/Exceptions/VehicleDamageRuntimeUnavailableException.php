<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class VehicleDamageRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(null, 'Le runtime dommages n’est pas disponible.');
    }
}
