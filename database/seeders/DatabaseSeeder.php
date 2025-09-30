<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
       
        $this->call([
            DesignationSeeder::class,
            EmployeeSeeder::class,
            UserSeeder::class,
            CustomerSeeder::class,
            BrandSeeder::class,
            CategorySeeder::class,
            SupplierSeeder::class,
            ProductSeeder::class,
            StockInOutSeeder::class,
            OrderSeeder::class,
            InvoiceSeeder::class,
            CostCategorySeeder::class,
            EmployeeCostCategorySeeder::class,
            PaymentSeeder::class,
            CostSeeder::class,
            SalarySeeder::class,

        ]);
       
    }
}
