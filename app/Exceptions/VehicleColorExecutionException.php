<?php

namespace App\Exceptions;

use RuntimeException;

class VehicleColorExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('L’inférence de couleur a échoué de manière contrôlée.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
