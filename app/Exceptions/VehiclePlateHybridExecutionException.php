<?php

namespace App\Exceptions;

use RuntimeException;

class VehiclePlateHybridExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('La suggestion de plaque a échoué de manière contrôlée.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
