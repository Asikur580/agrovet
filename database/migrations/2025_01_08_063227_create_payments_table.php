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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cust_id')->nullable()->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->cascadeOnUpdate()->restrictOnDelete();
            $table->double('amount');
            $table->enum('payment_method', ['cash', 'check']);
            $table->string('payment_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
