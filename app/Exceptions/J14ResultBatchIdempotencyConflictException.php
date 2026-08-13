<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class J14ResultBatchIdempotencyConflictException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('La clé d’idempotence J14-B existe avec un payload différent.');
    }
}
