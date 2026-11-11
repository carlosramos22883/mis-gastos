<?php

namespace App\Services;

use App\Models\CargoFijoTarjeta;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\EstadoCuentaItem;
use App\Models\EstadoCuentaTarjeta;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
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
 *  - Los cargos fijos (seguro, Netflix…) crean su movimiento el día de su cobro, cada mes entre su inicio y su fin.
 *  - Pagar de más un compromiso de la tarjeta: lo que pase del saldo pendiente queda como un crédito en ella (sube el
 *    disponible y baja el próximo corte). Un corte que queda en negativo pasa ese saldo a favor al siguiente.
 *  - Tarjetas con cargos_restan_disponible = false (Davivienda): los cargos del banco (tipo "cargo": intereses, comisiones,
 *    IVA) no bajan el disponible hasta pagarse; un pago cubre primero los cargos de su corte (ver cargosSinRestar).
 *  - Intereses bonificables (los trae el estado de cuenta del banco): los del último corte se restan del disponible
 *    hasta el corte siguiente, igual que en la app del banco, aunque ya se haya pagado (ver interesesRetenidos).
 */
class TarjetaService
{
    private const SUMAN = ['compra', 'avance', 'cargo'];

    /** Categoría de los pagos de una tarjeta: no es gasto (sus compras ya lo son), solo ordena el historial. */
    public const CATEGORIA_PAGO = 'Pago de tarjeta';

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
        return round((float) $tarjeta->limite_credito - $this->deuda($tarjeta, $exceptMovimientoId) - $this->interesesRetenidos($tarjeta)
            + $this->cargosSinRestar($tarjeta, $exceptMovimientoId), 2);
    }

    /**
     * Cargos del banco por pagar que la tarjeta no resta del disponible (solo si cargos_restan_disponible = false): los
     * que aún no se facturan y, en cada corte pendiente, lo que lo pagado no alcanzó a cubrir (el banco aplica el pago
     * primero a intereses y comisiones). La mora tiene su propio compromiso y sí resta.
     */
    public function cargosSinRestar(TarjetaCredito $tarjeta, ?int $exceptMovimientoId = null): float
    {
        if ($tarjeta->cargos_restan_disponible ?? true) {
            return 0.0;
        }
        $cargos = fn () => MovimientoTarjeta::where('tarjeta_credito_id', $tarjeta->id)->where('tipo', 'cargo')->whereNull('compromiso_id')
            ->when($exceptMovimientoId, fn ($q) => $q->whereKeyNot($exceptMovimientoId));
        $total = (float) $cargos()->whereDoesntHave('items')->sum('monto');
        $pendientes = $tarjeta->estadosCuenta()->with('compromiso')
            ->whereHas('compromiso', fn ($q) => $q->where('estado', Compromiso::PENDIENTE))->get();
        foreach ($pendientes as $estado) {
            $enCorte = (float) EstadoCuentaItem::where('estado_cuenta_tarjeta_id', $estado->id)
                ->whereIn('movimiento_tarjeta_id', $cargos()->select('id'))->sum('monto');
            $total += max(0, $enCorte - $estado->compromiso->montoPagado());
        }

        return round($total, 2);
    }

    /**
     * Intereses bonificables que el banco aparta del disponible: los del último corte, pagado o no. El banco los
     * resuelve en el corte siguiente: si se pagó el saldo de contado a tiempo los abona («bonificación pago total»)
     * y si no, los cobra como intereses (se anotan ahí como un cargo). Por eso un corte anterior ya no retiene nada.
     */
    public function interesesRetenidos(TarjetaCredito $tarjeta): float
    {
        return round((float) $tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->value('intereses_bonificables'), 2);
    }

    /** Intereses bonificables que trae el estado de cuenta del banco para este corte. */
    public function actualizarInteresesBonificables(EstadoCuentaTarjeta $estado, float $monto): void
    {
        $estado->update(['intereses_bonificables' => round($monto, 2)]);
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

    /** Genera los estados de cuenta de todos los cortes que ya pasaron y cobra los cargos fijos que ya tocaron. Devuelve cuántos estados generó. */
    public function generarEstados(TarjetaCredito $tarjeta): int
    {
        $hoy = $this->hoy($tarjeta->user);
        $generados = 0;

        while (true) {
            $inicio = $this->inicioPeriodoAbierto($tarjeta);
            $corte = $this->proximoCorte($tarjeta, $inicio);
            if ($corte->toDateString() >= $hoy->toDateString()) {
                $this->aplicarCargosFijos($tarjeta, $hoy);

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

    /**
     * Lo que entraría en el próximo corte: movimientos del período abierto y cuotas pendientes. "programada" = una cuota
     * con día de cobro que aún no llega (se cobrará antes del corte).
     */
    public function porFacturar(TarjetaCredito $tarjeta): Collection
    {
        $inicio = $this->inicioPeriodoAbierto($tarjeta);
        $hoy = $this->hoy($tarjeta->user)->toDateString();

        return $this->lineasDelPeriodo($tarjeta, $inicio, $this->proximoCorte($tarjeta, $inicio))
            ->map(fn (array $l) => $l + ['programada' => $l['fecha']->toDateString() > $hoy]);
    }

    /** Fecha de la cuota $numero (desde 1) de una compra con día de cobro: la primera es la de ese día en o después de la compra. */
    public function fechaCuota(MovimientoTarjeta $compra, int $numero): Carbon
    {
        $mes = Carbon::parse($compra->fecha->toDateString())->startOfMonth();
        if ($this->diaDelMes($mes, $compra->dia_cuota)->toDateString() < $compra->fecha->toDateString()) {
            $mes->addMonthNoOverflow();
        }

        return $this->diaDelMes($mes->addMonthsNoOverflow($numero - 1), $compra->dia_cuota);
    }

    /**
     * Con $estado, el movimiento se agrega a ese corte ya facturado (algo que faltó anotar): su total y su pago pendiente suben.
     *
     * @param  array{tipo: string, descripcion: string, monto: numeric, fecha: string, cuotas?: ?int, categoria_personal_id?: ?int, comentario?: ?string, confirmar?: bool}  $data  con "confirmar" se acepta sobregirar la tarjeta
     */
    public function registrarMovimiento(TarjetaCredito $tarjeta, array $data, ?EstadoCuentaTarjeta $estado = null): MovimientoTarjeta
    {
        $this->generarEstados($tarjeta);

        return DB::transaction(function () use ($tarjeta, $data, $estado) {
            $tarjeta = TarjetaCredito::whereKey($tarjeta->id)->lockForUpdate()->firstOrFail();
            if (! $estado) {
                $this->validarMovimiento($tarjeta, $data);
                unset($data['confirmar']);

                return $tarjeta->movimientos()->create($data);
            }

            $this->validarFacturado($tarjeta, $estado, $data);
            unset($data['confirmar']);
            $movimiento = $tarjeta->movimientos()->create(['cuotas' => null, 'dia_cuota' => null] + $data);
            $this->ajustarEstado($estado->items()->create(['movimiento_tarjeta_id' => $movimiento->id, 'monto' => 0]),
                $data['tipo'] === 'credito' ? -(float) $data['monto'] : (float) $data['monto']);

            return $movimiento;
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
                // Pagar otra tarjeta no es un gasto nuevo (sus compras ya tienen categoría): "Pago de tarjeta"; una factura sí lleva la suya
                'categoria_personal_id' => $compromiso->tarjeta_credito_id ? $this->categoriaPago($tarjeta->hogar_id, $tarjeta->user_id) : ($data['categoria_personal_id'] ?? null),
                'user_id' => $data['user_id'] ?? null, 'contacto_id' => $compromiso->contacto_id, 'pago_compromiso_id' => $compromiso->id,
            ]);
            $this->abonarPago($compromiso, (float) $data['monto'], $data['fecha'], $data['user_id'] ?? null);

            return $movimiento;
        });
    }

    public function actualizarMovimiento(MovimientoTarjeta $movimiento, array $data): MovimientoTarjeta
    {
        $this->asegurarEditable($movimiento);

        return DB::transaction(function () use ($movimiento, $data) {
            $tarjeta = TarjetaCredito::whereKey($movimiento->tarjeta_credito_id)->lockForUpdate()->firstOrFail();
            $item = $movimiento->items()->first();
            if ($item) { // ya facturado: se corrige dentro de su estado de cuenta y el pago pendiente sigue el nuevo monto
                $this->validarFacturado($tarjeta, $item->estadoCuenta, $data, $movimiento->id);
                $data['cuotas'] = $data['dia_cuota'] = null;
                $this->ajustarEstado($item, $data['tipo'] === 'credito' ? -(float) $data['monto'] : (float) $data['monto']);
            } else {
                $this->validarMovimiento($tarjeta, $data, $movimiento->id);
            }
            unset($data['confirmar']);
            if ($movimiento->pagoCompromiso) { // el abono sigue al nuevo monto
                $this->abonarPago($movimiento->pagoCompromiso, (float) $data['monto'] - (float) $movimiento->monto, $data['fecha'], $movimiento->user_id);
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
                $this->abonarPago($movimiento->pagoCompromiso, -(float) $movimiento->monto);
            }
            if ($item = $movimiento->items()->first()) { // ya facturado: sale de su estado de cuenta
                $this->ajustarEstado($item, 0);
                $item->delete();
            }
            $movimiento->delete();
        });
    }

    /**
     * Lo no facturado y también lo ya facturado (para corregir un error: se ajusta su estado de cuenta), salvo una compra
     * en cuotas con cuotas ya cobradas. Los avances se gestionan desde su transferencia, la mora desde su compromiso y el
     * excedente de un pago desde ese pago.
     */
    public function esEditable(MovimientoTarjeta $movimiento): bool
    {
        return ! $movimiento->transferencia_id && ! $movimiento->compromiso_id
            && ! ($movimiento->esEnCuotas() && $movimiento->items()->exists())
            && MovimientoVinculado::modulo($movimiento) === null; // los de un deudor o un préstamo se gestionan desde su módulo
    }

    /**
     * Cambia lo que una línea suma a su estado de cuenta ($nuevo con signo: un crédito resta) y lleva la diferencia al
     * total y a su compromiso de pago. Lo ya pagado se respeta: el nuevo monto del compromiso no puede quedar por debajo.
     */
    private function ajustarEstado(EstadoCuentaItem $item, float $nuevo): void
    {
        $diferencia = round($nuevo - (float) $item->monto, 2);
        if ($diferencia == 0) {
            return;
        }
        $this->ajustarTotal(EstadoCuentaTarjeta::whereKey($item->estado_cuenta_tarjeta_id)->lockForUpdate()->firstOrFail(), $diferencia);
        $item->update(['monto' => $nuevo]);
    }

    /**
     * Suma $diferencia al total de un corte y a su compromiso de pago. Si el corte tenía o queda con saldo a favor
     * (total negativo), el cambio de ese saldo pasa al corte siguiente.
     */
    private function ajustarTotal(EstadoCuentaTarjeta $estado, float $diferencia): void
    {
        $anterior = (float) $estado->total;
        $total = round($anterior + $diferencia, 2);
        $compromiso = $estado->compromiso()->lockForUpdate()->first();

        if ($compromiso) {
            $pagado = $compromiso->montoPagado();
            $monto = max(0, round((float) $compromiso->monto + max(0, $total) - max(0, $anterior), 2)); // lo que quede a favor no se cobra
            if ($monto < $pagado) {
                throw ValidationException::withMessages(['monto' => 'Del corte del '.$estado->fecha_corte->format('d/m/Y').' ya se pagaron $ '.number_format($pagado, 2).': con este cambio quedaría en $ '.number_format($monto, 2).'.']);
            }
            $saldo = round($monto - $pagado, 2);
            $compromiso->update(['monto' => $monto, 'saldo_pendiente' => $saldo, 'estado' => match (true) {
                $compromiso->estado === Compromiso::CANCELADO => Compromiso::CANCELADO,
                $saldo <= 0 => Compromiso::PAGADO,
                default => Compromiso::PENDIENTE,
            }]);
        } elseif ($total > 0) { // el corte había quedado en cero (o a favor) y ahora sí hay que pagar algo
            $tarjeta = $estado->tarjeta;
            $this->compromisos->crearUnico($tarjeta->user, [
                'descripcion' => "Tarjeta {$tarjeta->nombre} · corte {$estado->fecha_corte->format('d/m/Y')}",
                'monto' => $total, 'fecha_esperada' => $estado->fecha_limite_pago->toDateString(), 'medio_pago' => 'por_definir',
                'tarjeta_credito_id' => $tarjeta->id, 'estado_cuenta_tarjeta_id' => $estado->id,
            ]);
        }
        $estado->update(['total' => $total]);

        $arrastre = round(min(0, $total) - min(0, $anterior), 2);
        $siguiente = $arrastre == 0 ? null : EstadoCuentaTarjeta::where('tarjeta_credito_id', $estado->tarjeta_credito_id)
            ->whereDate('fecha_corte', '>', $estado->fecha_corte->toDateString())->orderBy('fecha_corte')->lockForUpdate()->first();
        if ($siguiente) {
            $this->ajustarTotal($siguiente, $arrastre);
            $siguiente->update(['saldo_anterior' => round((float) $siguiente->saldo_anterior + $arrastre, 2)]);
        }
    }

    /**
     * Abona $monto a un compromiso (negativo = un pago que se editó o eliminó). En un compromiso de tarjeta (estado de
     * cuenta o mora) se acepta pagar de más: lo que pase del saldo pendiente queda como un crédito en la tarjeta, ligado
     * al compromiso; al reducir el pago se quita primero ese excedente. Debe llamarse dentro de una transacción.
     */
    public function abonarPago(Compromiso $compromiso, float $monto, ?string $fecha = null, ?int $userId = null): void
    {
        if (! $compromiso->tarjeta_credito_id) {
            $this->compromisos->abonar($compromiso, $monto);

            return;
        }
        $compromiso = Compromiso::whereKey($compromiso->id)->lockForUpdate()->firstOrFail();
        $excedente = MovimientoTarjeta::where('excedente_compromiso_id', $compromiso->id)->lockForUpdate()->first();
        $actual = (float) ($excedente?->monto ?? 0);
        if ($monto >= 0) {
            $abono = min($monto, max(0, (float) $compromiso->saldo_pendiente));
            $nuevo = round($actual + $monto - $abono, 2);
        } else {
            $quita = min(-$monto, $actual);
            $abono = -round(-$monto - $quita, 2);
            $nuevo = round($actual - $quita, 2);
        }
        if ($abono != 0) {
            $this->compromisos->abonar($compromiso, $abono);
        }
        $this->guardarExcedente($compromiso, $excedente, $nuevo, $fecha, $userId);
    }

    /** Lo que se pagó de más en un compromiso de la tarjeta (el crédito que quedó en ella). */
    public function excedente(Compromiso $compromiso): float
    {
        return (float) MovimientoTarjeta::where('excedente_compromiso_id', $compromiso->id)->value('monto');
    }

    /** Saldo a favor con el que arranca el período abierto (el último corte quedó en negativo), como número ≤ 0. */
    public function saldoAFavor(TarjetaCredito $tarjeta): float
    {
        return min(0, (float) $tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->value('total'));
    }

    /** Crea, cambia o elimina el crédito del excedente. Si ya entró en un corte, ese corte se ajusta. */
    private function guardarExcedente(Compromiso $compromiso, ?MovimientoTarjeta $excedente, float $monto, ?string $fecha, ?int $userId): void
    {
        $item = $excedente?->items()->first();
        if ($excedente && $monto <= 0) {
            if ($item) {
                $this->ajustarEstado($item, 0);
                $item->delete();
            }
            $excedente->delete();

            return;
        }
        if ($excedente) {
            if ($item) {
                $this->ajustarEstado($item, -$monto);
            }
            $excedente->update(['monto' => $monto]);

            return;
        }
        if ($monto <= 0) {
            return;
        }

        $tarjeta = $compromiso->tarjeta;
        $fecha = max($fecha ?? $this->hoy($tarjeta->user)->toDateString(), $this->inicioPeriodoAbierto($tarjeta)->toDateString());
        $estado = $compromiso->estadoCuenta;
        $tarjeta->movimientos()->create([
            'user_id' => $userId ?? $tarjeta->user_id, 'tipo' => 'credito', 'monto' => $monto, 'fecha' => $fecha,
            'descripcion' => $estado ? 'Pago de más al estado de cuenta del corte '.$estado->fecha_corte->format('d/m/Y') : "Pago de más a «{$compromiso->descripcion}»",
            'categoria_personal_id' => $this->categoriaPago($tarjeta->hogar_id, $tarjeta->user_id), 'excedente_compromiso_id' => $compromiso->id,
        ]);
    }

    /**
     * Crea el movimiento de cada cobro de los cargos fijos activos (Netflix el 15…) con fecha hasta $hasta: así descuentan
     * el disponible el día que se cobran. Un cobro que caería en un corte ya facturado (inicio en el pasado) no se crea.
     */
    private function aplicarCargosFijos(TarjetaCredito $tarjeta, Carbon $hasta): void
    {
        $inicioAbierto = $this->inicioPeriodoAbierto($tarjeta)->toDateString();
        foreach ($tarjeta->cargosFijos()->where('activo', true)->pluck('id') as $id) {
            DB::transaction(function () use ($tarjeta, $id, $hasta, $inicioAbierto) {
                $cargo = $tarjeta->cargosFijos()->whereKey($id)->lockForUpdate()->first();
                while (($fecha = $this->proximoCobro($cargo)) && $fecha->toDateString() <= $hasta->toDateString()) {
                    if ($fecha->toDateString() >= $inicioAbierto) {
                        $tarjeta->movimientos()->create([
                            'user_id' => $tarjeta->user_id, 'tipo' => 'cargo', 'descripcion' => $cargo->descripcion, 'monto' => $cargo->monto,
                            'fecha' => $fecha->toDateString(), 'categoria_personal_id' => $cargo->categoria_personal_id, 'cargo_fijo_id' => $cargo->id,
                        ]);
                    }
                    $cargo->update(['ultimo_cobro' => $fecha->toDateString()]);
                }
            });
        }
    }

    /**
     * Siguiente cobro de un cargo fijo: su día en el mes siguiente al último cobro (así cambiar el día no cobra dos veces
     * el mismo mes), sin ser antes de "desde". null si ya pasó de "hasta".
     */
    public function proximoCobro(CargoFijoTarjeta $cargo): ?Carbon
    {
        $mes = $cargo->desde->copy()->startOfMonth();
        if ($cargo->ultimo_cobro && $cargo->ultimo_cobro->copy()->startOfMonth()->addMonthNoOverflow()->toDateString() > $mes->toDateString()) {
            $mes = $cargo->ultimo_cobro->copy()->startOfMonth()->addMonthNoOverflow();
        }
        $fecha = $this->diaDelMes($mes, $cargo->dia);
        if ($fecha->toDateString() < $cargo->desde->toDateString()) {
            $fecha = $this->diaDelMes($mes->addMonthNoOverflow(), $cargo->dia);
        }

        return $cargo->hasta && $fecha->toDateString() > $cargo->hasta->toDateString() ? null : $fecha;
    }

    /** Cargos fijos que aún se cobrarán antes del próximo corte (con "proximo_cobro"), para avisarlos en "por facturar". */
    public function cargosFijosDelProximoCorte(TarjetaCredito $tarjeta): Collection
    {
        $corte = $this->proximoCorte($tarjeta, $this->inicioPeriodoAbierto($tarjeta))->toDateString();

        return $tarjeta->cargosFijos()->where('activo', true)->get()
            ->each(fn (CargoFijoTarjeta $c) => $c->proximo_cobro = $this->proximoCobro($c))
            ->filter(fn (CargoFijoTarjeta $c) => $c->proximo_cobro && $c->proximo_cobro->toDateString() <= $corte)
            ->sortBy(fn (CargoFijoTarjeta $c) => $c->proximo_cobro->toDateString().$c->descripcion)->values();
    }

    /** Id de la categoría "Pago de tarjeta" del hogar (se crea la primera vez). */
    public function categoriaPago(int $hogarId, ?int $userId = null): int
    {
        return CategoriaPersonal::firstOrCreate(
            ['hogar_id' => $hogarId, 'nombre' => self::CATEGORIA_PAGO, 'tipo' => 'egreso'],
            ['user_id' => $userId, 'color' => '#475569', 'activo' => true],
        )->id;
    }

    /**
     * Pagos hechos a los estados de cuenta de la tarjeta (desde efectivo, una cuenta u otra tarjeta), del más reciente al más antiguo.
     *
     * @return Collection<int, array{clase: string, id: int, fecha: Carbon, descripcion: string, desde: string, monto: float, user_id: ?int, compromiso_id: int}>
     */
    public function pagos(TarjetaCredito $tarjeta, int $limite = 30): Collection
    {
        $ids = $tarjeta->compromisos()->pluck('id');
        $fila = fn (string $clase, string $desde) => fn ($m) => ['clase' => $clase, 'id' => $m->id, 'fecha' => $m->fecha, 'descripcion' => $m->descripcion,
            'desde' => $desde, 'monto' => (float) $m->monto, 'user_id' => $m->user_id, 'compromiso_id' => (int) ($m->compromiso_id ?? $m->pago_compromiso_id)];

        return collect()
            ->concat(MovimientoEfectivo::whereIn('compromiso_id', $ids)->where('tipo', 'egreso')->get()->map($fila('efectivo', 'Efectivo')))
            ->concat(MovimientoCuenta::with('cuenta')->whereIn('compromiso_id', $ids)->where('tipo', 'egreso')->get()->map(fn ($m) => $fila('cuenta', $m->cuenta->nombre)($m)))
            ->concat(MovimientoTarjeta::with('tarjeta')->whereIn('pago_compromiso_id', $ids)->get()->map(fn ($m) => $fila('tarjeta', 'Tarjeta '.$m->tarjeta->nombre)($m)))
            ->sortByDesc(fn ($p) => $p['fecha']->toDateString().sprintf('%010d', $p['id']))->take($limite)->values();
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
            $this->aplicarCargosFijos($tarjeta, $corte); // lo que se cobró hasta el corte entra en él
            $lineas = $this->lineasDelPeriodo($tarjeta, $inicio, $corte);
            $saldoAnterior = $this->saldoAFavor($tarjeta); // si el corte anterior quedó a favor, ese saldo se descuenta aquí
            $total = round($lineas->sum('monto') + $saldoAnterior, 2);
            $limite = $this->fechaLimite($tarjeta, $corte);

            $estado = $tarjeta->estadosCuenta()->create([
                'fecha_inicio' => $inicio->toDateString(),
                'fecha_corte' => $corte->toDateString(),
                'fecha_limite_pago' => $limite->toDateString(),
                'saldo_anterior' => $saldoAnterior,
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

    /**
     * Líneas de un período. Las cuotas con día de cobro entran en el corte que contiene su fecha (pueden ser varias si
     * se atrasaron); sin día, una por corte. La última cuota absorbe los centavos.
     *
     * @return Collection<int, array{movimiento: MovimientoTarjeta, cuota_numero: ?int, monto: float, fecha: Carbon}>
     */
    private function lineasDelPeriodo(TarjetaCredito $tarjeta, Carbon $inicio, Carbon $corte): Collection
    {
        // Sin facturar todavía, o compras a plazos que aún tienen cuotas por cobrar
        $movimientos = MovimientoTarjeta::where('tarjeta_credito_id', $tarjeta->id)
            ->whereNull('compromiso_id')
            ->whereDate('fecha', '<=', $corte->toDateString())
            ->where(fn ($q) => $q->whereDoesntHave('items')->orWhere(fn ($q) => $q->where('tipo', 'compra')->where('cuotas', '>', 1)))
            ->withCount('items')->withSum('items', 'monto')
            ->orderBy('fecha')->orderBy('id')->get();

        return $movimientos->flatMap(function (MovimientoTarjeta $m) use ($inicio, $corte) {
            if ($m->esEnCuotas()) {
                $cuota = round((float) $m->monto / $m->cuotas, 2);
                $cobrado = (float) $m->items_sum_monto;
                $lineas = [];
                for ($numero = $m->items_count + 1; $numero <= $m->cuotas; $numero++) {
                    $fecha = $m->dia_cuota ? $this->fechaCuota($m, $numero) : $m->fecha;
                    if ($m->dia_cuota && $fecha->toDateString() > $corte->toDateString()) {
                        break;
                    }
                    $monto = $numero === $m->cuotas ? round((float) $m->monto - $cobrado, 2) : $cuota;
                    $cobrado += $monto;
                    $lineas[] = ['movimiento' => $m, 'cuota_numero' => $numero, 'monto' => $monto, 'fecha' => $fecha];
                    if (! $m->dia_cuota) {
                        break;
                    }
                }

                return $lineas;
            }
            if ($m->items_count > 0 || $m->fecha->toDateString() < $inicio->toDateString()) {
                return [];
            }

            return [['movimiento' => $m, 'cuota_numero' => null, 'monto' => $m->tipo === 'credito' ? -(float) $m->monto : (float) $m->monto, 'fecha' => $m->fecha]];
        })->values();
    }

    private function validarMovimiento(TarjetaCredito $tarjeta, array $data, ?int $exceptId = null): void
    {
        $inicio = $this->inicioPeriodoAbierto($tarjeta);
        if ($data['fecha'] < $inicio->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'La fecha debe ser del período sin facturar (desde el '.$inicio->format('d/m/Y').').']);
        }
        $this->validarDisponible($tarjeta, $data, $exceptId);
    }

    /** Lo que se agrega o corrige en un corte ya facturado queda dentro de su período y de contado. */
    private function validarFacturado(TarjetaCredito $tarjeta, EstadoCuentaTarjeta $estado, array $data, ?int $movimientoId = null): void
    {
        if ($data['fecha'] < $estado->fecha_inicio->toDateString() || $data['fecha'] > $estado->fecha_corte->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'Ya se facturó en el corte del '.$estado->fecha_corte->format('d/m/Y').': la fecha debe quedar entre el '.$estado->fecha_inicio->format('d/m/Y').' y ese día.']);
        }
        if (($data['cuotas'] ?? 1) > 1) {
            throw ValidationException::withMessages(['cuotas' => 'En un corte ya facturado el movimiento va de contado.']);
        }
        $this->validarDisponible($tarjeta, $data, $movimientoId);
    }

    /**
     * Una tarjeta sí se puede sobregirar. Si quien llama manda "confirmar" (el formulario de movimientos), al pasarse
     * del disponible se pide confirmación (error "confirmar") y con confirmar = true se registra igual; si no lo
     * manda (avances, pagos de deudas o compromisos), pasarse del disponible es un error del monto.
     */
    private function validarDisponible(TarjetaCredito $tarjeta, array $data, ?int $exceptId = null): void
    {
        if (! in_array($data['tipo'], ['compra', 'avance'], true) || ! empty($data['confirmar'])) {
            return;
        }
        $disponible = max(0, $this->disponible($tarjeta, $exceptId));
        if ((float) $data['monto'] <= $disponible) {
            return;
        }
        if (! array_key_exists('confirmar', $data)) {
            throw ValidationException::withMessages(['monto' => 'El monto supera el disponible de la tarjeta ($ '.number_format($disponible, 2).').']);
        }
        throw ValidationException::withMessages(['confirmar' => 'El monto supera el disponible de la tarjeta: solo quedan $ '.number_format($disponible, 2)
            .' y quedaría sobregirada por $ '.number_format((float) $data['monto'] - $disponible, 2).'. ¿Deseas registrarlo de todas formas?']);
    }

    private function asegurarEditable(MovimientoTarjeta $movimiento): void
    {
        MovimientoVinculado::asegurarLibre($movimiento);
        abort_unless($this->esEditable($movimiento), 403, 'Este movimiento se gestiona desde su transferencia, su compromiso o su compra en cuotas.');
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
