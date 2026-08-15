<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FleetReallocationIdempotencyConflictException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('La clé d’idempotence de réallocation existe avec un payload différent.');
    }
}
