<?php

namespace Database\Factories;

use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => $this->faker->unique()->numberBetween(1000, 9999),
            'designation_id' => Designation::inRandomOrder()->first()->id ?? Designation::factory(),
            'name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'territory' => $this->faker->city(),
            'district' => $this->faker->state(),
            'national_id' => $this->faker->optional()->numerify('############'),
            'blood_group' => $this->faker->optional()->randomElement(['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']),
            'image' => $this->faker->optional()->imageUrl(),
            'credit_limit' => $this->faker->randomFloat(2, 5000, 50000),
            'basic_salary' => $this->faker->randomFloat(2, 10000, 50000),
            'created_by' => 1, // Assuming an admin user ID 1
            'status' => $this->faker->randomElement(['active', 'deactive']),
        ];
    }
}
