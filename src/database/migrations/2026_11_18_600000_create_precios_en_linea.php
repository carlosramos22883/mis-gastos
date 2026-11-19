<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despensa, parte 4: precios en línea (Walmart, La Despensa de Don Juan y Súper Selectos).
 *
 *  - productos_en_linea: cada producto se liga una vez con el suyo en cada fuente (código de la tienda, nombre, enlace
 *    y "unidades" si allá se vende en paquete). El precio leído pasa a "precios" con origen en_linea.
 *  - consultas_precios: una consulta de los productos de una lista, hecha de uno en uno (avance "hechos de total").
 *    Sirve para el límite por hogar al día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_en_linea', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('fuente', 20); // walmart | donjuan | selectos (Tienda::FUENTES)
            $table->string('codigo', 40); // id del producto en la tienda
            $table->string('nombre', 200);
            $table->string('url', 500)->nullable();
            $table->string('imagen', 500)->nullable();
            $table->unsignedSmallInteger('unidades')->default(1); // allá se vende en paquete de N del producto
            $table->timestamp('consultado_at')->nullable();
            $table->string('error', 20)->nullable(); // no_encontrado | sin_precio (última consulta)
            $table->timestamps();
            $table->unique(['producto_id', 'fuente']);
        });

        Schema::create('consultas_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lista_compra_id')->nullable()->constrained('listas_compra')->nullOnDelete();
            $table->date('dia'); // en la zona del usuario: el límite es por día
            $table->json('productos'); // ids a consultar, en orden
            $table->unsignedSmallInteger('hechos')->default(0);
            $table->unsignedSmallInteger('nuevos')->default(0); // precios leídos
            $table->unsignedSmallInteger('reusados')->default(0); // ya consultados hoy hace menos de 12 horas
            $table->unsignedSmallInteger('fallas')->default(0);
            $table->timestamp('terminada_at')->nullable();
            $table->timestamps();
            $table->index(['hogar_id', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas_precios');
        Schema::dropIfExists('productos_en_linea');
    }
};
