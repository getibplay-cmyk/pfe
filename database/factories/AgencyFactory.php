<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Agency> */
class AgencyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->city().' Centre';

        return [
            'code' => Str::upper(fake()->unique()->lexify('???')),
            'name' => $name,
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
