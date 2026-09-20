<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compromisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('descripcion', 255);
            $table->decimal('monto', 15, 2);
            $table->date('fecha_esperada')->nullable();
            $table->string('medio_pago', 30)->default('por_definir');
            $table->string('recurrencia_tipo', 20)->default('unica');
            $table->unsignedInteger('recurrencia_intervalo')->nullable();
            $table->string('recurrencia_unidad', 20)->nullable();
            $table->unsignedInteger('recurrencia_ocurrencias')->nullable();
            $table->date('recurrencia_hasta')->nullable();
            $table->string('estado', 20)->default('activo');
            $table->timestamps();
            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compromisos');
    }
};
