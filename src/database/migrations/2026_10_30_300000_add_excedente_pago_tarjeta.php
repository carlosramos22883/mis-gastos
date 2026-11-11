<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagar de más un estado de cuenta: lo que pase del saldo pendiente queda como un crédito en la tarjeta
 * (excedente_compromiso_id = el compromiso que se pagó). Si un corte queda en negativo (saldo a favor),
 * ese saldo pasa al corte siguiente (saldo_anterior, siempre ≤ 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->foreignId('excedente_compromiso_id')->nullable()->after('pago_compromiso_id')->constrained('compromisos')->nullOnDelete();
        });
        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->decimal('saldo_anterior', 15, 2)->default(0)->after('fecha_limite_pago');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_tarjeta', fn (Blueprint $table) => $table->dropConstrainedForeignId('excedente_compromiso_id'));
        Schema::table('estados_cuenta_tarjeta', fn (Blueprint $table) => $table->dropColumn('saldo_anterior'));
    }
};
