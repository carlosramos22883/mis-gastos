<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompraPagoRequest;
use App\Models\CompraPago;
use App\Models\ListaCompra;
use App\Services\CicloEfectivoService;
use App\Services\CompraPagoService;
use Illuminate\Http\Request;

/** «Pagar aquí» en una lista de compras (la lógica está en CompraPagoService). */
class CompraPagoController extends Controller
{
    public function __construct(private readonly CompraPagoService $pagos) {}

    public function create(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        abort_unless($lista->abierta() && $lista->tiendaActual, 404);
        $user = $request->user();
        $origenes = $this->pagos->origenes($user);
        abort_if($origenes === [], 403, 'No tienes permiso para registrar gastos.');
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);

        return view('compras._pagar', [
            'lista' => $lista,
            'sugerido' => $this->pagos->sugerido($lista),
            'origenes' => $origenes,
            'categorias' => $this->pagos->categorias($user)->all(),
            'categoria' => $this->pagos->categoriaSugerida($user),
            'cycleStart' => $inicio,
            'cycleEnd' => $fin,
        ]);
    }

    public function store(CompraPagoRequest $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $pago = $this->pagos->pagar($lista, $request->user(), $request->validated());
        $n = $pago->movimientos->count();

        return response()->json(['success' => true, 'message' => 'Pagaste $ '.number_format((float) $pago->total, 2).' en '.$pago->tienda->nombre.'. '
            .($n === 1 ? 'Se registró el gasto en «'.$pago->movimientos->first()->categoria->nombre.'».' : "Se registraron {$n} gastos, uno por categoría.")]);
    }

    public function destroy(Request $request, ListaCompra $lista, CompraPago $pago)
    {
        $this->own($request, $lista);
        abort_unless($pago->lista_compra_id === $lista->id, 404);
        $this->pagos->deshacer($pago);

        return response()->json(['success' => true, 'message' => 'Pago deshecho. Sus gastos se eliminaron y lo comprado volvió al carrito sin pagar.']);
    }

    private function own(Request $request, ListaCompra $lista): void
    {
        abort_unless($lista->esDelHogar($request->user()), 404);
    }
}
