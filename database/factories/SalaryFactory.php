<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Salary>
 */
class SalaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 🔹 Random Employee ID
        $employeeId = Employee::inRandomOrder()->first()->id ?? 1; // Default 1 if no employees exist

        // 🔹 Basic Salary (Fixed range)
        $basicSalary = $this->faker->randomFloat(2, 2000, 10000);

        // 🔹 Randomly decide whether to assign Advance or Due
        $hasAdvance = $this->faker->boolean(50); // 50% chance

        $advanceAmount = $hasAdvance ? $this->faker->randomFloat(2, 0, 1000) : 0;
        $dueAmount = !$hasAdvance ? $this->faker->randomFloat(2, 0, 10000) : 0;

        return [
            'employee_id' => $employeeId,
            'month_year' => $this->faker->dateTimeBetween('-12 months', 'now')->format('Y-m'), // Random month-year
            'basic_salary' => $basicSalary,
            'paid_amount' => $this->faker->randomFloat(2, 0, $basicSalary), // Paid amount (≤ Basic Salary)
            'advance_amount' => $advanceAmount, // Either this or due will be 0
            'due_amount' => $dueAmount, // Either this or advance will be 0
        ];
    }
}
