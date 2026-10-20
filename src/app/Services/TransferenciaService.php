<?php

namespace App\Services;

use App\Models\Cuenta;
use App\Models\TarjetaCredito;
use App\Models\Transferencia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mueve dinero entre efectivo y cuentas ("Metí al banco", "Saqué de la Chivo", de una cuenta a otra).
 * Cada transferencia crea la salida en el origen y la entrada en el destino; al eliminarla se borran ambas.
 * Origen y destino se expresan como "efectivo" o "cuenta:{id}"; el origen también puede ser "tarjeta:{id}"
 * (avance: la tarjeta se usa para sacar dinero, como el flujo tarjeta → Chivo → efectivo).
 */
class TransferenciaService
{
    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CuentaService $cuentas,
        private readonly TarjetaService $tarjetas,
    ) {}

    /** @param array{origen: string, destino: string, monto: numeric, fecha: string, descripcion?: ?string} $data */
    public function crear(User $user, array $data): Transferencia
    {
        $this->ciclos->closeIfNeeded($user);
        $tarjeta = $this->resolverTarjeta($user, $data['origen']);
        [$origenTipo, $origen] = $tarjeta ? ['tarjeta', null] : $this->resolver($user, $data['origen'], 'origen');
        [$destinoTipo, $destino] = $this->resolver($user, $data['destino'], 'destino');
        if ($data['origen'] === $data['destino']) {
            throw ValidationException::withMessages(['destino' => 'El origen y el destino deben ser distintos.']);
        }
        $monto = round((float) $data['monto'], 2);

        return DB::transaction(function () use ($user, $data, $origenTipo, $origen, $tarjeta, $destinoTipo, $destino, $monto) {
            // Fondos suficientes en el origen
            if ($origenTipo === 'efectivo' && round($this->ciclos->balance($user) - $monto, 2) < 0) {
                throw ValidationException::withMessages(['monto' => 'No tienes suficiente efectivo para esta transferencia.']);
            }
            foreach (array_filter([$origen, $destino]) as $cuenta) {
                $this->cuentas->validarFecha($cuenta, $data['fecha']);
            }
            if ($origen) {
                $origen = Cuenta::whereKey($origen->id)->lockForUpdate()->first();
                $this->cuentas->asegurarSaldo($origen, -$monto);
            }

            $transferencia = $user->transferencias()->create([
                'user_id' => $data['user_id'] ?? $user->id,
                'origen_tipo' => $origenTipo,
                'origen_cuenta_id' => $origen?->id,
                'origen_tarjeta_id' => $tarjeta?->id,
                'destino_tipo' => $destinoTipo,
                'destino_cuenta_id' => $destino?->id,
                'descripcion' => ($data['descripcion'] ?? null) ?: 'Transferencia',
                'monto' => $monto,
                'fecha' => $data['fecha'],
            ]);
            $transferencia->load('origenCuenta', 'origenTarjeta', 'destinoCuenta');

            if ($tarjeta) {
                $this->tarjetas->registrarAvance($tarjeta, $monto, $data['fecha'], 'Avance hacia '.$transferencia->nombreDestino().($transferencia->descripcion === 'Transferencia' ? '' : ": {$transferencia->descripcion}"), $transferencia->id);
            } else {
                $this->movimiento($transferencia, $origenTipo, $origen, 'egreso', 'Hacia '.$transferencia->nombreDestino());
            }
            $this->movimiento($transferencia, $destinoTipo, $destino, 'ingreso', 'Desde '.$transferencia->nombreOrigen());

            return $transferencia;
        });
    }

    /** Al eliminarla, el destino pierde la entrada: no puede quedar en negativo. */
    public function eliminar(Transferencia $transferencia): void
    {
        [$inicio] = $this->ciclos->cicloConsultado($transferencia->user);
        abort_if($transferencia->fecha->toDateString() < $inicio->toDateString(), 403, 'Solo se pueden eliminar transferencias del ciclo actual.');

        $avance = $transferencia->origenTarjeta ? $transferencia->origenTarjeta->movimientos()->where('transferencia_id', $transferencia->id)->first() : null;
        if ($avance?->items()->exists()) {
            throw ValidationException::withMessages(['monto' => 'No puedes eliminarla: el avance ya se facturó en un estado de cuenta de la tarjeta.']);
        }

        DB::transaction(function () use ($transferencia) {
            if ($transferencia->destino_tipo === 'efectivo') {
                $entrada = $transferencia->movimientoEfectivo;
                if (round($this->ciclos->balance($transferencia->user, null, $entrada?->id), 2) < 0) {
                    throw ValidationException::withMessages(['monto' => 'No puedes eliminarla: el efectivo quedaría en negativo.']);
                }
            } else {
                $entrada = $transferencia->movimientosCuenta()->where('tipo', 'ingreso')->first();
                $this->cuentas->asegurarSaldo($transferencia->destinoCuenta, 0, $entrada?->id,
                    "No puedes eliminarla: {$transferencia->destinoCuenta->nombre} quedaría en negativo.");
            }
            $transferencia->delete();
        });
    }

    private function resolverTarjeta(User $user, string $valor): ?TarjetaCredito
    {
        if (! preg_match('/^tarjeta:(\d+)$/', $valor, $m)) {
            return null;
        }

        return $user->tarjetasCredito()->where('activo', true)->find($m[1])
            ?? throw ValidationException::withMessages(['origen' => 'Selecciona una tarjeta válida.']);
    }

    /** @return array{0: string, 1: ?Cuenta} */
    private function resolver(User $user, string $valor, string $campo): array
    {
        if ($valor === 'efectivo') {
            return ['efectivo', null];
        }
        if (preg_match('/^cuenta:(\d+)$/', $valor, $m)) {
            $cuenta = $user->cuentas()->where('activo', true)->find($m[1]);
            if ($cuenta) {
                return ['cuenta', $cuenta];
            }
        }

        throw ValidationException::withMessages([$campo => 'Selecciona un '.$campo.' válido.']);
    }

    private function movimiento(Transferencia $t, string $tipo, ?Cuenta $cuenta, string $sentido, string $prefijo): void
    {
        $datos = [
            'user_id' => $t->user_id,
            'transferencia_id' => $t->id,
            'descripcion' => $t->descripcion === 'Transferencia' ? $prefijo : "{$prefijo}: {$t->descripcion}",
            'monto' => $t->monto,
            'fecha' => $t->fecha,
            'tipo' => $sentido,
        ];

        $tipo === 'efectivo'
            ? $t->user->movimientosEfectivo()->create($datos)
            : $cuenta->movimientos()->create($datos);
    }
}
