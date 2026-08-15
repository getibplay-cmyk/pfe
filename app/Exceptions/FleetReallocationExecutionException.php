<?php

namespace App\Exceptions;

use RuntimeException;

class FleetReallocationExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('L’exécution OR-Tools a échoué de manière contrôlée.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
