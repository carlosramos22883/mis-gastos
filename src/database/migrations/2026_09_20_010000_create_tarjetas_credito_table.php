<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarjetas_credito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banco_id')->nullable()->constrained('bancos')->nullOnDelete();
            $table->foreignId('marca_red_id')->nullable()->constrained('marca_reds')->nullOnDelete();
            $table->string('nombre', 100);
            $table->string('ultimos_digitos', 4)->nullable();
            $table->decimal('limite_credito', 15, 2)->default(0);
            $table->decimal('saldo_actual', 15, 2)->default(0);
            $table->unsignedTinyInteger('dia_corte')->nullable();
            $table->unsignedTinyInteger('dia_pago')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarjetas_credito');
    }
};
