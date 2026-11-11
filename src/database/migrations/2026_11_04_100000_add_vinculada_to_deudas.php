<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deuda ligada a un movimiento que ya estaba registrado (ej. una compra con la tarjeta que hizo otra persona).
 * Al eliminarla el movimiento se queda y recupera la categoría que tenía (categoria_original_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deudas', function (Blueprint $table) {
            $table->boolean('vinculada')->default(false)->after('origen');
            $table->foreignId('categoria_original_id')->nullable()->after('vinculada')->constrained('categorias_personales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deudas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_original_id');
            $table->dropColumn('vinculada');
        });
    }
};
