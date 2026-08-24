<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehicleDamagePredictionAlreadyReviewedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Cette analyse dommages possède déjà une revue humaine.');
    }
}
