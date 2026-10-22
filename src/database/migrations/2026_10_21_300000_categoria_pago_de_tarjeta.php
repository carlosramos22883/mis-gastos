<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los pagos a estados de cuenta de tarjeta que se registraron sin categoría pasan a "Pago de tarjeta"
 * (no cuenta como gasto: las compras de la tarjeta ya tienen la suya). La categoría se crea por hogar si hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $compromisos = DB::table('compromisos')->whereNotNull('tarjeta_credito_id')->pluck('id');
        if ($compromisos->isEmpty()) {
            return;
        }

        $pendientes = collect()
            ->concat(DB::table('movimientos_efectivo')->whereIn('compromiso_id', $compromisos)->whereNull('categoria_personal_id')->get(['id', 'hogar_id', 'user_id'])->map(fn ($m) => ['movimientos_efectivo', $m]))
            ->concat(DB::table('movimientos_cuenta')->whereIn('compromiso_id', $compromisos)->whereNull('categoria_personal_id')->get(['id', 'hogar_id', 'user_id'])->map(fn ($m) => ['movimientos_cuenta', $m]))
            ->concat(DB::table('movimientos_tarjeta')->whereIn('pago_compromiso_id', $compromisos)->whereNull('categoria_personal_id')->get(['id', 'hogar_id', 'user_id'])->map(fn ($m) => ['movimientos_tarjeta', $m]));

        $categorias = [];
        foreach ($pendientes as [$tabla, $m]) {
            $categorias[$m->hogar_id] ??= DB::table('categorias_personales')->where('hogar_id', $m->hogar_id)->where('nombre', 'Pago de tarjeta')->where('tipo', 'egreso')->value('id')
                ?? DB::table('categorias_personales')->insertGetId(['hogar_id' => $m->hogar_id, 'user_id' => $m->user_id, 'nombre' => 'Pago de tarjeta', 'tipo' => 'egreso',
                    'color' => '#475569', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table($tabla)->where('id', $m->id)->update(['categoria_personal_id' => $categorias[$m->hogar_id]]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: la categoría solo ordena el historial
    }
};
