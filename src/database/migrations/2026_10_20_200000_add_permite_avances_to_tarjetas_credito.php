<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las tarjetas de tienda (ej. Credisiman) no sirven para avances ni recargas a billeteras
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->boolean('permite_avances')->default(true)->after('tasa_interes');
        });
    }

    public function down(): void
    {
        Schema::table('tarjetas_credito', fn (Blueprint $table) => $table->dropColumn('permite_avances'));
    }
};
