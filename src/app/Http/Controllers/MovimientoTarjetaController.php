<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovimientoTarjetaRequest;
use App\Models\CategoriaPersonal;
use App\Models\EstadoCuentaTarjeta;
use App\Models\MovimientoTarjeta;
use App\Models\TarjetaCredito;
use App\Services\DeudorService;
use App\Services\TarjetaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovimientoTarjetaController extends Controller
{
    public function __construct(private readonly TarjetaService $tarjetas) {}

    public function create(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        abort_unless($tarjeta->activo, 403, 'La tarjeta está inactiva.');

        return view('tarjetas._movimiento_form', ['tarjeta' => $tarjeta, 'tipo' => $request->query('tipo', 'compra'), 'facturado' => $this->estado($request, $tarjeta)] + $this->formData($request, $tarjeta));
    }

    public function store(MovimientoTarjetaRequest $request, TarjetaCredito $tarjeta, DeudorService $deudas)
    {
        $this->own($request, $tarjeta);
        abort_unless($tarjeta->activo, 403, 'La tarjeta está inactiva.');
        $estado = $this->estado($request, $tarjeta);
        $meLoDebe = (bool) $request->validated('me_lo_debe');
        DB::transaction(function () use ($request, $tarjeta, $estado, $meLoDebe, $deudas) {
            $movimiento = $this->tarjetas->registrarMovimiento($tarjeta, $request->safe()->except('me_lo_debe'), $estado);
            if ($meLoDebe) { // la compra la hizo el contacto: queda como deuda suya
                $contacto = $request->user()->contactos()->findOrFail($movimiento->contacto_id);
                $deudas->vincularMovimiento($request->user(), $movimiento, $contacto, ['descripcion' => $movimiento->descripcion, 'monto' => $movimiento->monto]);
            }
        });

        return response()->json(['success' => true, 'message' => ($estado ? 'Movimiento agregado al corte del '.$estado->fecha_corte->format('d/m/Y') : 'Movimiento registrado')
            .($meLoDebe ? ' y anotado como deuda' : '').'. Disponible: $ '.number_format($this->tarjetas->disponible($tarjeta), 2)]);
    }

    public function edit(Request $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        abort_unless($this->tarjetas->esEditable($movimiento), 403);

        // Si ya se facturó, se corrige dentro del período de su corte
        $facturado = $movimiento->items()->first()?->estadoCuenta;

        return view('tarjetas._movimiento_form', ['tarjeta' => $tarjeta, 'movimiento' => $movimiento, 'tipo' => $movimiento->tipo, 'facturado' => $facturado] + $this->formData($request, $tarjeta));
    }

    public function update(MovimientoTarjetaRequest $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        $this->tarjetas->actualizarMovimiento($movimiento, $request->safe()->except('me_lo_debe'));

        return response()->json(['success' => true, 'message' => 'Movimiento actualizado correctamente.']);
    }

    public function destroy(Request $request, TarjetaCredito $tarjeta, MovimientoTarjeta $movimiento)
    {
        $this->own($request, $tarjeta, $movimiento);
        $this->tarjetas->eliminarMovimiento($movimiento);

        return response()->json(['success' => true, 'message' => 'Movimiento eliminado correctamente.']);
    }

    private function formData(Request $request, TarjetaCredito $tarjeta): array
    {
        return [
            'categorias' => CategoriaPersonal::where('hogar_id', $request->user()->hogar_id)->where('activo', true)->where('tipo', 'egreso')->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'desde' => $this->tarjetas->inicioPeriodoAbierto($tarjeta),
            'disponible' => $this->tarjetas->disponible($tarjeta),
        ];
    }

    /** ?estado=ID: el movimiento va a ese corte ya facturado (algo que faltó anotar). */
    private function estado(Request $request, TarjetaCredito $tarjeta): ?EstadoCuentaTarjeta
    {
        return $request->filled('estado') ? $tarjeta->estadosCuenta()->findOrFail((int) $request->query('estado')) : null;
    }

    private function own(Request $request, TarjetaCredito $tarjeta, ?MovimientoTarjeta $movimiento = null): void
    {
        abort_unless($tarjeta->esDelHogar($request->user()), 404);
        abort_if($movimiento && $movimiento->tarjeta_credito_id !== $tarjeta->id, 404);
    }
}
