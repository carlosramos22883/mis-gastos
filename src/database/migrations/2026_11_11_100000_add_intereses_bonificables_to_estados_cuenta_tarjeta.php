<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intereses bonificables de un corte (así los llama BAC): el banco los calcula al corte y los aparta del disponible;
 * hasta el corte siguiente, donde los abona si se pagó el saldo de contado a tiempo (o los cobra si no).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->decimal('intereses_bonificables', 15, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->dropColumn('intereses_bonificables');
        });
    }
};
