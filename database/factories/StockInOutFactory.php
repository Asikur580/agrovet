<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockInOut>
 */
class StockInOutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inOutType = $this->faker->randomElement(['in', 'out']);
        $product = Product::inRandomOrder()->first() ?? Product::factory()->create();
        $quantity = $this->faker->numberBetween(1, 50);

        // Adjust product quantity directly
        if ($inOutType === 'in') {
            $product->increment('quantity', $quantity);
        } else {
            $product->decrement('quantity', min($product->quantity, $quantity)); // Prevent negative stock
        }

        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'in_out' => $inOutType,
            'in_out_date' => $this->faker->date('Y-m-d'),

            // If stock is coming in
            'buy_price' => $inOutType === 'in' ? $this->faker->randomFloat(2, 10, 500) : null,
            'supplier_id' => $inOutType === 'in' ? (Supplier::inRandomOrder()->first()->id ?? Supplier::factory()->create()->id) : null,

            // If stock is going out
            'purpose' => $inOutType === 'out' ? $this->faker->sentence() : null,
            'who_take' => $inOutType === 'out' ? $this->faker->name() : null,
        ];
    }
}
