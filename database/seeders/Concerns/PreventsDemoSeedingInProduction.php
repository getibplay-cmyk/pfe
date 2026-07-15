<?php

namespace Database\Seeders\Concerns;

use LogicException;

trait PreventsDemoSeedingInProduction
{
    private function ensureDemoSeedingIsAllowed(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Les données fictives de démonstration sont interdites en production.');
        }
    }
}
