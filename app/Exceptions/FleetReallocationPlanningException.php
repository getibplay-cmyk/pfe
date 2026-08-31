<?php

namespace App\Exceptions;

use RuntimeException;

class FleetReallocationPlanningException extends RuntimeException
{
    public function __construct(private readonly string $codeName)
    {
        parent::__construct('La planification de réallocation n’est pas disponible.');
    }

    public function failureCode(): string
    {
        return $this->codeName;
    }
}
