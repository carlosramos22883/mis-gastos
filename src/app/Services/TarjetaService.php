<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\EstadoCuentaTarjeta;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Models\User;
use App\Support\MovimientoVinculado;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de las tarjetas de crédito.
 *
 *  - Deuda = estado de cuenta pendiente al registrarla + compras, avances y cargos − créditos − lo pagado
 *    en sus compromisos. Disponible = límite − deuda. Al pagar un compromiso de la tarjeta (desde efectivo
 *    o una cuenta) el disponible se recupera solo.
 *  - Cada corte genera un estado de cuenta con su detalle y un compromiso con la fecha límite de pago.
 *    Las compras a tasa cero se facturan una cuota por corte; la última cuota absorbe los centavos.
 */
class TarjetaService
{
    private const SUMAN = ['compra', 'avance', 'cargo'];

    public function __construct(
        private readonly CicloEfectivoService $ciclos,
        private readonly CompromisoService $compromisos,
    ) {}

    /**
     * @param  array  $data  datos de la tarjeta + "disponible" actual y, opcionalmente, el estado de cuenta
     *                       pendiente ("pendiente_monto", "pendiente_fecha")
     */
    public function crear(User $user, array $data): TarjetaCredito
    {
        $usada = round((float) $data['limite_credito'] - (float) $data['disponible'], 2);
        $facturado = round((float) ($data['pendiente_monto'] ?? 0), 2);
        if ($facturado > $usada) {
            throw ValidationException::withMessages(['pendiente_monto' => 'El estado de cuenta pendiente no puede ser mayor a lo usado de la tarjeta ('.number_format($usada, 2).').']);
        }

        return DB::transaction(function () use ($user, $data, $usada, $facturado) {
            $hoy = $this->hoy($user)->toDateString();
            $tarjeta = $user->tarjetasCredito()->create(collect($data)->except(['disponible', 'pendiente_monto', 'pendiente_fecha'])->all() + [
                'deuda_inicial' => $facturado,
                'fecha_registro' => $hoy,
            ]);

            if ($facturado > 0) {
                $this->compromisos->crearUnico($user, [
                    'descripcion' => "Tarjeta {$tarjeta->nombre} · estado de cuenta pendiente",
                    'monto' => $facturado,
                    'fecha_esperada' => $data['pendiente_fecha'] ?? null,
                    'medio_pago' => 'por_definir',
                    'tarjeta_credito_id' => $tarjeta->id,
                ]);
            }
            // Lo usado que aún no se facturó entra en el próximo corte
            if ($usada - $facturado > 0) {
                $tarjeta->movimientos()->create([
                    'user_id' => $user->id, 'tipo' => 'compra', 'descripcion' => 'Consumos sin facturar al registrar la tarjeta',
                    'monto' => round($usada - $facturado, 2), 'fecha' => $hoy,
                ]);
            }

            return $tarjeta;
        });
    }

    /** Una tarjeta con historial no se elimina: se desactiva. */
    public function eliminar(TarjetaCredito $tarjeta): void
    {
        if ($tarjeta->movimientos()->exists() || $tarjeta->compromisos()->exists()) {
            throw ValidationException::withMessages(['tarjeta' => 'Esta tarjeta ya tiene movimientos o pagos. Desactívala en lugar de eliminarla.']);
        }
        $tarjeta->delete();
    }

