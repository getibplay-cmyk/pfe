<?php

namespace App\Exceptions;

use RuntimeException;

class J11ContractDemoDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La démonstration contractuelle Intelligence est désactivée.');
    }
}
