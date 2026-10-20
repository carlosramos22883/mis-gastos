<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISOS = ['prestamos.view', 'prestamos.create', 'prestamos.edit', 'prestamos.delete'];

    public function up(): void
    {
        // Lo que el hogar debe (banco o persona). Saldo actual = saldo_inicial − capital de los pagos.
        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // titular
            $table->string('nombre', 100);
            $table->string('tipo', 20)->default('consumo'); // consumo | hipotecario | vehiculo | persona | otro
            $table->foreignId('banco_id')->nullable()->constrained('bancos')->nullOnDelete();
            $table->string('acreedor', 100)->nullable(); // si no es un banco (ej. "Evelyn")
            $table->decimal('monto_original', 15, 2);
            $table->decimal('tasa_anual', 6, 3)->default(0);
            $table->decimal('tasa_mora', 6, 3)->nullable();
            $table->unsignedSmallInteger('plazo_meses')->nullable();
            $table->date('fecha_otorgamiento')->nullable();
            $table->unsignedTinyInteger('dia_pago')->nullable();
            $table->decimal('cuota', 15, 2)->nullable(); // capital + interés (sin seguros)
            $table->decimal('saldo_inicial', 15, 2); // saldo de capital a fecha_saldo
            $table->date('fecha_saldo');
            $table->string('medio_pago', 20)->default('por_definir'); // efectivo | transferencia | planilla | por_definir
            $table->decimal('monto_planilla', 15, 2)->nullable(); // parte de la cuota que se descuenta del sueldo (el resto se paga por medio_pago)
            $table->foreignId('compromiso_recurrente_id')->nullable()->constrained('compromisos_recurrentes')->nullOnDelete();
            $table->string('color', 7)->default('#7C3AED');
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Cargos fijos de cada cuota: seguros, IVA, comisiones.
        Schema::create('prestamo_cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->decimal('monto', 15, 2);
            $table->timestamps();
        });

        Schema::create('prestamo_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prestamo_id')->constrained('prestamos')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('capital', 15, 2)->default(0);
            $table->decimal('interes', 15, 2)->default(0);
            $table->decimal('cargos', 15, 2)->default(0);
            $table->decimal('mora', 15, 2)->default(0);
            $table->decimal('planilla', 15, 2)->default(0); // parte del total que se descontó del sueldo (no mueve dinero aquí)
            $table->string('origen', 10); // efectivo | cuenta | planilla | ninguno
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->cascadeOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->foreignId('compromiso_id')->nullable()->constrained('compromisos')->nullOnDelete(); // cuota del mes que abonó
            $table->decimal('monto_compromiso', 15, 2)->default(0);
            $table->text('comentario')->nullable();
            $table->timestamps();
            $table->index(['prestamo_id', 'fecha']);
        });

        foreach (self::PERMISOS as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        foreach (['Administrador', 'Usuario'] as $rol) {
            Role::where('name', $rol)->first()?->givePermissionTo(self::PERMISOS);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamo_pagos');
        Schema::dropIfExists('prestamo_cargos');
        Schema::dropIfExists('prestamos');
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
