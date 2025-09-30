<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proprietor_name' => $this->faker->name(),
            'company_name' => $this->faker->company(),
            'phone' => $this->faker->unique()->phoneNumber(),
            'email' => $this->faker->optional()->safeEmail(),
            'whatsapp' => $this->faker->optional()->phoneNumber(),
            'country' => $this->faker->country(),
            'address' => $this->faker->address(),
            'image' => $this->faker->optional()->imageUrl(),
        ];
    }
}
