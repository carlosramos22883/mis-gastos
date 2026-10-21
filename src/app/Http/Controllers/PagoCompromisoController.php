<?php

namespace App\Http\Controllers;

use App\Http\Requests\PagoCompromisoRequest;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\Prestamo;
use App\Services\CicloEfectivoService;
use App\Services\PagoCompromisoService;
use Illuminate\Http\Request;

/** "Pagar" un compromiso desde efectivo, una cuenta o una tarjeta (la lógica está en PagoCompromisoService). */
class PagoCompromisoController extends Controller
{
    public function create(Request $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        // La cuota de un préstamo se paga con su desglose (capital, interés, seguros)
        $prestamo = $compromiso->compromiso_recurrente_id ? Prestamo::where('compromiso_recurrente_id', $compromiso->compromiso_recurrente_id)->first() : null;
        if ($prestamo && $request->user()->can('prestamos.create')) {
            return view('prestamos._pago_form', app(PrestamoController::class)->pagoData($request, $prestamo));
        }
        $user = $request->user();
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);
        $origenes = app(PagoCompromisoService::class)->origenes($user, $compromiso);
        abort_if($origenes === [], 403);

        return view('compromisos._pago_form', [
            'compromiso' => $compromiso,
            'origenes' => $origenes,
            'origen' => $compromiso->medio_pago === 'transferencia' ? (array_keys($origenes)[1] ?? 'efectivo') : array_key_first($origenes),
            'categorias' => CategoriaPersonal::where('hogar_id', $user->hogar_id)->where('activo', true)->where('tipo', 'egreso')->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'cycleStart' => $inicio,
            'cycleEnd' => $fin,
        ]);
    }

    public function store(PagoCompromisoRequest $request, Compromiso $compromiso)
    {
        $this->own($request, $compromiso);
        $compromiso = app(PagoCompromisoService::class)->pagar($request->user(), $compromiso, $request->validated());

        return response()->json([
            'success' => true,
            'message' => $compromiso->estado === Compromiso::PAGADO ? 'Compromiso pagado por completo.' : 'Abono registrado. Pendiente: $ '.number_format((float) $compromiso->saldo_pendiente, 2),
            'highlight_id' => $compromiso->id,
        ]);
    }

    private function own(Request $request, Compromiso $compromiso): void
    {
        abort_unless($compromiso->esDelHogar($request->user()), 404);
        abort_unless($compromiso->estado === Compromiso::PENDIENTE, 403, 'Este compromiso ya no está pendiente.');
    }
}
