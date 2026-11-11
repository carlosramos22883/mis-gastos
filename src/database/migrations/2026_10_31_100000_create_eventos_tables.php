<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Eventos (dividir la cuenta de una comida o celebración), como una tabla: participantes en las filas (un miembro del
 * hogar o un contacto, festejados o no), productos con su precio en las columnas y en cada celda cuántos pidió cada uno.
 * Además la propina y, opcionalmente, la foto de la cuenta. Si pagó el hogar, el pago es un
 * movimiento del efectivo, una cuenta o una tarjeta y lo de cada contacto queda como una deuda ("Nos deben").
 */
return new class extends Migration
{
    private const PERMISOS = ['eventos.view', 'eventos.create', 'eventos.edit', 'eventos.delete'];

    public function up(): void
    {
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 150);
            $table->date('fecha');
            $table->string('lugar', 150)->nullable();
            $table->text('comentario')->nullable();
            $table->string('foto')->nullable(); // foto de la cuenta (disco privado)
            $table->string('propina_tipo', 12)->default('ninguna'); // ninguna | porcentaje | monto
            $table->decimal('propina_valor', 15, 2)->default(0);
            // Pago de la cuenta (si pagó el hogar): de dónde salió y su movimiento
            $table->string('origen', 12)->nullable(); // efectivo | cuenta | tarjeta | ninguno
            $table->foreignId('categoria_personal_id')->nullable()->constrained('categorias_personales')->nullOnDelete();
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->nullOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->nullOnDelete();
            $table->foreignId('movimiento_tarjeta_id')->nullable()->constrained('movimientos_tarjeta')->nullOnDelete();
            $table->timestamps();
            $table->index(['hogar_id', 'fecha']);
        });

        Schema::create('evento_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // miembro del hogar
            $table->foreignId('contacto_id')->nullable()->constrained('contactos')->restrictOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('es_festejado')->default(false);
            $table->boolean('es_pagador')->default(false);
            $table->foreignId('deuda_id')->nullable()->constrained('deudas')->nullOnDelete(); // pagó el hogar: lo que nos debe
            // Pagó otra persona: si ya le pagaron (y, si es del hogar, el movimiento con que le pagó)
            $table->date('fecha_pago')->nullable();
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->nullOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->nullOnDelete();
            $table->foreignId('movimiento_tarjeta_id')->nullable()->constrained('movimientos_tarjeta')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('evento_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->string('descripcion', 150);
            $table->decimal('precio', 15, 2); // precio unitario
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Cuántos pidió cada participante de cada producto (las celdas de la tabla)
        Schema::create('evento_consumo_participante', function (Blueprint $table) {
            $table->foreignId('evento_consumo_id')->constrained('evento_consumos')->cascadeOnDelete();
            $table->foreignId('evento_participante_id')->constrained('evento_participantes')->cascadeOnDelete();
            $table->unsignedSmallInteger('cantidad');
            $table->primary(['evento_consumo_id', 'evento_participante_id']);
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
        Schema::dropIfExists('evento_consumo_participante');
        Schema::dropIfExists('evento_consumos');
        Schema::dropIfExists('evento_participantes');
        Schema::dropIfExists('eventos');
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
