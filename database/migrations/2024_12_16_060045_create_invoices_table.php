<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoiceId')->unique();
            $table->foreignId('cust_id')->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('total_item');
            $table->double('total_price');
            $table->double('discount')->default(0);
            $table->double('less')->default(0);
            $table->double('grand_total');
            $table->double('paid')->default(0);
            $table->double('due')->default(0);
            $table->string('sale_date');
            $table->enum('sale_type', ['cash', 'credit']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
