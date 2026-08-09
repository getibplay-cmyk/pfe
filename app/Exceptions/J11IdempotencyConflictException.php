<?php

namespace App\Exceptions;

use RuntimeException;

class J11IdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La clé d’idempotence existe avec une empreinte différente.');
    }
}
