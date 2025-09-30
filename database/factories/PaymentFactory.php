<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Decide whether to use customer/employee or supplier
        $isSupplierPayment = $this->faker->boolean;  // Randomly decide if this payment is for a supplier

        return [
            'cust_id' => $isSupplierPayment ? null : Customer::inRandomOrder()->first()->id,  // If supplier, set to null
            'employee_id' => $isSupplierPayment ? null : Employee::inRandomOrder()->first()->id,  // If supplier, set to null
            'supplier_id' => $isSupplierPayment ? Supplier::inRandomOrder()->first()->id : null,  // If not supplier, set to null
            'amount' => $this->faker->randomFloat(2, 100, 1000),  // Random amount between 100 and 1000
            'payment_method' => $this->faker->randomElement(['cash', 'check']), // Random payment method
            'payment_date' => $this->faker->date(), // Random payment date
        ];
    }
}
