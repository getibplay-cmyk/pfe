<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehicleColorPredictionAlreadyReviewedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une décision humaine append-only existe déjà pour cette analyse couleur.');
    }
}
