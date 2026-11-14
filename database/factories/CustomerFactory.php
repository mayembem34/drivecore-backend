<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'company_id' => fn (array $attributes) => Branch::findOrFail($attributes['branch_id'])->company_id, 'name' => fake()->name(), 'type' => 'individual'];
    }
}
