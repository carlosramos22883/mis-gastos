<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los "tipos" descriptivos pasan de valores fijos en el código a catálogos que mantiene el administrador:
 * tipos de contacto, de emisor, de préstamo y de ingreso, y los conceptos de planilla (ISSS, AFP…).
 * Los valores que usa la lógica (ingreso/egreso, medio de pago, frecuencia…) siguen fijos.
 */
return new class extends Migration
{
    /** tabla del catálogo => [tabla que lo usa, columna de texto actual, [valor actual => nombre en el catálogo]] */
    private const TIPOS = [
        'tipos_contacto' => ['contactos', 'tipo', ['persona' => 'Persona', 'tienda' => 'Tienda', 'empresa' => 'Empresa', 'institucion' => 'Institución', 'mascota' => 'Mascota']],
        'tipos_emisor' => ['bancos', 'tipo', ['banco' => 'Banco', 'tienda' => 'Tienda', 'financiera' => 'Financiera', 'cooperativa' => 'Cooperativa']],
        'tipos_prestamo' => ['prestamos', 'tipo', ['consumo' => 'Consumo / personal', 'hipotecario' => 'Hipotecario', 'vehiculo' => 'Vehículo', 'persona' => 'De una persona', 'otro' => 'Otro']],
        'tipos_ingreso' => ['fuentes_ingreso', 'tipo', ['planilla' => 'Sueldo (planilla)', 'servicios' => 'Servicios profesionales', 'otro' => 'Otro']],
    ];

    private const COLUMNA = ['tipos_contacto' => 'tipo_contacto_id', 'tipos_emisor' => 'tipo_emisor_id', 'tipos_prestamo' => 'tipo_prestamo_id', 'tipos_ingreso' => 'tipo_ingreso_id'];

    private const PERMISOS = ['tipos_contacto', 'tipos_emisor', 'tipos_prestamo', 'tipos_ingreso', 'conceptos_planilla'];

    public function up(): void
    {
        $ahora = now();
        foreach (self::TIPOS as $catalogo => [$tabla, $columna, $valores]) {
            Schema::create($catalogo, function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 100)->unique();
                $table->string('descripcion', 255)->nullable();
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
            $orden = 0;
            $ids = [];
            foreach ($valores as $valor => $nombre) {
                $ids[$valor] = DB::table($catalogo)->insertGetId(['nombre' => $nombre, 'orden' => $orden++, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }

            $fk = self::COLUMNA[$catalogo];
            Schema::table($tabla, function (Blueprint $table) use ($fk, $catalogo) {
                $table->foreignId($fk)->nullable()->constrained($catalogo)->restrictOnDelete();
            });
            foreach ($ids as $valor => $id) {
                DB::table($tabla)->where($columna, $valor)->update([$fk => $id]);
            }
            DB::table($tabla)->whereNull($fk)->update([$fk => reset($ids)]); // cualquier valor desconocido: el primero
            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn($columna));
        }

        // Atajos de la plantilla de ingresos: valores de ley que cambian (ISSS, AFP…)
        Schema::create('conceptos_planilla', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('tipo', 10)->default('descuento'); // descuento | adicion (lo usa el cálculo del neto)
            $table->decimal('porcentaje', 6, 3)->nullable(); // sobre el bruto
            $table->decimal('tope', 15, 2)->nullable();
            $table->decimal('monto', 15, 2)->nullable();
            $table->string('descripcion', 255)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
        foreach ([
            ['ISSS', 'descuento', 3, 30, null, 'Seguro Social: 3 % del salario, máximo $30 al mes'],
            ['AFP', 'descuento', 7.25, null, null, 'Pensiones: 7.25 % del salario'],
            ['Renta', 'descuento', null, null, null, 'Retención de renta según la tabla de Hacienda'],
            ['Comisión por transferencia', 'descuento', null, null, null, 'Cargo del banco al recibir pagos del extranjero'],
            ['Bono', 'adicion', null, null, null, null],
        ] as $i => [$nombre, $tipo, $porcentaje, $tope, $monto, $descripcion]) {
            DB::table('conceptos_planilla')->insert(['nombre' => $nombre, 'tipo' => $tipo, 'porcentaje' => $porcentaje, 'tope' => $tope, 'monto' => $monto,
                'descripcion' => $descripcion, 'orden' => $i, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        $nombres = [];
        foreach (self::PERMISOS as $modulo) {
            foreach (['view', 'create', 'edit', 'delete'] as $accion) {
                $nombres[] = Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web'])->name;
            }
        }
        Role::where('name', 'Administrador')->first()?->givePermissionTo($nombres);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('conceptos_planilla');
        foreach (self::TIPOS as $catalogo => [$tabla, $columna, $valores]) {
            $fk = self::COLUMNA[$catalogo];
            Schema::table($tabla, fn (Blueprint $table) => $table->string($columna, 20)->nullable());
            foreach ($valores as $valor => $nombre) {
                $id = DB::table($catalogo)->where('nombre', $nombre)->value('id');
                DB::table($tabla)->where($fk, $id)->update([$columna => $valor]);
            }
            Schema::table($tabla, fn (Blueprint $table) => $table->dropConstrainedForeignId($fk));
            Schema::dropIfExists($catalogo);
        }
        foreach (self::PERMISOS as $modulo) {
            Permission::where('name', 'like', "{$modulo}.%")->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
