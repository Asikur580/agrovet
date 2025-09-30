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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cat_id')->constrained('categories')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('pack_size')->nullable();            
            $table->string('expire_date')->nullable();
            $table->double('buy_price')->default(0);
            $table->double('sell_price')->default(0);
            $table->integer('quantity')->default(0);
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
