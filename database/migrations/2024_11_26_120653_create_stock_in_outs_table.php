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
        Schema::create('stock_in_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('quantity');
            $table->double('buy_price')->nullable();
            $table->string('in_out');
            $table->string('purpose')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('who_take')->nullable();
            $table->string('in_out_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_in_outs');
    }
};
