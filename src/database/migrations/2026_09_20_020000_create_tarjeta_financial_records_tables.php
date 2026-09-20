<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados_cuenta_tarjeta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarjeta_credito_id')->constrained('tarjetas_credito')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_corte');
            $table->date('fecha_limite_pago')->nullable();
            $table->decimal('saldo_anterior', 15, 2)->default(0);
            $table->decimal('consumos', 15, 2)->default(0);
            $table->decimal('pagos', 15, 2)->default(0);
            $table->decimal('saldo_final', 15, 2)->default(0);
            $table->string('estado', 20)->default('abierto');
            $table->timestamps();
            $table->unique(['tarjeta_credito_id', 'fecha_corte']);
        });

        Schema::create('movimientos_tarjeta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tarjeta_credito_id')->constrained('tarjetas_credito')->cascadeOnDelete();
            $table->foreignId('estado_cuenta_tarjeta_id')->nullable()->constrained('estados_cuenta_tarjeta')->nullOnDelete();
            $table->foreignId('compromiso_id')->nullable()->constrained('compromisos')->nullOnDelete();
            $table->string('tipo', 20);
            $table->string('descripcion');
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->unsignedSmallInteger('cuotas')->nullable();
            $table->unsignedSmallInteger('cuota_numero')->nullable();
            $table->timestamps();
            $table->index(['tarjeta_credito_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_tarjeta');
        Schema::dropIfExists('estados_cuenta_tarjeta');
    }
};
