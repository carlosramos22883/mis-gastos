<?php

namespace App\Http\Controllers;

use App\Models\EstadoCuentaItem;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Services\CicloEfectivoService;
use App\Services\CuentaService;
use App\Services\MovimientoEfectivoService;
use App\Services\TarjetaService;
use App\Support\Volver;
use Illuminate\Http\Request;

/**
 * Lleva a la pantalla donde vive un movimiento (efectivo, cuenta o tarjeta), en su ciclo y en la página
 * del listado donde aparece, y lo resalta. Conserva "desde" para poder regresar (App\Support\Volver).
 */
class IrAMovimientoController extends Controller
{
    private const POR_PAGINA = 10; // el de las tablas por defecto

    public function __invoke(Request $request, string $clase, int $id)
    {
        $user = $request->user();
        $desde = Volver::parametro($request);

        if ($clase === 'efectivo') {
            abort_unless($user->can('efectivo.view'), 403);
            $m = MovimientoEfectivo::delHogar($user)->findOrFail($id);
            [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user, $m->fecha->toDateString()) ?? abort(404);
            $ids = app(MovimientoEfectivoService::class)->consultar($user, $inicio, $fin)->pluck('movimientos_efectivo.id');

            return redirect()->route('efectivo.index', ['cycle' => $m->fecha->toDateString(), 'page' => $this->pagina($ids, $m->id), 'resaltar' => $m->id] + $desde);
        }

        if ($clase === 'cuenta') {
            abort_unless($user->can('cuentas.view'), 403);
            $m = MovimientoCuenta::delHogar($user)->with('cuenta')->findOrFail($id);
            [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user, $m->fecha->toDateString()) ?? abort(404);
            $ids = app(CuentaService::class)->consultarMovimientos($m->cuenta, $inicio, $fin)->pluck('movimientos_cuenta.id');

            return redirect()->route('cuentas.show', [$m->cuenta, 'cycle' => $m->fecha->toDateString(), 'page' => $this->pagina($ids, $m->id), 'resaltar' => $m->id] + $desde);
        }

        abort_unless($clase === 'tarjeta' && $user->can('tarjetas.view'), 404);
        $m = MovimientoTarjeta::delHogar($user)->findOrFail($id);
        // Ya facturado: se abre el estado de cuenta donde quedó (el más reciente si va en cuotas)
        $estado = EstadoCuentaItem::where('movimiento_tarjeta_id', $m->id)->latest('id')->value('estado_cuenta_tarjeta_id');
        $sinFacturar = $m->fecha->toDateString() >= app(TarjetaService::class)->inicioPeriodoAbierto($m->tarjeta)->toDateString();

        return redirect()->route('tarjetas.show', [$m->tarjeta_credito_id, 'resaltar' => $m->id] + ($estado && ! $sinFacturar ? ['estado' => $estado] : []) + $desde);
    }

    private function pagina($ids, int $id): int
    {
        $posicion = $ids->search($id);

        return $posicion === false ? 1 : intdiv($posicion, self::POR_PAGINA) + 1;
    }
}
