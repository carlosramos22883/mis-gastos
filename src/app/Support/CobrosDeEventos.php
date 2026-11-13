<?php

namespace App\Support;

use App\Models\Evento;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use Illuminate\Support\Collection;

/**
 * Si el hogar pagó la cuenta de un evento, su gasto (un solo movimiento por el total) se cuenta en la categoría
 * completo mientras los contactos no paguen su parte. Lo que ya se les cobró se descuenta de ese movimiento: al final
 * la categoría (presupuestos, tope del ciclo y reportes) solo carga lo de los miembros del hogar. El cobro descuenta
 * el ciclo del gasto, no el del día en que pagaron. Es solo para leer: el movimiento no se guarda con el monto ajustado.
 */
class CobrosDeEventos
{
    private const COLUMNAS = [MovimientoEfectivo::class => 'movimiento_efectivo_id', MovimientoCuenta::class => 'movimiento_cuenta_id',
        MovimientoTarjeta::class => 'movimiento_tarjeta_id'];

    /**
     * Baja el monto de los movimientos que pagan un evento en lo ya cobrado a los contactos. Cada uno ajustado lleva
     * "monto_original" y "cobrado".
     *
     * @param  Collection<int, MovimientoEfectivo|MovimientoCuenta|MovimientoTarjeta>  $movimientos
     * @return Collection<int, MovimientoEfectivo|MovimientoCuenta|MovimientoTarjeta>
     */
    public static function descontar(Collection $movimientos): Collection
    {
        foreach (self::COLUMNAS as $clase => $columna) {
            $ids = $movimientos->filter(fn ($m) => $m instanceof $clase)->pluck('id')->all();
            if (! $ids) {
                continue;
            }
            $cobrado = Evento::whereIn($columna, $ids)->with(['participantes.deuda' => fn ($q) => $q->withSum('pagos', 'monto')])->get()
                ->mapWithKeys(fn (Evento $e) => [$e->{$columna} => round((float) $e->participantes->sum(fn ($p) => $p->deuda?->pagado() ?? 0), 2)])
                ->filter(fn ($monto) => $monto > 0);
            $movimientos->filter(fn ($m) => $m instanceof $clase && isset($cobrado[$m->id]))->each(function ($m) use ($cobrado) {
                $original = (float) $m->monto;
                $m->setAttribute('monto_original', $original)->setAttribute('cobrado', min($original, $cobrado[$m->id]))
                    ->setAttribute('monto', round(max(0, $original - $cobrado[$m->id]), 2));
            });
        }

        return $movimientos;
    }
}
