<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cambiamos string por foreignId para crear la relación real
            $table->foreignId('moneda_preferida')
                ->nullable()
                ->after('email')
                ->constrained('monedas')
                ->nullOnDelete(); // Si se borra la moneda, se pone en null en lugar de romper todo

            $table->integer('fecha_corte_dia')->default(31)->after('moneda_preferida');
            $table->string('zona_horaria')->default('America/El_Salvador')->after('fecha_corte_dia');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['moneda_preferida']);
            $table->dropColumn(['moneda_preferida', 'fecha_corte_dia', 'zona_horaria']);
        });
    }
};
