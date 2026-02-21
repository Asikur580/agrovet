<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->default(0)->after('image');
            $table->decimal('purchase', 15, 2)->default(0)->after('credit_limit');
            $table->decimal('payment', 15, 2)->default(0)->after('purchase');
            $table->decimal('old_due', 15, 2)->default(0)->after('payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'purchase', 'payment', 'old_due']);
        });
    }
};
