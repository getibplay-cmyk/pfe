<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehiclePlatePredictionAlreadyReviewedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une correction humaine append-only existe déjà pour cette analyse de plaque.');
    }
}
