<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'company_id' => fn (array $attributes) => Branch::findOrFail($attributes['branch_id'])->company_id, 'registration' => fake()->unique()->bothify('ABC-####'), 'make' => 'Toyota', 'model' => 'Corolla', 'status' => 'available', 'odometer' => 0];
    }
}
