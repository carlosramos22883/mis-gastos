<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cuentas bancarias y billeteras digitales, sus movimientos y las transferencias
 * entre efectivo, cuentas y tarjetas (cada transferencia crea sus dos movimientos).
 */
return new class extends Migration
{
    private const PERMISOS = ['cuentas.view', 'cuentas.create', 'cuentas.edit', 'cuentas.delete', 'cuentas.export'];

    public function up(): void
    {
        if (! DB::table('tipo_cuentas')->where('nombre', 'Billetera digital')->exists()) {
            DB::table('tipo_cuentas')->insert([
                'nombre' => 'Billetera digital', 'descripcion' => 'Chivo, Niu, Tigo Money y similares', 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banco_id')->nullable()->constrained('bancos')->nullOnDelete();
            $table->foreignId('tipo_cuenta_id')->nullable()->constrained('tipo_cuentas')->nullOnDelete();
            $table->string('nombre', 100);
            $table->text('numero_cuenta')->nullable(); // encriptado
            $table->string('color', 7)->default('#0284C7');
            $table->decimal('saldo_inicial', 15, 2)->default(0);
            $table->date('fecha_inicial');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'activo']);
        });

        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('origen_tipo', 10);   // efectivo | cuenta | tarjeta
            $table->foreignId('origen_cuenta_id')->nullable()->constrained('cuentas')->restrictOnDelete();
            $table->foreignId('origen_tarjeta_id')->nullable()->constrained('tarjetas_credito')->restrictOnDelete();
            $table->string('destino_tipo', 10);  // efectivo | cuenta
            $table->foreignId('destino_cuenta_id')->nullable()->constrained('cuentas')->restrictOnDelete();
            $table->string('descripcion', 255);
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->timestamps();
            $table->index(['user_id', 'fecha']);
        });

        Schema::create('movimientos_cuenta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();
            $table->foreignId('categoria_personal_id')->nullable()->constrained('categorias_personales')->restrictOnDelete();
            $table->foreignId('compromiso_id')->nullable()->constrained('compromisos')->nullOnDelete();
            $table->foreignId('transferencia_id')->nullable()->constrained('transferencias')->cascadeOnDelete();
            $table->string('descripcion', 255);
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->string('tipo', 20); // ingreso | egreso
            $table->timestamps();
            $table->index(['cuenta_id', 'fecha']);
        });

        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->foreignId('transferencia_id')->nullable()->after('compromiso_cuota')->constrained('transferencias')->cascadeOnDelete();
        });
        // Los movimientos de una transferencia no llevan categoría
        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->unsignedBigInteger('categoria_personal_id')->nullable()->change();
        });

        foreach (self::PERMISOS as $nombre) {
            Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
        }
        Role::where('name', 'Administrador')->first()?->givePermissionTo(self::PERMISOS);
        Role::where('name', 'Usuario')->first()?->givePermissionTo(self::PERMISOS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferencia_id');
        });
        Schema::dropIfExists('movimientos_cuenta');
        Schema::dropIfExists('transferencias');
        Schema::dropIfExists('cuentas');
        Permission::whereIn('name', self::PERMISOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
