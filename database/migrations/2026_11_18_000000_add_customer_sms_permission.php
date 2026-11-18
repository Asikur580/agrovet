<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Permission::firstOrCreate([
            'name' => 'Customer-sms',
            'guard_name' => 'sanctum',
        ]);
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'Customer-sms')->where('guard_name', 'sanctum')->delete();
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
