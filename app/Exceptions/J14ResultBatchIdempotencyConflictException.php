<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class J14ResultBatchIdempotencyConflictException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Ces résultats existent déjà avec un contenu différent. Rechargez la page avant de réessayer.');
    }
}
