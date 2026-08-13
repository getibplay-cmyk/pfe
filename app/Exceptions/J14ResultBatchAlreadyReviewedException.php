<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class J14ResultBatchAlreadyReviewedException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Une décision humaine append-only existe déjà pour ce lot.');
    }
}
