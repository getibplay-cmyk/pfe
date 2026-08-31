<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FleetReallocationAlreadyReviewedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une décision a déjà été enregistrée pour cette suggestion.');
    }
}
