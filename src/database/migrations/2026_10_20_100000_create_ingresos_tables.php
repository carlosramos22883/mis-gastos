<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISOS = ['ingresos.view', 'ingresos.create', 'ingresos.edit', 'ingresos.delete'];

    public function up(): void
    {
        // De dónde viene el dinero (sueldo, servicios profesionales…) y a dónde entra.
        Schema::create('fuentes_ingreso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // quién lo recibe
            $table->string('nombre', 100);
            $table->string('tipo', 20)->default('planilla'); // planilla | servicios | otro
            $table->string('pagador', 100)->nullable();
            $table->string('frecuencia', 20)->default('mensual'); // mensual | quincenal
            $table->unsignedTinyInteger('dia_pago')->nullable();
            $table->unsignedTinyInteger('dia_pago_2')->nullable();
            $table->decimal('monto_bruto', 15, 2)->nullable(); // bruto habitual (se propone al registrar)
            $table->string('destino', 20)->default('efectivo'); // efectivo | cuenta:{id}
            $table->string('color', 7)->default('#16A34A');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Plantilla del recibo: descuentos y adiciones habituales (fijos o en %), opcionalmente ligados a un préstamo.
        Schema::create('fuente_ingreso_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuente_ingreso_id')->constrained('fuentes_ingreso')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('tipo', 10); // descuento | adicion
            $table->decimal('monto', 15, 2)->nullable();
            $table->decimal('porcentaje', 6, 3)->nullable(); // sobre el bruto
            $table->decimal('tope', 15, 2)->nullable(); // máximo (ej. ISSS $30)
            $table->foreignId('prestamo_id')->nullable()->constrained('prestamos')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Cada pago recibido con su desglose; el neto entra como ingreso al efectivo o a una cuenta.
        Schema::create('ingresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fuente_ingreso_id')->constrained('fuentes_ingreso')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('bruto', 15, 2);
            $table->decimal('neto', 15, 2);
            $table->string('destino', 10); // efectivo | cuenta | ninguno
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->cascadeOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->text('comentario')->nullable();
            $table->timestamps();
            $table->index(['fuente_ingreso_id', 'fecha']);
        });

        Schema::create('ingreso_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingreso_id')->constrained('ingresos')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('tipo', 10); // descuento | adicion
            $table->decimal('monto', 15, 2);
            $table->foreignId('prestamo_pago_id')->nullable()->constrained('prestamo_pagos')->nullOnDelete();
            $table->timestamps();
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
        Schema::dropIfExists('ingreso_lineas');
        Schema::dropIfExists('ingresos');
        Schema::dropIfExists('fuente_ingreso_conceptos');
        Schema::dropIfExists('fuentes_ingreso');
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
