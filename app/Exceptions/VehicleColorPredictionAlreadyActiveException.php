<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehicleColorPredictionAlreadyActiveException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une analyse de couleur est déjà en attente ou en cours pour ce véhicule.');
    }
}
