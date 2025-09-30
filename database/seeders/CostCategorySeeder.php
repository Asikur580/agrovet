<?php

namespace Database\Seeders;

use App\Models\CostCategory;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class CostCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CostCategory::factory(10)->create();
    }
}