    public function deuda(TarjetaCredito $tarjeta, ?int $exceptMovimientoId = null): float
    {
        $movimientos = (float) $tarjeta->movimientos()
            ->when($exceptMovimientoId, fn ($q) => $q->whereKeyNot($exceptMovimientoId))
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'credito' THEN -monto ELSE monto END), 0) total")
            ->value('total');
        $pagado = (float) $tarjeta->compromisos()->selectRaw('COALESCE(SUM(monto - saldo_pendiente), 0) pagado')->value('pagado');

        return round((float) $tarjeta->deuda_inicial + $movimientos - $pagado, 2);
    }

    public function disponible(TarjetaCredito $tarjeta, ?int $exceptMovimientoId = null): float
    {
        return round((float) $tarjeta->limite_credito - $this->deuda($tarjeta, $exceptMovimientoId), 2);
    }

    /**
     * Lo que costaría no pagar completo un estado de cuenta: interés de un mes sobre lo pendiente
     * (saldo × tasa anual × 30 / 365). Es un mínimo: muchos bancos cobran desde la fecha de cada compra
     * y además cargan el pago tardío. null si la tarjeta no tiene tasa.
     *
     * @return array{pendiente: float, tasa: float, interes_mes: float, fecha_limite: ?string}|null
     */
    public function costoDeNoPagar(TarjetaCredito $tarjeta, Compromiso $compromiso): ?array
    {
        $tasa = (float) $tarjeta->tasa_interes;
        if ($tasa <= 0) {
            return null;
        }
        $pendiente = (float) $compromiso->saldo_pendiente;

        return ['pendiente' => $pendiente, 'tasa' => $tasa, 'interes_mes' => round($pendiente * $tasa / 100 * 30 / 365, 2),
            'fecha_limite' => $compromiso->fecha_esperada?->toDateString()];
    }

    /** Primer día del período que aún no se ha facturado. */
    public function inicioPeriodoAbierto(TarjetaCredito $tarjeta): Carbon
    {
        $ultimo = $tarjeta->estadosCuenta()->max('fecha_corte');

        return $ultimo ? Carbon::parse($ultimo)->addDay() : Carbon::parse($tarjeta->fecha_registro ?? $tarjeta->created_at)->startOfDay();
    }

    /** Primer día de corte en o después de $desde (31 = último día del mes). */
    public function proximoCorte(TarjetaCredito $tarjeta, Carbon $desde): Carbon
    {
        $corte = $this->diaDelMes($desde, $tarjeta->dia_corte);

        return $corte->lt($desde->copy()->startOfDay()) ? $this->diaDelMes($desde->copy()->startOfMonth()->addMonthNoOverflow(), $tarjeta->dia_corte) : $corte;
    }

    /** Fecha límite de pago de un corte: el siguiente día de pago después del corte. */
    public function fechaLimite(TarjetaCredito $tarjeta, Carbon $corte): Carbon
    {
        $limite = $this->diaDelMes($corte, $tarjeta->dia_pago);

        return $limite->lte($corte) ? $this->diaDelMes($corte->copy()->startOfMonth()->addMonthNoOverflow(), $tarjeta->dia_pago) : $limite;
    }

    /** Genera los estados de cuenta de todos los cortes que ya pasaron. Devuelve cuántos generó. */
    public function generarEstados(TarjetaCredito $tarjeta): int
    {
        $hoy = $this->hoy($tarjeta->user)->toDateString();
        $generados = 0;

        while (true) {
            $inicio = $this->inicioPeriodoAbierto($tarjeta);
            $corte = $this->proximoCorte($tarjeta, $inicio);
            if ($corte->toDateString() >= $hoy) {
                return $generados;
            }
            $this->generarEstado($tarjeta, $inicio, $corte);
            $generados++;
        }
    }

    public function generarPendientes(User $user): void
    {
        foreach ($user->tarjetasCredito()->get() as $tarjeta) {
            $this->generarEstados($tarjeta);
        }
    }

    /** Lo que entraría en el próximo corte: movimientos del período abierto y cuotas pendientes. */
    public function porFacturar(TarjetaCredito $tarjeta): Collection
    {
        $inicio = $this->inicioPeriodoAbierto($tarjeta);

        return $this->lineasDelPeriodo($tarjeta, $inicio, $this->proximoCorte($tarjeta, $inicio));
    }

    /** @param array{tipo: string, descripcion: string, monto: numeric, fecha: string, cuotas?: ?int, categoria_personal_id?: ?int, comentario?: ?string} $data */
    public function registrarMovimiento(TarjetaCredito $tarjeta, array $data): MovimientoTarjeta
    {
        $this->generarEstados($tarjeta);

        return DB::transaction(function () use ($tarjeta, $data) {
            $tarjeta = TarjetaCredito::whereKey($tarjeta->id)->lockForUpdate()->firstOrFail();
            $this->validarMovimiento($tarjeta, $data);

            return $tarjeta->movimientos()->create($data);
        });
    }

    /**
     * Paga (o abona) un compromiso cargándolo a una tarjeta: una compra en la tarjeta que paga y el abono al
     * compromiso. Sirve para pagar con una tarjeta el estado de cuenta de otra (NPE) o cualquier factura.
     *
     * @param  array{monto: numeric, fecha: string, descripcion: string, categoria_personal_id?: ?int, user_id?: ?int}  $data
     */
    public function pagarCompromiso(TarjetaCredito $tarjeta, Compromiso $compromiso, array $data): MovimientoTarjeta
    {
        if ((int) $compromiso->tarjeta_credito_id === (int) $tarjeta->id) {
            throw ValidationException::withMessages(['origen' => 'Una tarjeta no puede pagar su propio estado de cuenta.']);
        }

        return DB::transaction(function () use ($tarjeta, $compromiso, $data) {
            $movimiento = $this->registrarMovimiento($tarjeta, [
                'tipo' => 'compra', 'descripcion' => $data['descripcion'], 'monto' => $data['monto'], 'fecha' => $data['fecha'],
                // Pagar otra tarjeta no es un gasto nuevo (sus compras ya tienen categoría); una factura sí
                'categoria_personal_id' => $compromiso->tarjeta_credito_id ? null : ($data['categoria_personal_id'] ?? null),
                'user_id' => $data['user_id'] ?? null, 'contacto_id' => $compromiso->contacto_id, 'pago_compromiso_id' => $compromiso->id,
            ]);
            $this->compromisos->abonar($compromiso, (float) $data['monto']);

            return $movimiento;
        });
    }

    public function actualizarMovimiento(MovimientoTarjeta $movimiento, array $data): MovimientoTarjeta
    {
        $this->asegurarEditable($movimiento);

        return DB::transaction(function () use ($movimiento, $data) {
            $tarjeta = TarjetaCredito::whereKey($movimiento->tarjeta_credito_id)->lockForUpdate()->firstOrFail();
            $this->validarMovimiento($tarjeta, $data, $movimiento->id);
            if ($movimiento->pagoCompromiso) { // el abono sigue al nuevo monto
                $this->compromisos->abonar($movimiento->pagoCompromiso, (float) $data['monto'] - (float) $movimiento->monto);
            }
            $movimiento->update($data);

            return $movimiento;
        });
    }

    public function eliminarMovimiento(MovimientoTarjeta $movimiento): void
    {
        $this->asegurarEditable($movimiento);
        DB::transaction(function () use ($movimiento) {
            if ($movimiento->pagoCompromiso) { // ese monto vuelve a quedar pendiente en el compromiso
                $this->compromisos->abonar($movimiento->pagoCompromiso, -(float) $movimiento->monto);
            }
            $movimiento->delete();
        });
    }

    /** Solo lo que aún no se ha facturado; los avances se gestionan desde su transferencia y la mora desde su compromiso. */
    public function esEditable(MovimientoTarjeta $movimiento): bool
    {
        return ! $movimiento->transferencia_id && ! $movimiento->compromiso_id && ! $movimiento->items()->exists()
            && MovimientoVinculado::modulo($movimiento) === null; // los de un deudor o un préstamo se gestionan desde su módulo
    }

    /** Avance a efectivo o a una cuenta (lo crea TransferenciaService). */
    public function registrarAvance(TarjetaCredito $tarjeta, float $monto, string $fecha, string $descripcion, int $transferenciaId): MovimientoTarjeta
    {
        $this->generarEstados($tarjeta);
        $data = ['tipo' => 'avance', 'descripcion' => $descripcion, 'monto' => $monto, 'fecha' => $fecha, 'transferencia_id' => $transferenciaId];
        $tarjeta = TarjetaCredito::whereKey($tarjeta->id)->lockForUpdate()->firstOrFail();
        $this->validarMovimiento($tarjeta, $data);

        return $tarjeta->movimientos()->create($data);
    }

    /** Mora de un estado de cuenta vencido: un cargo a la tarjeta que se paga en su propio compromiso. */
    public function registrarMora(EstadoCuentaTarjeta $estado, float $monto, string $descripcion): Compromiso
    {
        $tarjeta = $estado->tarjeta;
        $hoy = $this->hoy($tarjeta->user);
        $compromiso = $estado->compromiso;
        if (! $compromiso || $compromiso->estado !== Compromiso::PENDIENTE || ! $compromiso->fecha_esperada || $compromiso->fecha_esperada->toDateString() >= $hoy->toDateString()) {
            throw ValidationException::withMessages(['monto' => 'Solo se registra mora en un estado de cuenta vencido y pendiente de pago.']);
        }

        return DB::transaction(function () use ($tarjeta, $compromiso, $monto, $descripcion, $hoy) {
            $mora = $this->compromisos->crearUnico($tarjeta->user, [
                'descripcion' => $descripcion,
                'comentario' => 'Mora del compromiso «'.$compromiso->descripcion.'»',
                'monto' => $monto,
                'fecha_esperada' => $hoy->toDateString(),
                'medio_pago' => 'por_definir',
                'tarjeta_credito_id' => $tarjeta->id,
            ]);
            $tarjeta->movimientos()->create([
                'tipo' => 'cargo', 'descripcion' => $descripcion,
                'monto' => $monto, 'fecha' => $hoy->toDateString(), 'compromiso_id' => $mora->id,
            ]);

            return $mora;
        });
    }

    /** La fecha límite cambia de un mes a otro: se ajusta por estado de cuenta y su compromiso la sigue. */
    public function actualizarFechaLimite(EstadoCuentaTarjeta $estado, string $fecha): void
    {
        if ($fecha < $estado->fecha_corte->toDateString()) {
            throw ValidationException::withMessages(['fecha_limite_pago' => 'La fecha límite no puede ser anterior al corte.']);
        }

        DB::transaction(function () use ($estado, $fecha) {
            $estado->update(['fecha_limite_pago' => $fecha]);
            $estado->compromiso?->update([
                'fecha_esperada' => $fecha,
                'ciclo_inicio' => $this->ciclos->cycleFor($estado->tarjeta->user, Carbon::parse($fecha))[0],
            ]);
        });
    }

    private function generarEstado(TarjetaCredito $tarjeta, Carbon $inicio, Carbon $corte): EstadoCuentaTarjeta
    {
        return DB::transaction(function () use ($tarjeta, $inicio, $corte) {
            $lineas = $this->lineasDelPeriodo($tarjeta, $inicio, $corte);
            $total = round($lineas->sum('monto'), 2);
            $limite = $this->fechaLimite($tarjeta, $corte);

            $estado = $tarjeta->estadosCuenta()->create([
                'fecha_inicio' => $inicio->toDateString(),
                'fecha_corte' => $corte->toDateString(),
                'fecha_limite_pago' => $limite->toDateString(),
                'total' => $total,
            ]);
            foreach ($lineas as $linea) {
                $estado->items()->create([
                    'movimiento_tarjeta_id' => $linea['movimiento']->id,
                    'cuota_numero' => $linea['cuota_numero'],
                    'monto' => $linea['monto'],
                ]);
            }

            if ($total > 0) {
                $this->compromisos->crearUnico($tarjeta->user, [
                    'descripcion' => "Tarjeta {$tarjeta->nombre} · corte {$corte->format('d/m/Y')}",
                    'monto' => $total,
                    'fecha_esperada' => $limite->toDateString(),
                    'medio_pago' => 'por_definir',
                    'tarjeta_credito_id' => $tarjeta->id,
                    'estado_cuenta_tarjeta_id' => $estado->id,
                ]);
            }

            return $estado;
        });
    }

    /** @return Collection<int, array{movimiento: MovimientoTarjeta, cuota_numero: ?int, monto: float}> */
    private function lineasDelPeriodo(TarjetaCredito $tarjeta, Carbon $inicio, Carbon $corte): Collection
    {
        // Sin facturar todavía, o compras a plazos que aún tienen cuotas por cobrar
        $movimientos = MovimientoTarjeta::where('tarjeta_credito_id', $tarjeta->id)
            ->whereNull('compromiso_id')
            ->whereDate('fecha', '<=', $corte->toDateString())
            ->where(fn ($q) => $q->whereDoesntHave('items')->orWhere(fn ($q) => $q->where('tipo', 'compra')->where('cuotas', '>', 1)))
            ->withCount('items')->withSum('items', 'monto')
            ->orderBy('fecha')->orderBy('id')->get();

        return $movimientos->map(function (MovimientoTarjeta $m) use ($inicio) {
            if ($m->esEnCuotas()) {
                if ($m->items_count >= $m->cuotas) {
                    return null;
                }
                $numero = $m->items_count + 1;
                $cuota = round((float) $m->monto / $m->cuotas, 2);
                $monto = $numero === $m->cuotas ? round((float) $m->monto - (float) $m->items_sum_monto, 2) : $cuota;

                return ['movimiento' => $m, 'cuota_numero' => $numero, 'monto' => $monto];
            }
            if ($m->items_count > 0 || $m->fecha->toDateString() < $inicio->toDateString()) {
                return null;
            }

            return ['movimiento' => $m, 'cuota_numero' => null, 'monto' => $m->tipo === 'credito' ? -(float) $m->monto : (float) $m->monto];
        })->filter()->values();
    }

    private function validarMovimiento(TarjetaCredito $tarjeta, array $data, ?int $exceptId = null): void
    {
        $inicio = $this->inicioPeriodoAbierto($tarjeta);
        if ($data['fecha'] < $inicio->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'La fecha debe ser del período sin facturar (desde el '.$inicio->format('d/m/Y').').']);
        }
        if (in_array($data['tipo'], ['compra', 'avance'], true) && (float) $data['monto'] > $this->disponible($tarjeta, $exceptId)) {
            throw ValidationException::withMessages(['monto' => 'El monto supera el disponible de la tarjeta ($ '.number_format(max(0, $this->disponible($tarjeta, $exceptId)), 2).').']);
        }
    }

    private function asegurarEditable(MovimientoTarjeta $movimiento): void
    {
        MovimientoVinculado::asegurarLibre($movimiento);
        abort_unless($this->esEditable($movimiento), 403, 'Este movimiento ya se facturó o se gestiona desde su transferencia o compromiso.');
    }

    private function diaDelMes(Carbon $mes, int $dia): Carbon
    {
        return Carbon::parse($mes->copy()->day(1)->toDateString())->day(min($dia, $mes->daysInMonth));
    }

    private function hoy(User $user): Carbon
    {
        return Carbon::parse(Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString());
    }
}
