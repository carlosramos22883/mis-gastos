<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despensa, parte 4 (paso 3): «Pagar aquí». Un pago es lo que se pagó en una tienda de una lista: un total, de dónde
 * salió (efectivo, una cuenta o una tarjeta) y uno o varios gastos, uno por categoría (lo de la lista en una y los
 * extras en la suya). Las cosas pagadas quedan ligadas al pago; los extras detallados quedan en la lista ("extra").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lista_compra_id')->constrained('listas_compra')->cascadeOnDelete();
            $table->foreignId('tienda_id')->nullable()->constrained('tiendas')->nullOnDelete();
            $table->string('origen', 30); // efectivo | cuenta:{id} | tarjeta:{id}
            $table->decimal('total', 15, 2);
            $table->date('fecha');
            $table->timestamps();
        });

        // Un gasto del pago (uno por categoría), ligado a su movimiento: mientras exista, el gasto se gestiona desde la lista
        Schema::create('compra_pago_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_pago_id')->constrained('compras_pagos')->cascadeOnDelete();
            $table->foreignId('categoria_personal_id')->nullable()->constrained('categorias_personales')->nullOnDelete();
            $table->decimal('monto', 15, 2);
            $table->foreignId('movimiento_efectivo_id')->nullable()->unique()->constrained('movimientos_efectivo')->nullOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->unique()->constrained('movimientos_cuenta')->nullOnDelete();
            $table->foreignId('movimiento_tarjeta_id')->nullable()->unique()->constrained('movimientos_tarjeta')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('lista_compra_items', function (Blueprint $table) {
            $table->foreignId('compra_pago_id')->nullable()->after('no_habia_el')->constrained('compras_pagos')->nullOnDelete();
            $table->boolean('extra')->default(false)->after('compra_pago_id'); // lo agregó el pago (se borra al deshacerlo)
        });
    }

    public function down(): void
    {
        Schema::table('lista_compra_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compra_pago_id');
            $table->dropColumn('extra');
        });
        Schema::dropIfExists('compra_pago_movimientos');
        Schema::dropIfExists('compras_pagos');
    }
};
