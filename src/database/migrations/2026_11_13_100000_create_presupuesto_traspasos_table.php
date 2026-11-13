<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuesto que pasa de una categoría a otra dentro del mismo ciclo (el tope del hogar no cambia): origen = el
 * presupuesto que cede, destino = el que recibe. Si lo pidió un gasto que se pasaba, queda ligado a ese movimiento:
 * al borrarlo o bajarle el monto, el presupuesto regresa. Sin movimiento = se movió a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presupuesto_traspasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('origen_id')->constrained('compromisos')->cascadeOnDelete();
            $table->foreignId('destino_id')->constrained('compromisos')->cascadeOnDelete();
            $table->decimal('monto', 15, 2);
            $table->foreignId('movimiento_efectivo_id')->nullable()->constrained('movimientos_efectivo')->cascadeOnDelete();
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('movimientos_cuenta')->cascadeOnDelete();
            $table->foreignId('movimiento_tarjeta_id')->nullable()->constrained('movimientos_tarjeta')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['destino_id', 'origen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presupuesto_traspasos');
    }
};
