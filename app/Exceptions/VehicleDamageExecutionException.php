<?php

namespace App\Exceptions;

use RuntimeException;

class VehicleDamageExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('Vehicle damage prediction failed.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
