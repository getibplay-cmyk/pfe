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
        $this->call([
            RolesPermissionsSeeder::class,
            DemoTenancySeeder::class,
            Lot02DemoSeeder::class,
            Lot03DemoSeeder::class,
            Lot04DemoSeeder::class,
        ]);
    }
}
