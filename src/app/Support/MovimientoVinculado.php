<?php

namespace App\Support;

use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\Evento;
use App\Models\EventoParticipante;
use App\Models\Ingreso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\PrestamoPago;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimientos de efectivo, cuenta o tarjeta que nacieron en otro módulo (un préstamo a un deudor, el pago
 * de un deudor, el pago de un préstamo, el pago de un evento o lo pagado de más en un compromiso). Se modifican desde ese módulo, no desde su pantalla.
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
        if (Evento::where($columna, $movimiento->id)->exists() || EventoParticipante::where($columna, $movimiento->id)->exists()) {
            return 'Eventos';
        }
        if ($columna === 'movimiento_tarjeta_id') {
            return $movimiento->excedente_compromiso_id ? 'Compromisos' : null; // lo pagado de más sigue a su pago
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
