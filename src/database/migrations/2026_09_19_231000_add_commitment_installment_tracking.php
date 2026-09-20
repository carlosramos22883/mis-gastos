<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos', function (Blueprint $table) {
            $table->unsignedInteger('cuotas_pagadas')->default(0)->after('recurrencia_ocurrencias');
            $table->decimal('saldo_pendiente', 15, 2)->nullable()->after('cuotas_pagadas');
            $table->boolean('recurrencia_indefinida')->default(false)->after('saldo_pendiente');
        });
    }

    public function down(): void
    {
        Schema::table('compromisos', function (Blueprint $table) {
            $table->dropColumn(['cuotas_pagadas', 'saldo_pendiente', 'recurrencia_indefinida']);
        });
    }
};
