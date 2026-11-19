<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Despensa, parte 1: los productos que el hogar compra siempre (nombre, marca, presentación, foto, código de barras…).
 * La categoría de despensa es un catálogo del administrador. Importancia, duración y unidad quedan fijas: el sistema
 * las usa para calcular (prioridad en la lista, fecha de vencimiento y precio por unidad).
 */
return new class extends Migration
{
    private const CATEGORIAS = [
        'Lácteos y huevos', 'Carnes y embutidos', 'Frutas y verduras', 'Panadería y tortillas', 'Granos y básicos',
        'Enlatados', 'Bebidas', 'Snacks y dulces', 'Condimentos y salsas', 'Congelados', 'Limpieza del hogar',
        'Higiene personal', 'Bebé', 'Mascotas', 'Otros',
    ];

    private const PERMISOS_DESPENSA = ['despensa.view', 'despensa.create', 'despensa.edit', 'despensa.delete'];

    private const PERMISOS_CATALOGO = ['categorias_despensa.view', 'categorias_despensa.create', 'categorias_despensa.edit', 'categorias_despensa.delete'];

    public function up(): void
    {
        Schema::create('categorias_despensa', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
        $ahora = now();
        foreach (self::CATEGORIAS as $orden => $nombre) {
            DB::table('categorias_despensa')->insert(['nombre' => $nombre, 'orden' => $orden, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 100);
            $table->string('marca', 80)->nullable();
            // Presentación: 140 g, 1 L, 12 unidades. Sirve para el precio por unidad al comparar paquetes distintos
            $table->decimal('cantidad', 10, 3)->nullable();
            $table->string('unidad', 10)->nullable(); // g | kg | lb | oz | ml | l | unidad
            $table->string('foto')->nullable(); // disco privado: solo la ven los miembros del hogar
            $table->string('codigo_barras', 14)->nullable();
            $table->foreignId('categoria_despensa_id')->constrained('categorias_despensa')->restrictOnDelete();
            $table->string('importancia', 10)->default('basico'); // basico | ocasional
            $table->string('duracion', 12)->default('duradero'); // perecedero | duradero
            $table->boolean('acepta_otra_marca')->default(false);
            $table->unsignedSmallInteger('cantidad_ideal')->nullable(); // cuántos tener en casa
            $table->unsignedSmallInteger('avisar_cuando')->nullable(); // pasa a la lista al quedar esta cantidad
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true); // archivado: sale de la lista sin perder su historial
            $table->timestamps();
            $table->index(['hogar_id', 'activo']);
            $table->index(['hogar_id', 'codigo_barras']);
        });

        foreach ([...self::PERMISOS_DESPENSA, ...self::PERMISOS_CATALOGO] as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        Role::where('name', 'Administrador')->first()?->givePermissionTo([...self::PERMISOS_DESPENSA, ...self::PERMISOS_CATALOGO]);
        Role::where('name', 'Usuario')->first()?->givePermissionTo(self::PERMISOS_DESPENSA);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias_despensa');
        Permission::whereIn('name', [...self::PERMISOS_DESPENSA, ...self::PERMISOS_CATALOGO])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
