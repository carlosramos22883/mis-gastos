<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras en cuotas: día del mes en que se cobra cada cuota (31 = último día). La primera es la de ese día en o
 * después de la compra; cada cuota entra en el corte que contiene su fecha. Sin día (las ya registradas), una por corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->unsignedTinyInteger('dia_cuota')->nullable()->after('cuotas');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_tarjeta', fn (Blueprint $table) => $table->dropColumn('dia_cuota'));
    }
};
