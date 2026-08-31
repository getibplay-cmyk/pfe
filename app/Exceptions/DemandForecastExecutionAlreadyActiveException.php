<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DemandForecastExecutionAlreadyActiveException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une prévision est déjà en attente ou en cours pour cet historique.');
    }
}
