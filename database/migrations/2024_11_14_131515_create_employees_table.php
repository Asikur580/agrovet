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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique();
            $table->foreignId('designation_id')->constrained('designations')->onDelete('restrict');
            $table->string('name');
            $table->string('phone');
            $table->string('territory');
            $table->string('district');
            $table->string('national_id')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('image')->nullable();
            $table->double('credit_limit')->default(0);
            $table->double('basic_salary')->default(0);
            $table->integer('created_by');
            $table->enum('status', ['active', 'deactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
