<?php

namespace App\Support;

use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\Ingreso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\PrestamoPago;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimientos de efectivo, cuenta o tarjeta que nacieron en otro módulo (un préstamo a un deudor, el pago
 * de un deudor o el pago de un préstamo). Se modifican desde ese módulo, no desde su pantalla.
 */
class MovimientoVinculado
{
    /** Nombre del módulo que gestiona el movimiento, o null si es un movimiento propio. */
    public static function modulo(Model $movimiento): ?string
    {
        $columna = match (true) {
            $movimiento instanceof MovimientoEfectivo => 'movimiento_efectivo_id',
            $movimiento instanceof MovimientoCuenta => 'movimiento_cuenta_id',
            $movimiento instanceof MovimientoTarjeta => 'movimiento_tarjeta_id',
        };

        if (Deuda::where($columna, $movimiento->id)->exists()) {
            return 'Deudores';
        }
        if ($columna === 'movimiento_tarjeta_id') {
            return null;
        }
        if (DeudaPago::where($columna, $movimiento->id)->exists()) {
            return 'Deudores';
        }

        if (PrestamoPago::where($columna, $movimiento->id)->exists()) {
            return 'Préstamos';
        }

        return Ingreso::where($columna, $movimiento->id)->exists() ? 'Ingresos' : null;
    }

    public static function asegurarLibre(Model $movimiento): void
    {
        $modulo = self::modulo($movimiento);
        abort_if($modulo !== null, 403, "Este movimiento se gestiona desde {$modulo}.");
    }
}
