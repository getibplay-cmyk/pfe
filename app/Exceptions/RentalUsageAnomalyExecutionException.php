<?php

namespace App\Exceptions;

use RuntimeException;

class RentalUsageAnomalyExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('Rental usage anomaly screening failed.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
