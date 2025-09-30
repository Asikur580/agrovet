<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\OrderProduct;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Faker\Factory as Faker;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create(); // Create a Faker instance

        Order::factory(10)->create()->each(function ($order) use ($faker) {
            $products = Product::inRandomOrder()->limit(rand(1, 5))->get();

            // Ensure at least one product exists for the invoice
            if ($products->isEmpty()) {
                $products = Product::inRandomOrder()->limit(1)->get();
            }

            foreach ($products as $product) {
                $quantity = rand(1, 3);
                $unitPrice = $product->sell_price;

                OrderProduct::factory()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'price_type' => $faker->randomElement(['tp', 'flat']),
                    'bonus_qty' => rand(0, 3), // Random bonus quantity
                ]);
            }
        });
    }
}
