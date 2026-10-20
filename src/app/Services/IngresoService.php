<?php

namespace App\Services;

use App\Models\CategoriaPersonal;
use App\Models\FuenteIngreso;
use App\Models\Ingreso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\Prestamo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ingresos con desglose (sueldos, servicios profesionales…).
 *
 *  - Cada fuente tiene una plantilla de descuentos y adiciones (monto fijo, o % del bruto con tope).
 *  - Al registrar un pago recibido: neto = bruto + adiciones − descuentos; el neto entra como ingreso
 *    (categoría "Sueldo y trabajos") al efectivo o a una cuenta.
 *  - Un descuento ligado a un préstamo que se paga todo por planilla registra ese pago del préstamo
 *    (interés del período; el resto a capital). Si el préstamo es mixto (planilla + ventanilla), su
 *    pago se registra al pagar la ventanilla y aquí solo queda el detalle.
 */
class IngresoService
{
    public const CATEGORIA = 'Sueldo y trabajos';

    public function __construct(
        private readonly MovimientoEfectivoService $efectivo,
        private readonly CuentaService $cuentas,
        private readonly PrestamoService $prestamos,
    ) {}

    /** @param array<int, array{nombre: string, tipo: string, monto?: ?numeric, porcentaje?: ?numeric, tope?: ?numeric, prestamo_id?: ?int}> $conceptos */
    public function crearFuente(User $user, array $data, array $conceptos = []): FuenteIngreso
    {
        return DB::transaction(function () use ($user, $data, $conceptos) {
            $fuente = $user->fuentesIngreso()->create($data + ['user_id' => $user->id]);
            $this->guardarConceptos($fuente, $conceptos);

            return $fuente;
        });
    }

    public function actualizarFuente(FuenteIngreso $fuente, array $data, ?array $conceptos = null): FuenteIngreso
    {
        return DB::transaction(function () use ($fuente, $data, $conceptos) {
            $fuente->update($data);
            if ($conceptos !== null) {
                $this->guardarConceptos($fuente, $conceptos);
            }

            return $fuente;
        });
    }

    public function eliminarFuente(FuenteIngreso $fuente): void
    {
        if ($fuente->ingresos()->exists()) {
            throw ValidationException::withMessages(['fuente' => 'Esta fuente ya tiene pagos registrados. Desactívala en lugar de eliminarla.']);
        }
        $fuente->delete();
    }

    /** Líneas propuestas para un bruto (de la plantilla) y el neto resultante. */
    public function propuesta(FuenteIngreso $fuente, ?float $bruto = null): array
    {
        $bruto ??= (float) $fuente->monto_bruto;
        $lineas = $fuente->conceptos->map(fn ($c) => [
            'nombre' => $c->nombre, 'tipo' => $c->tipo, 'monto' => $c->montoPara($bruto), 'prestamo_id' => $c->prestamo_id,
            'porcentaje' => $c->porcentaje !== null ? (float) $c->porcentaje : null, 'tope' => $c->tope !== null ? (float) $c->tope : null,
        ])->values()->all();

        return ['bruto' => round($bruto, 2), 'lineas' => $lineas, 'neto' => $this->neto($bruto, $lineas)];
    }

    public function neto(float $bruto, array $lineas): float
    {
        return round($bruto + collect($lineas)->sum(fn ($l) => ($l['tipo'] === 'adicion' ? 1 : -1) * (float) $l['monto']), 2);
    }

    /**
     * @param  array{fecha: string, bruto: numeric, lineas?: array, destino: string, comentario?: ?string, user_id?: ?int}  $data
     *                                                                                                                             destino: "efectivo" | "cuenta:{id}" | "ninguno"
     */
    public function registrar(User $user, FuenteIngreso $fuente, array $data): Ingreso
    {
        $lineas = collect($data['lineas'] ?? [])->filter(fn ($l) => trim((string) ($l['nombre'] ?? '')) !== '' && (float) ($l['monto'] ?? 0) > 0)->values();
        $neto = $this->neto((float) $data['bruto'], $lineas->all());
        if ($neto < 0) {
            throw ValidationException::withMessages(['bruto' => 'Los descuentos superan el bruto.']);
        }

        return DB::transaction(function () use ($user, $fuente, $data, $lineas, $neto) {
            $quien = $data['user_id'] ?? $fuente->user_id;
            $movimiento = ['descripcion' => $fuente->nombre, 'monto' => $neto, 'fecha' => $data['fecha'], 'tipo' => 'ingreso', 'user_id' => $quien,
                'categoria_personal_id' => $this->categoria($user)];
            $ingreso = ['user_id' => $quien, 'fecha' => $data['fecha'], 'bruto' => $data['bruto'], 'neto' => $neto, 'comentario' => $data['comentario'] ?? null];

            if ($data['destino'] === 'efectivo' && $neto > 0) {
                $ingreso += ['destino' => 'efectivo', 'movimiento_efectivo_id' => $this->efectivo->registrar($user, $movimiento)->id];
            } elseif (preg_match('/^cuenta:(\d+)$/', $data['destino'], $m) && $neto > 0) {
                $cuenta = $user->cuentas()->where('activo', true)->find($m[1]) ?? throw ValidationException::withMessages(['destino' => 'Selecciona una cuenta válida.']);
                $ingreso += ['destino' => 'cuenta', 'movimiento_cuenta_id' => $this->cuentas->registrarMovimiento($cuenta, $movimiento)->id];
            } else {
                $ingreso += ['destino' => 'ninguno'];
            }
            $registro = $fuente->ingresos()->create($ingreso);

            foreach ($lineas as $l) {
                $pagoId = null;
                $prestamo = ! empty($l['prestamo_id']) ? $user->prestamos()->find($l['prestamo_id']) : null;
                if ($prestamo && $l['tipo'] === 'descuento' && $prestamo->medio_pago === 'planilla') {
                    $pagoId = $this->pagarPrestamo($user, $prestamo, (float) $l['monto'], $data['fecha'], $quien)->id;
                }
                $registro->lineas()->create(['nombre' => trim($l['nombre']), 'tipo' => $l['tipo'], 'monto' => round((float) $l['monto'], 2),
                    'prestamo_pago_id' => $pagoId]);
            }

            return $registro;
        });
    }

