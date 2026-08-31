<?php

namespace App\Exceptions;

use RuntimeException;

class J11ContractDemoDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cette démonstration n’est pas disponible.');
    }
}
