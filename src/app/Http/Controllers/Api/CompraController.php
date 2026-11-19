<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompraPagoRequest;
use App\Http\Requests\ListaCompraItemRequest;
use App\Http\Resources\ListaCompraResource;
use App\Models\CompraPago;
use App\Models\ConsultaPrecios;
use App\Models\ListaCompra;
use App\Models\ListaCompraItem;
use App\Services\CicloEfectivoService;
use App\Services\CompraPagoService;
use App\Services\ListaCompraService;
use App\Services\PreciosEnLineaService;
use App\Services\PrecioService;
use App\Services\ProductoService;
use App\Services\RutaCompraService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Compras', description: 'Lista de compras (una abierta por hogar), pagar en cada tienda, precios en línea y ruta de compra')]
class CompraController extends Controller
{
    public function __construct(private readonly ListaCompraService $listas, private readonly CompraPagoService $pagos, private readonly PreciosEnLineaService $enLinea) {}

    #[OA\Get(path: '/api/compras', summary: 'La lista abierta (o null) y el historial (últimas 30)', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'abierta, historial, faltan (en la despensa)')])]
    public function index(Request $request)
    {
        $conteos = ['items' => fn ($q) => $q->where('extra', false), 'items as comprados_count' => fn ($q) => $q->where('extra', false)->where('comprado', true)];
        $abierta = $this->listas->abierta($request->user())?->loadCount($conteos);
        $historial = ListaCompra::delHogar($request->user())->whereNotNull('terminada_at')->withCount($conteos)->latest('terminada_at')->limit(30)->get();

        return response()->json([
            'abierta' => $abierta ? new ListaCompraResource($abierta->setAttribute('resumen', $this->listas->resumen($abierta))) : null,
            'historial' => ListaCompraResource::collection($historial),
            'faltan' => app(ProductoService::class)->resumen($request->user())['faltan'],
        ]);
    }

