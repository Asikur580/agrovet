<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderProduct>
 */
class OrderProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::inRandomOrder()->first() ?? Product::factory()->create();
        $quantity = $this->faker->numberBetween(1, 10);
        $unitPrice = $product->sell_price;
        
        return [
            'order_id' => Order::inRandomOrder()->first()->id ?? Order::factory()->create()->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'bonus_qty' => $this->faker->numberBetween(0, 5),
            'price_type' => $this->faker->randomElement(['tp', 'flat']),
        ];
    }
}
