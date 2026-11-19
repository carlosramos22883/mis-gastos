<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despensa, parte 4 (pasos 1 y 2): tiendas del hogar, historial de precios y, en la lista de compras, la tienda donde
 * se está comprando, el precio de cada cosa y «No había».
 *
 * "fuente" de una tienda = de dónde se pueden leer sus precios en línea (walmart, donjuan, selectos); nula = precio
 * anotado. Es un valor fijo porque cada fuente tiene su propio conector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 80);
            $table->string('fuente', 20)->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['hogar_id', 'nombre']);
        });

        Schema::table('listas_compra', function (Blueprint $table) {
            $table->foreignId('tienda_actual_id')->nullable()->after('nombre')->constrained('tiendas')->nullOnDelete();
        });
        Schema::table('lista_compra_items', function (Blueprint $table) {
            $table->decimal('precio', 15, 2)->nullable()->after('comprado'); // por unidad, en la tienda donde se compró
            $table->foreignId('tienda_id')->nullable()->after('precio')->constrained('tiendas')->nullOnDelete();
            $table->foreignId('no_habia_tienda_id')->nullable()->after('tienda_id')->constrained('tiendas')->nullOnDelete();
            $table->date('no_habia_el')->nullable()->after('no_habia_tienda_id');
        });

        Schema::create('precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('tienda_id')->constrained('tiendas')->cascadeOnDelete();
            $table->decimal('precio', 15, 2); // lo que cuesta como lo vende la tienda
            $table->unsignedSmallInteger('unidades')->default(1); // paquete de N del producto (precio por unidad = precio / unidades)
            $table->string('origen', 10); // anotado | compra | en_linea
            $table->date('fecha');
            // Precio que salió de una compra: si se corrige en la lista, se corrige aquí
            $table->foreignId('lista_compra_item_id')->nullable()->unique()->constrained('lista_compra_items')->nullOnDelete();
            $table->timestamps();
            $table->index(['producto_id', 'tienda_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precios');
        Schema::table('lista_compra_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('no_habia_tienda_id');
            $table->dropConstrainedForeignId('tienda_id');
            $table->dropColumn(['precio', 'no_habia_el']);
        });
        Schema::table('listas_compra', fn (Blueprint $table) => $table->dropConstrainedForeignId('tienda_actual_id'));
        Schema::dropIfExists('tiendas');
    }
};
