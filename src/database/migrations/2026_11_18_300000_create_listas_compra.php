<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despensa, parte 3: listas de compras (una por viaje). Se arma sola con lo que falta y el usuario quita, agrega o
 * anota cosas fuera del catálogo. Al terminarla, lo comprado sube en la despensa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 100);
            $table->timestamp('terminada_at')->nullable(); // nula = abierta (solo una abierta por hogar)
            $table->timestamps();
            $table->index(['hogar_id', 'terminada_at']);
        });

        Schema::create('lista_compra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_compra_id')->constrained('listas_compra')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete(); // nulo: anotado fuera del catálogo
            $table->string('descripcion', 200); // copia del nombre al agregarlo (queda aunque se elimine el producto)
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->boolean('automatico')->default(false); // lo agregó la app porque faltaba
            $table->boolean('comprado')->default(false);
            $table->timestamps();
            $table->unique(['lista_compra_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lista_compra_items');
        Schema::dropIfExists('listas_compra');
    }
};
