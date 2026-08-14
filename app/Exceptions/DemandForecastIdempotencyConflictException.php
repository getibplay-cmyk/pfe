<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DemandForecastIdempotencyConflictException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('La clé d’idempotence de la prévision existe avec un payload différent.');
    }
}
