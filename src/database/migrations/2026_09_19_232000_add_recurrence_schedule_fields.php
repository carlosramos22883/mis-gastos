<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos', function (Blueprint $table) {
            $table->unsignedTinyInteger('recurrencia_dia_semana')->nullable()->after('recurrencia_unidad');
            $table->unsignedTinyInteger('recurrencia_dia_secundario')->nullable()->after('recurrencia_dia_semana');
        });
    }

    public function down(): void
    {
        Schema::table('compromisos', function (Blueprint $table) {
            $table->dropColumn(['recurrencia_dia_semana', 'recurrencia_dia_secundario']);
        });
    }
};
