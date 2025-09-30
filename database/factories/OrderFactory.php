<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cust_id' => Customer::inRandomOrder()->first()->id ?? Customer::factory()->create()->id,
            'employee_id' => Employee::inRandomOrder()->first()->id ?? Employee::factory()->create()->id,
        ];
    }
}
