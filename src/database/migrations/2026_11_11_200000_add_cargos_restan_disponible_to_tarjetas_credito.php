<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hay bancos (Davivienda) que solo restan del disponible el capital: los cargos del banco (intereses, comisiones, IVA)
 * no lo bajan hasta que se pagan. false = la tarjeta funciona así.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->boolean('cargos_restan_disponible')->default(true)->after('permite_avances');
        });
    }

    public function down(): void
    {
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->dropColumn('cargos_restan_disponible');
        });
    }
};
