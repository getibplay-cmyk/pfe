<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \LogicException('Les données fictives de démonstration sont interdites en production.');
        }

        $this->call([
            RolesPermissionsSeeder::class,
            DemoTenancySeeder::class,
            Lot02DemoSeeder::class,
            Lot03DemoSeeder::class,
            Lot04DemoSeeder::class,
            Lot05DemoSeeder::class,
        ]);
    }
}
