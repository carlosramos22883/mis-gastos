<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * "Pagar" un compromiso (completo o un abono) desde efectivo, una cuenta o una tarjeta de crédito.
 *  - Efectivo o cuenta: un egreso ligado al compromiso (al eliminarlo, el monto vuelve a quedar pendiente).
 *  - Tarjeta: una compra en esa tarjeta (ej. pagar el estado de cuenta de otra tarjeta con su NPE).
 * El pago de una tarjeta lleva la categoría "Pago de tarjeta" (no cuenta como gasto: sus compras ya lo son).
 */
class PagoCompromisoService
{
    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
        private readonly MovimientoEfectivoService $efectivo,
    ) {}

    /** Desde dónde puede pagar el usuario (según sus permisos), con el saldo o disponible de cada opción. */
    public function origenes(User $user, Compromiso $compromiso): array
    {
        $origenes = [];
        if ($user->can('efectivo.create')) {
            $origenes['efectivo'] = 'Efectivo ($ '.number_format($this->ciclos->balance($user), 2).')';
        }
        if ($user->can('cuentas.create')) {
            foreach ($user->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
                $origenes['cuenta:'.$cuenta->id] = $cuenta->nombre.' ($ '.number_format($this->cuentas->saldo($cuenta), 2).')';
            }
        }
        if ($user->can('tarjetas.create')) {
            $tarjetas = $user->tarjetasCredito()->where('activo', true)->where('id', '!=', (int) $compromiso->tarjeta_credito_id)->orderBy('nombre')->get();
            foreach ($tarjetas as $tarjeta) {
                $origenes['tarjeta:'.$tarjeta->id] = 'Tarjeta '.$tarjeta->nombre.' (disponible $ '.number_format($this->tarjetas->disponible($tarjeta), 2).')';
            }
        }

        return $origenes;
    }

    /** @param array{origen: string, monto: numeric, fecha: string, descripcion: string, categoria_personal_id?: ?int, user_id?: ?int} $data */
    public function pagar(User $user, Compromiso $compromiso, array $data): Compromiso
    {
        $origen = $data['origen'];
        $mov = Arr::except($data, ['origen']) + ['tipo' => 'egreso', 'compromiso_id' => $compromiso->id, 'contacto_id' => $compromiso->contacto_id];
        if ($compromiso->tarjeta_credito_id) {
            $mov['categoria_personal_id'] = $this->tarjetas->categoriaPago($user->hogar_id, $user->id);
        }

        if ($origen === 'efectivo') {
            abort_unless($user->can('efectivo.create'), 403);
            $this->efectivo->registrar($user, $mov);
        } elseif (preg_match('/^cuenta:(\d+)$/', $origen, $m)) {
            abort_unless($user->can('cuentas.create'), 403);
            $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una cuenta válida.']);
            $this->cuentas->registrarMovimiento($cuenta, $mov);
        } elseif (preg_match('/^tarjeta:(\d+)$/', $origen, $m)) {
            abort_unless($user->can('tarjetas.create'), 403);
            $tarjeta = $user->tarjetasCredito()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['origen' => 'Selecciona una tarjeta válida.']);
            $this->tarjetas->pagarCompromiso($tarjeta, $compromiso, $data);
        } else {
            throw ValidationException::withMessages(['origen' => 'Elige desde dónde pagas.']);
        }

        return $compromiso->refresh();
    }
}
