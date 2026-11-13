<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Producto compartido de un evento (ej. una bandeja para todos): su precio es el del producto completo y se divide
 * entre quienes comieron de él, según las porciones de cada uno (en la celda; 1 = partes iguales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_consumos', function (Blueprint $table) {
            $table->boolean('compartido')->default(false)->after('precio');
        });
    }

    public function down(): void
    {
        Schema::table('evento_consumos', function (Blueprint $table) {
            $table->dropColumn('compartido');
        });
    }
};