    #[OA\Post(path: '/api/compras', summary: 'Nueva lista con lo que falta en la despensa (o la abierta, si ya hay)', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'La lista')])]
    public function store(Request $request)
    {
        return (new ListaCompraResource($this->detalle($request, $this->listas->crear($request->user()))))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/compras/{id}', summary: 'Lista con pendientes, en el carrito, pagos, resumen y comparación de precios', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No existe o es de otro hogar')])]
    public function show(Request $request, ListaCompra $lista)
    {
        return new ListaCompraResource($this->detalle($request, $lista));
    }

    #[OA\Delete(path: '/api/compras/{id}', summary: 'Eliminar lista (sus gastos quedan como movimientos normales)', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada')])]
    public function destroy(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $this->listas->eliminar($lista);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/compras/{id}/faltantes', summary: 'Agregar lo que falta en la despensa', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista (agregados = cuántos)')])]
    public function faltantes(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $n = $this->listas->agregarFaltantes($lista, $request->user());

        return (new ListaCompraResource($this->detalle($request, $lista)))->additional(['agregados' => $n]);
    }

    #[OA\Post(path: '/api/compras/{id}/terminar', summary: 'Terminar: lo comprado sube en la despensa', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista terminada (subieron = cuántos productos)')])]
    public function terminar(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $n = $this->listas->terminar($lista);

        return (new ListaCompraResource($this->detalle($request, $lista)))->additional(['subieron' => $n]);
    }

    #[OA\Patch(path: '/api/compras/{id}/tienda', summary: 'Elegir en qué tienda se está (tienda_id o null)', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function tienda(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $request->validate(['tienda_id' => ['nullable', 'integer']]);
        $this->listas->elegirTienda($lista, $request->user(), $request->integer('tienda_id') ?: null);

        return new ListaCompraResource($this->detalle($request, $lista));
    }

    #[OA\Post(path: '/api/compras/{id}/items', summary: 'Agregar un producto (producto_id) o algo anotado (descripcion), con cantidad', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Lista'), new OA\Response(response: 422, description: 'Ya está o no es del hogar')])]
    public function agregarItem(ListaCompraItemRequest $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $this->listas->agregar($lista, $request->user(), $request->datos());

        return (new ListaCompraResource($this->detalle($request, $lista)))->response()->setStatusCode(201);
    }

    #[OA\Patch(path: '/api/compras/{id}/items/{item}', summary: 'Cambiar cantidad, precio (c/u), comprado (al carrito) o no_habia', tags: ['Compras'], security: [['bearerAuth' => []]],
        description: 'Al echarlo al carrito toma la tienda elegida y su último precio por unidad. no_habia requiere tienda. Lo ya pagado no se cambia (422).',
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function actualizarItem(ListaCompraItemRequest $request, ListaCompra $lista, ListaCompraItem $item)
    {
        $this->ownItem($request, $lista, $item);
        $this->listas->actualizarItem($item, $request->user(), $request->datos());

        return new ListaCompraResource($this->detalle($request, $lista));
    }

    #[OA\Delete(path: '/api/compras/{id}/items/{item}', summary: 'Quitar de la lista', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function quitarItem(Request $request, ListaCompra $lista, ListaCompraItem $item)
    {
        $this->ownItem($request, $lista, $item);
        $this->listas->quitar($item);

        return new ListaCompraResource($this->detalle($request, $lista));
    }

    #[OA\Get(path: '/api/compras/{id}/pagar', summary: 'Datos para «Pagar aquí» en la tienda elegida', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'items por pagar, total sugerido, sin_precio, origenes, categorias, categoria sugerida, ciclo'), new OA\Response(response: 404, description: 'Lista terminada o sin tienda')])]
    public function datosPago(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        abort_unless($lista->abierta() && $lista->tiendaActual, 404);
        $user = $request->user();
        $sugerido = $this->pagos->sugerido($lista);
        [$inicio, $fin] = app(CicloEfectivoService::class)->cicloConsultado($user);

        return response()->json([
            'tienda' => ['id' => $lista->tiendaActual->id, 'nombre' => $lista->tiendaActual->nombre],
            'items' => $sugerido['items']->map(fn (ListaCompraItem $i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad,
                'precio' => $i->precio !== null ? number_format((float) $i->precio, 2, '.', '') : null,
                'subtotal' => $i->subtotal() !== null ? number_format($i->subtotal(), 2, '.', '') : null])->values(),
            'total' => number_format($sugerido['total'], 2, '.', ''),
            'sin_precio' => $sugerido['sin_precio'],
            'origenes' => collect($this->pagos->origenes($user))->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values(),
            'categorias' => $this->pagos->categorias($user)->map(fn ($nombre, $id) => ['id' => $id, 'nombre' => $nombre])->values(),
            'categoria_sugerida' => $this->pagos->categoriaSugerida($user),
            'ciclo' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString()],
        ]);
    }

    #[OA\Post(path: '/api/compras/{id}/pagar', summary: '«Pagar aquí»: un gasto por categoría', tags: ['Compras'], security: [['bearerAuth' => []]],
        description: 'origen ("efectivo" | "cuenta:{id}" | "tarjeta:{id}"), fecha, user_id, categoria (id o "nueva:Nombre"), monto_lista, extras_modo (ninguno | total | detalle), '
            .'extras_total + extras_categoria, o extras[] {descripcion, precio, categoria}, confirmar (sobregirar tarjeta). Si una categoría pasa su presupuesto responde 422 con '
            .'"presupuesto" (incluye "clave"): reenviar con presupuestos[clave] = {modo: cubrir | pasarme, traspasos[]}.',
        responses: [new OA\Response(response: 201, description: 'Lista con el pago'), new OA\Response(response: 422, description: 'Validación, saldo, presupuesto o confirmar')])]
    public function pagar(CompraPagoRequest $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $pago = $this->pagos->pagar($lista, $request->user(), $request->validated());

        return (new ListaCompraResource($this->detalle($request, $lista)))->additional(['pago_id' => $pago->id])->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/compras/{id}/pagos/{pago}', summary: 'Deshacer un pago (borra sus gastos; lo comprado vuelve al carrito sin pagar)', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function deshacerPago(Request $request, ListaCompra $lista, CompraPago $pago)
    {
        $this->own($request, $lista);
        abort_unless($pago->lista_compra_id === $lista->id, 404);
        $this->pagos->deshacer($pago);

        return new ListaCompraResource($this->detalle($request, $lista));
    }

    #[OA\Post(path: '/api/compras/{id}/consultas', summary: 'Empezar (o continuar) la consulta de precios en línea', tags: ['Compras'], security: [['bearerAuth' => []]],
        description: 'Devuelve consulta_id y estado; luego llamar a /siguiente hasta terminada = true, esperando "espera" ms si viene (pausa de 2 s). al_dia = true si todo ya era de hoy. 3 consultas por hogar al día.',
        responses: [new OA\Response(response: 200, description: 'Consulta'), new OA\Response(response: 422, description: 'Sin tiendas en línea, nada ligado o límite del día')])]
    public function iniciarConsulta(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        $consulta = $this->enLinea->iniciar($request->user(), $lista);

        return response()->json($consulta ? ['al_dia' => false, 'consulta_id' => $consulta->id, 'estado' => $this->enLinea->estado($consulta)]
            : ['al_dia' => true, 'message' => 'Los precios en línea de esta lista ya son de hoy.']);
    }

    #[OA\Post(path: '/api/compras/{id}/consultas/{consulta}/siguiente', summary: 'Consultar el siguiente producto', tags: ['Compras'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'hechos, total, nuevos, reusados, fallas, terminada, espera (ms, si fue muy pronto), producto')])]
    public function siguiente(Request $request, ListaCompra $lista, ConsultaPrecios $consulta)
    {
        $this->own($request, $lista);
        abort_unless($consulta->esDelHogar($request->user()) && $consulta->lista_compra_id === $lista->id, 404);

        return response()->json($this->enLinea->siguiente($consulta, $request->user()));
    }

    #[OA\Get(path: '/api/compras/{id}/ruta', summary: 'Ruta de compra: en qué tiendas comprar lo que falta', tags: ['Compras'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'costo', in: 'query', description: 'Lo que cuesta ir a una tienda más (por defecto 2.00)', schema: new OA\Schema(type: 'number'))],
        responses: [new OA\Response(response: 200, description: 'paradas[] {tienda, subtotal, items[]}, total, costo_ir, una_tienda, ahorro, sin_precio[], viejos')])]
    public function ruta(Request $request, ListaCompra $lista)
    {
        $this->own($request, $lista);
        abort_unless($lista->abierta(), 404);
        $request->validate(['costo' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $r = app(RutaCompraService::class)->calcular($lista, $request->user(), round((float) $request->input('costo', 2), 2));
        $dinero = fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', '');
        $item = fn ($i) => ['id' => $i->id, 'descripcion' => $i->descripcion, 'cantidad' => $i->cantidad];
        $plan = $r['plan'];

        return response()->json([
            'costo_visita' => $dinero($r['costo_visita']),
            'paradas' => collect($plan['tiendas'] ?? [])->map(fn ($p) => ['tienda_id' => $p['tienda']->id, 'tienda' => $p['tienda']->nombre, 'subtotal' => $dinero($p['subtotal']),
                'items' => collect($p['items'])->map(fn ($l) => $item($l['item']) + ['por_unidad' => $dinero($l['por_unidad']), 'subtotal' => $dinero($l['subtotal']),
                    'cuando' => $l['cuando'], 'viejo' => $l['viejo']])->values()])->values(),
            'total' => $dinero($plan['total'] ?? null),
            'costo_ir' => $dinero($plan['costo_ir'] ?? null),
            'una_tienda' => $r['una_tienda'] ? ['tienda_id' => $r['una_tienda']['tienda']->id, 'tienda' => $r['una_tienda']['tienda']->nombre,
                'total' => $dinero($r['una_tienda']['total']), 'sin_cubrir' => $r['una_tienda']['sin_cubrir']] : null,
            'ahorro' => $dinero($r['ahorro']),
            'sin_precio' => collect($plan['sin_cubrir'] ?? [])->merge($r['sin_precio'])->map($item)->values(),
            'viejos' => $r['viejos'],
        ]);
    }

    private function detalle(Request $request, ListaCompra $lista): ListaCompra
    {
        $this->own($request, $lista);
        $lista = $lista->fresh(['tiendaActual']);
        $partes = $this->listas->partes($lista);
        $comparacion = $lista->abierta() ? $partes['pendientes']->filter(fn ($i) => $i->producto)
            ->mapWithKeys(fn ($i) => [$i->id => app(PrecioService::class)->comparacion($i->producto, $request->user())])->all() : [];

        return $lista->setAttribute('partes', $partes)->setAttribute('resumen', $this->listas->resumen($lista))
            ->setAttribute('pagosDetalle', $this->pagos->deLista($lista))->setAttribute('comparacion', $comparacion)
            ->setAttribute('consultas_restantes', $this->enLinea->restantesHoy($request->user()))->setAttribute('con_detalle', true);
    }

    private function own(Request $request, ListaCompra $lista): void
    {
        abort_unless($lista->esDelHogar($request->user()), 404);
    }

    private function ownItem(Request $request, ListaCompra $lista, ListaCompraItem $item): void
    {
        $this->own($request, $lista);
        abort_unless($item->lista_compra_id === $lista->id, 404);
    }
}
