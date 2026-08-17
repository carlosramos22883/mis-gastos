<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('moneda_preferida')->default('USD')->after('email');
            $table->integer('fecha_corte_dia')->default(31)->after('moneda_preferida');
            $table->string('zona_horaria')->default('America/El_Salvador')->after('fecha_corte_dia');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['moneda_preferida', 'fecha_corte_dia', 'zona_horaria']);
        });
    }
};
