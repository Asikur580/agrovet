<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoiceId' => 'INV-' . $this->faker->unique()->numberBetween(1000, 9999),
            'cust_id' => Customer::inRandomOrder()->first()->id ?? Customer::factory()->create()->id,
            'employee_id' => Employee::inRandomOrder()->first()->id ?? Employee::factory()->create()->id,
            'total_item' => $this->faker->numberBetween(1, 10),
            'total_price' => $this->faker->randomFloat(2, 100, 1000),
            'discount' => $this->faker->randomFloat(2, 0, 100),
            'less' => $this->faker->randomFloat(2, 0, 50),
            'grand_total' => $this->faker->randomFloat(2, 50, 1000),
            'paid' => $this->faker->randomFloat(2, 0, 1000),
            'due' => $this->faker->randomFloat(2, 0, 500),
            'sale_date' => $this->faker->date(),
        ];
    }
}
