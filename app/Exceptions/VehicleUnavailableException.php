<?php

namespace App\Exceptions;

use RuntimeException;

class VehicleUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Véhicule déjà indisponible sur cette période.');
    }
}
