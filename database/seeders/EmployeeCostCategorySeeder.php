<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EmployeeCostCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class EmployeeCostCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EmployeeCostCategory::factory(10)->create();
    }
}