    /** Elimina el pago recibido: su ingreso y los pagos de préstamos que registró. */
    public function eliminar(Ingreso $ingreso): void
    {
        DB::transaction(function () use ($ingreso) {
            foreach ($ingreso->lineas()->whereNotNull('prestamo_pago_id')->with('prestamoPago')->get() as $linea) {
                if ($linea->prestamoPago) {
                    $this->prestamos->eliminarPago($linea->prestamoPago);
                }
            }
            $movimiento = $ingreso->movimientoEfectivo ?? $ingreso->movimientoCuenta;
            $ingreso->delete();
            match (true) {
                $movimiento instanceof MovimientoEfectivo => $this->efectivo->eliminar($movimiento),
                $movimiento instanceof MovimientoCuenta => $this->cuentas->eliminarMovimiento($movimiento),
                default => null,
            };
        });
    }

    /** Totales del año: bruto, neto y cada concepto (útil para la declaración de renta). */
    public function resumenAnual(FuenteIngreso $fuente, int $anio): array
    {
        $ingresos = $fuente->ingresos()->with('lineas')->whereYear('fecha', $anio)->get();
        $conceptos = $ingresos->flatMap->lineas->groupBy(fn ($l) => $l->tipo.'|'.$l->nombre)
            ->map(fn ($g) => ['nombre' => $g->first()->nombre, 'tipo' => $g->first()->tipo, 'monto' => round((float) $g->sum('monto'), 2)])
            ->sortBy(fn ($c) => ($c['tipo'] === 'adicion' ? '0' : '1').$c['nombre'])->values();

        return ['anio' => $anio, 'pagos' => $ingresos->count(), 'bruto' => round((float) $ingresos->sum('bruto'), 2),
            'neto' => round((float) $ingresos->sum('neto'), 2), 'conceptos' => $conceptos->all()];
    }

    /** Próxima fecha de pago de la fuente (o null sin día de pago). */
    public function proximaFecha(FuenteIngreso $fuente, Carbon $hoy): ?Carbon
    {
        $dias = array_filter([$fuente->dia_pago, $fuente->frecuencia === 'quincenal' ? $fuente->dia_pago_2 : null]);
        if (! $dias) {
            return null;
        }
        $candidatas = [];
        foreach ([0, 1] as $suma) {
            $mes = $hoy->copy()->startOfMonth()->addMonthsNoOverflow($suma);
            foreach ($dias as $d) {
                $candidatas[] = $mes->copy()->day(min($d, $mes->daysInMonth));
            }
        }

        return collect($candidatas)->filter(fn (Carbon $f) => $f->toDateString() >= $hoy->toDateString())->sort()->first();
    }

    /** Pago de un préstamo descontado de planilla: interés del período y el resto a capital (los cargos van primero). */
    private function pagarPrestamo(User $user, Prestamo $prestamo, float $monto, string $fecha, int $quien)
    {
        $s = $this->prestamos->sugerencia($prestamo, Carbon::parse($fecha));
        $cargos = min($monto, $s['cargos']);
        $interes = min($monto - $cargos, $s['interes']);
        $capital = round($monto - $cargos - $interes, 2);

        try {
            return $this->prestamos->registrarPago($user, $prestamo, ['fecha' => $fecha, 'capital' => $capital, 'interes' => round($interes, 2),
                'cargos' => round($cargos, 2), 'mora' => 0, 'origen' => 'planilla', 'user_id' => $quien, 'comentario' => 'Descontado de planilla']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['lineas' => "No se pudo registrar el pago de «{$prestamo->nombre}»: ".collect($e->errors())->flatten()->first()]);
        }
    }

    private function guardarConceptos(FuenteIngreso $fuente, array $conceptos): void
    {
        $fuente->conceptos()->delete();
        foreach (array_values($conceptos) as $i => $c) {
            if (trim((string) ($c['nombre'] ?? '')) === '') {
                continue;
            }
            $fuente->conceptos()->create([
                'nombre' => trim($c['nombre']), 'tipo' => ($c['tipo'] ?? 'descuento') === 'adicion' ? 'adicion' : 'descuento',
                'monto' => ($c['monto'] ?? '') !== '' ? $c['monto'] : null,
                'porcentaje' => ($c['porcentaje'] ?? '') !== '' ? $c['porcentaje'] : null,
                'tope' => ($c['tope'] ?? '') !== '' ? $c['tope'] : null,
                'prestamo_id' => ($c['prestamo_id'] ?? '') !== '' ? $c['prestamo_id'] : null,
                'orden' => $i,
            ]);
        }
    }

    private function categoria(User $user): int
    {
        return CategoriaPersonal::firstOrCreate(
            ['hogar_id' => $user->hogar_id, 'nombre' => self::CATEGORIA, 'tipo' => 'ingreso'],
            ['user_id' => $user->id, 'color' => '#16A34A', 'activo' => true],
        )->id;
    }
}
