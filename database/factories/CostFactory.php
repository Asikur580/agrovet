<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\CostCategory;
use App\Models\EmployeeCostCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Cost>
 */
class CostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Randomly decide whether to assign cost_cat_id or employee_id
        $costCatId = $this->faker->boolean ? CostCategory::inRandomOrder()->first()->id : null;
        $employeeCostCatId = $this->faker->boolean ? EmployeeCostCategory::inRandomOrder()->first()->id : null;
        $employeeId = !$costCatId ? Employee::inRandomOrder()->first()->id : null;

        return [
            'cost_cat_id' => $costCatId, // If random boolean is true, get cost category, else null
            'employee_cost_cat_id' => $employeeCostCatId, // If random boolean is true, get cost category, else null
            'employee_id' => $employeeId, // If cost_cat_id is null, assign employee_id, else null
            'amount' => $this->faker->randomFloat(2, 50, 5000),  // Random amount between 50 and 5000
            'cost_date' => $this->faker->date(), // Random cost date
        ];
    }
}
