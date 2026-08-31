<?php

namespace App\Exceptions;

use RuntimeException;

class DemandForecastExecutionException extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct('La prévision n’a pas pu être générée. Réessayez ou contactez l’administrateur.');
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
