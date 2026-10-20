<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El catálogo de bancos pasa a ser de emisores: también tiendas (Credisiman), financieras y cooperativas
        Schema::table('bancos', function (Blueprint $table) {
            $table->string('tipo', 20)->default('banco')->after('nombre'); // banco | tienda | financiera | cooperativa
        });
    }

    public function down(): void
    {
        Schema::table('bancos', fn (Blueprint $table) => $table->dropColumn('tipo'));
    }
};
