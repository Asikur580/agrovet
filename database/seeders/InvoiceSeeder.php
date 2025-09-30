<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\InvoiceProduct;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Faker\Factory as Faker;

class InvoiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();  // Create the Faker instance

        Invoice::factory(10)->create()->each(function ($invoice) use ($faker) {
            $products = Product::inRandomOrder()->limit(rand(1, 5))->get();

            // Ensure at least one product exists for the invoice
            if ($products->isEmpty()) {
                $products = Product::inRandomOrder()->limit(1)->get();
            }

            $totalPrice = 0;
            $totalItem = 0;

            foreach ($products as $product) {
                $quantity = rand(1, 3);
                $unitPrice = $product->sell_price;
                $totalPrice += $quantity * $unitPrice;
                $totalItem++;

                InvoiceProduct::factory()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'price_type' => $faker->randomElement(['tp', 'flat']),
                    'bonus_qty' => rand(0, 3), // Random bonus quantity
                ]);
            }

            $invoice->update([
                'total_item' => $totalItem,
                'total_price' => $totalPrice,
                'grand_total' => $totalPrice - $invoice->discount - $invoice->less,
                'due' => $totalPrice - $invoice->paid,
            ]);
        });
    }
}
