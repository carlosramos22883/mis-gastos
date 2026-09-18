<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categorias_personales', 'color')) {
            Schema::table('categorias_personales', function (Blueprint $table) {
                $table->string('color', 7)->default('#64748B')->after('tipo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categorias_personales', 'color')) {
            Schema::table('categorias_personales', function (Blueprint $table) {
                $table->dropColumn('color');
            });
        }
    }
};
