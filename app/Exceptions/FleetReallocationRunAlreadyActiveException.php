<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FleetReallocationRunAlreadyActiveException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une exécution OR-Tools est déjà en attente ou en cours pour cette entreprise.');
    }
}
