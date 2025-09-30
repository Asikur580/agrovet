<?php

namespace Database\Seeders;

use App\Models\Salary;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class SalarySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Salary::factory(20)->create();
    }
}
