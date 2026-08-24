<?php

namespace App\Exceptions;

use RuntimeException;

class RentalUsageAnomalyAlreadyActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Une analyse de cet export est déjà active.');
    }
}
