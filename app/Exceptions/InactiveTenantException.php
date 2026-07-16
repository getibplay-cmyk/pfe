<?php

namespace App\Exceptions;

use RuntimeException;

class InactiveTenantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Le tenant doit être actif pour exécuter cette opération.');
    }
}
