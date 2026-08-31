<?php

namespace App\Exceptions;

use RuntimeException;

class J11IdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cette démonstration existe déjà avec un contenu différent.');
    }
}
