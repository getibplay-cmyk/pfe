<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehicleDamagePredictionAlreadyActiveException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une analyse dommages est déjà active pour cette inspection.');
    }
}
