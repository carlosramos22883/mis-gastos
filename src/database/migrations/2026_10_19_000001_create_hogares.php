<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas cuyos registros pasan a ser del hogar; user_id queda como "quién lo hizo" (o el titular). */
    private const TABLAS = [
        'categorias_personales', 'movimientos_efectivo', 'compromisos_recurrentes', 'compromisos',
        'cuentas', 'transferencias', 'movimientos_cuenta', 'tarjetas_credito', 'movimientos_tarjeta',
    ];

    public function up(): void
    {
        Schema::create('hogares', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // administrador
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('hogar_id')->nullable()->after('id')->constrained('hogares')->nullOnDelete();
        });

        Schema::create('hogar_invitaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hogar_id')->constrained('hogares')->cascadeOnDelete();
            $table->foreignId('invitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('estado', 20)->default('pendiente'); // pendiente | aceptada | rechazada | cancelada
            $table->timestamp('respondida_en')->nullable();
            $table->timestamps();
            $table->index(['email', 'estado']);
        });

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $user) {
            $hogar = DB::table('hogares')->insertGetId([
                'nombre' => 'Hogar de '.$user->name, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $user->id)->update(['hogar_id' => $hogar]);
        }

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('hogar_id')->nullable()->after('id')->constrained('hogares')->cascadeOnDelete();
            });
            DB::table($tabla)->update([
                'hogar_id' => DB::raw("(SELECT hogar_id FROM users WHERE users.id = {$tabla}.user_id)"),
            ]);
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('hogar_id')->nullable(false)->change();
            });
        }

        Schema::table('categorias_personales', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'nombre', 'tipo']);
            $table->unique(['hogar_id', 'nombre', 'tipo']);
            $table->index(['hogar_id', 'tipo', 'activo']);
        });
        Schema::table('movimientos_efectivo', fn (Blueprint $table) => $table->index(['hogar_id', 'fecha']));
        Schema::table('compromisos', fn (Blueprint $table) => $table->index(['hogar_id', 'estado', 'ciclo_inicio']));
        Schema::table('transferencias', fn (Blueprint $table) => $table->index(['hogar_id', 'fecha']));
    }

    public function down(): void
    {
        Schema::table('categorias_personales', function (Blueprint $table) {
            $table->dropUnique(['hogar_id', 'nombre', 'tipo']);
            $table->dropIndex(['hogar_id', 'tipo', 'activo']);
            $table->unique(['user_id', 'nombre', 'tipo']);
        });
        Schema::table('movimientos_efectivo', fn (Blueprint $table) => $table->dropIndex(['hogar_id', 'fecha']));
        Schema::table('compromisos', fn (Blueprint $table) => $table->dropIndex(['hogar_id', 'estado', 'ciclo_inicio']));
        Schema::table('transferencias', fn (Blueprint $table) => $table->dropIndex(['hogar_id', 'fecha']));

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropConstrainedForeignId('hogar_id'));
        }

        Schema::dropIfExists('hogar_invitaciones');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('hogar_id'));
        Schema::dropIfExists('hogares');
    }
};
