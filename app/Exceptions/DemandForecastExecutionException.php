<?php

namespace App\Exceptions;

use RuntimeException;

class DemandForecastExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('L’inférence HGB a échoué de manière contrôlée.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
