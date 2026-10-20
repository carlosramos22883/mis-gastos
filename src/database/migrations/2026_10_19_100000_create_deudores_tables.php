<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deudores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('tipo', 20)->default('persona'); // persona | institucion | empresa
            $table->string('contacto', 150)->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['hogar_id', 'nombre']);
        });

        // Lo prestado. Si salió de efectivo, una cuenta o una tarjeta, guarda ese movimiento (y se va con él).
        Schema::create('deudas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deudor_id')->constrained('deudores')->cascadeOnDelete();
            $table->string('descripcion', 255);
            $table->text('comentario')->nullable();
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->string('origen', 10); // efectivo | cuenta | tarjeta | ninguno
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->cascadeOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->foreignId('movimiento_tarjeta_id')->nullable()->constrained('movimientos_tarjeta')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['deudor_id', 'fecha']);
        });

        // Lo que el deudor devuelve. Un mismo ingreso puede abonar a varias deudas (una fila por deuda).
        Schema::create('deuda_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deuda_id')->constrained('deudas')->cascadeOnDelete();
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->string('destino', 10); // efectivo | cuenta | ninguno
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->cascadeOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->text('comentario')->nullable();
            $table->timestamps();
            $table->index('deuda_id');
        });

        $permisos = ['deudores.view', 'deudores.create', 'deudores.edit', 'deudores.delete'];
        foreach ($permisos as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        foreach (['Administrador', 'Usuario'] as $rol) {
            Role::where('name', $rol)->first()?->givePermissionTo($permisos);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('deuda_pagos');
        Schema::dropIfExists('deudas');
        Schema::dropIfExists('deudores');
        Permission::whereIn('name', ['deudores.view', 'deudores.create', 'deudores.edit', 'deudores.delete'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
