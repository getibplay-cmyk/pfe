<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FleetReallocationIdempotencyConflictException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Cette demande de réallocation existe déjà avec des données différentes. Rechargez la page avant de réessayer.');
    }
}
