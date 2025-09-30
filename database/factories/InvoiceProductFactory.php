<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InvoiceProduct>
 */
class InvoiceProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory()->create();
        $quantity = $this->faker->numberBetween(1, 5);
        $unitPrice = $product->sell_price;

        return [
            'invoice_id' => Invoice::inRandomOrder()->first()->id ?? Invoice::factory()->create()->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'due_quantity' => rand(0, $quantity), // Random due quantity
            'bonus_qty' => rand(0, 3), // Random bonus quantity
            'price_type' => $this->faker->randomElement(['tp', 'flat']),
        ];
    }
}
