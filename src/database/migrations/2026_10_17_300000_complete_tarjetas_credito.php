<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarjetas completas: la deuda se calcula a partir de los movimientos y de lo pagado en sus
 * estados de cuenta; cada corte guarda su detalle (incluidas las cuotas de compras a tasa cero)
 * y genera un compromiso de pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->renameColumn('saldo_actual', 'deuda_inicial'); // estado de cuenta pendiente al registrarla
        });
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->string('color', 7)->default('#1E293B')->after('ultimos_digitos');
            $table->decimal('tasa_interes', 6, 2)->nullable()->after('limite_credito');
            $table->date('fecha_registro')->nullable()->after('dia_pago');
        });

        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->dropColumn(['saldo_anterior', 'pagos', 'saldo_final', 'estado']);
        });
        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->renameColumn('consumos', 'total');
        });

        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estado_cuenta_tarjeta_id');
            $table->dropColumn('cuota_numero');
        });
        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->foreignId('categoria_personal_id')->nullable()->after('tarjeta_credito_id')->constrained('categorias_personales')->restrictOnDelete();
            $table->foreignId('transferencia_id')->nullable()->after('compromiso_id')->constrained('transferencias')->cascadeOnDelete();
            $table->text('comentario')->nullable()->after('descripcion');
        });

        // Detalle de cada estado de cuenta: un movimiento completo o una cuota de una compra a plazos
        Schema::create('estado_cuenta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estado_cuenta_tarjeta_id')->constrained('estados_cuenta_tarjeta')->cascadeOnDelete();
            $table->foreignId('movimiento_tarjeta_id')->constrained('movimientos_tarjeta')->restrictOnDelete();
            $table->unsignedSmallInteger('cuota_numero')->nullable();
            $table->decimal('monto', 15, 2);
            $table->timestamps();
            $table->index('movimiento_tarjeta_id');
        });

        Schema::table('compromisos', function (Blueprint $table) {
            $table->foreignId('tarjeta_credito_id')->nullable()->after('categoria_personal_id')->constrained('tarjetas_credito')->nullOnDelete();
            $table->foreignId('estado_cuenta_tarjeta_id')->nullable()->after('tarjeta_credito_id')->constrained('estados_cuenta_tarjeta')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compromisos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estado_cuenta_tarjeta_id');
            $table->dropConstrainedForeignId('tarjeta_credito_id');
        });
        Schema::dropIfExists('estado_cuenta_items');
        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferencia_id');
            $table->dropConstrainedForeignId('categoria_personal_id');
            $table->dropColumn('comentario');
            $table->unsignedSmallInteger('cuota_numero')->nullable();
            $table->foreignId('estado_cuenta_tarjeta_id')->nullable()->constrained('estados_cuenta_tarjeta')->nullOnDelete();
        });
        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->renameColumn('total', 'consumos');
        });
        Schema::table('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->decimal('saldo_anterior', 15, 2)->default(0);
            $table->decimal('pagos', 15, 2)->default(0);
            $table->decimal('saldo_final', 15, 2)->default(0);
            $table->string('estado', 20)->default('abierto');
        });
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->dropColumn(['color', 'tasa_interes', 'fecha_registro']);
        });
        Schema::table('tarjetas_credito', function (Blueprint $table) {
            $table->renameColumn('deuda_inicial', 'saldo_actual');
        });
    }
};
