<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cat_id' => Category::inRandomOrder()->first()->id ?? Category::factory()->create()->id, // Select a random category or create one if none exist
            'brand_id' => Brand::inRandomOrder()->first()->id ?? Brand::factory()->create()->id, // Select a random brand or create one if none exist
            'name' => $this->faker->word(),
            'pack_size' => $this->faker->optional()->randomElement(['Small', 'Medium', 'Large']),
            'expire_date' => $this->faker->optional()->date('Y-m-d'),
            'buy_price' => $this->faker->randomFloat(2, 10, 500),
            'sell_price' => $this->faker->randomFloat(2, 15, 600),
            'quantity' => 0,
        ];
    }
}
