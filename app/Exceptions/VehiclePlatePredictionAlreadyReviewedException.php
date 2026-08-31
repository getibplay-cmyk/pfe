<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehiclePlatePredictionAlreadyReviewedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une correction a déjà été enregistrée pour cette lecture d’immatriculation.');
    }
}
