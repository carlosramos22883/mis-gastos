<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos fijos de una tarjeta (seguro, membresía…): se agregan solos a cada estado de cuenta al generarse el corte.
 * El movimiento que crean guarda cargo_fijo_id para no repetirlo en el mismo corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos_fijos_tarjeta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tarjeta_credito_id')->constrained('tarjetas_credito')->cascadeOnDelete();
            $table->foreignId('categoria_personal_id')->nullable()->constrained('categorias_personales')->nullOnDelete();
            $table->string('descripcion', 100);
            $table->decimal('monto', 15, 2);
            $table->date('desde'); // primer corte en el que se cobra
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('movimientos_tarjeta', function (Blueprint $table) {
            $table->foreignId('cargo_fijo_id')->nullable()->after('pago_compromiso_id')->constrained('cargos_fijos_tarjeta')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_tarjeta', fn (Blueprint $table) => $table->dropConstrainedForeignId('cargo_fijo_id'));
        Schema::dropIfExists('cargos_fijos_tarjeta');
    }
};
