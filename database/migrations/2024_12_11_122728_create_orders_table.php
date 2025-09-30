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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cust_id')->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnUpdate()->restrictOnDelete();
            $table->double('discount', 8, 2)->default(0); 
            $table->enum('order_type', ['cash', 'credit'])->nullable();
            $table->string('order_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
