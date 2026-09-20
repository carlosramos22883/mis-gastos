<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['view', 'create', 'edit', 'delete', 'export'] as $action) {
            Permission::firstOrCreate(['name' => "tarjetas.{$action}"]);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'tarjetas.view',
            'tarjetas.create',
            'tarjetas.edit',
            'tarjetas.delete',
            'tarjetas.export',
        ])->delete();
    }
};
