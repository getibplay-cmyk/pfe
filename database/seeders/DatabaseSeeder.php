<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\PreventsDemoSeedingInProduction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use PreventsDemoSeedingInProduction;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->ensureDemoSeedingIsAllowed();

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
