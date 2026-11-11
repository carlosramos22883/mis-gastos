<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuestos (súper, gasolina…): compromisos que no se pagan, se van gastando con los egresos de su categoría en
 * el ciclo. Tope de gasto del hogar por ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos_recurrentes', function (Blueprint $table) {
            $table->boolean('es_presupuesto')->default(false)->after('medio_pago');
        });
        Schema::table('compromisos', function (Blueprint $table) {
            $table->boolean('es_presupuesto')->default(false)->after('medio_pago');
        });
        Schema::table('hogares', function (Blueprint $table) {
            $table->decimal('tope_gasto_ciclo', 15, 2)->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('compromisos_recurrentes', fn (Blueprint $table) => $table->dropColumn('es_presupuesto'));
        Schema::table('compromisos', fn (Blueprint $table) => $table->dropColumn('es_presupuesto'));
        Schema::table('hogares', fn (Blueprint $table) => $table->dropColumn('tope_gasto_ciclo'));
    }
};
