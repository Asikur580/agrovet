<?php

namespace Database\Seeders;

use App\Models\StockInOut;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class StockInOutSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StockInOut::factory(50)->create(); // Inserts 50 dummy stock transactions
    }
}
