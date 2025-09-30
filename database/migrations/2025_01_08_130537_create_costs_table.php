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
        Schema::create('costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_cat_id')->nullable()->constrained('cost_categories')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_cost_cat_id')->nullable()->constrained('employee_cost_categories')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnUpdate()->restrictOnDelete();
            $table->double('amount');
            $table->string('cost_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('costs');
    }
};
