<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehiclePlatePredictionAlreadyActiveException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une analyse de plaque est déjà en attente pour ce véhicule.');
    }
}
