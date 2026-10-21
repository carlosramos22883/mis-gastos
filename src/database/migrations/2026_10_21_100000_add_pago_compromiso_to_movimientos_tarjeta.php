<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pagar un compromiso (ej. el estado de cuenta de otra tarjeta vía NPE) cargándolo a una tarjeta
        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->foreignId('pago_compromiso_id')->nullable()->after('compromiso_id')->constrained('compromisos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_tarjeta', fn (Blueprint $table) => $table->dropConstrainedForeignId('pago_compromiso_id'));
    }
};
