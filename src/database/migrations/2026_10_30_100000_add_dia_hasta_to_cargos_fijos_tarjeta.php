<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los cargos fijos pasan a cobrarse un día del mes (como Netflix el 15) entre una fecha de inicio y una de fin opcional:
 * el movimiento se crea ese día y descuenta el disponible desde ya. "ultimo_cobro" evita repetirlo (aunque se borre
 * el movimiento) y, al cambiar el día, que se cobre dos veces el mismo mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos_fijos_tarjeta', function (Blueprint $table) {
            $table->unsignedTinyInteger('dia')->nullable()->after('monto'); // 31 = último día del mes
            $table->date('hasta')->nullable()->after('desde');
            $table->date('ultimo_cobro')->nullable()->after('hasta');
        });

        // Los que ya existen: el día de su último cobro registrado o, si no tienen, el de su fecha de inicio
        foreach (DB::table('cargos_fijos_tarjeta')->get() as $cargo) {
            $ultimo = DB::table('movimientos_tarjeta')->where('cargo_fijo_id', $cargo->id)->max('fecha');
            DB::table('cargos_fijos_tarjeta')->where('id', $cargo->id)->update([
                'dia' => (int) substr($ultimo ?? $cargo->desde, 8, 2),
                'ultimo_cobro' => $ultimo ? substr($ultimo, 0, 10) : null,
            ]);
        }

        Schema::table('cargos_fijos_tarjeta', fn (Blueprint $table) => $table->unsignedTinyInteger('dia')->nullable(false)->change());
    }

    public function down(): void
    {
        Schema::table('cargos_fijos_tarjeta', fn (Blueprint $table) => $table->dropColumn(['dia', 'hasta', 'ultimo_cobro']));
    }
};
