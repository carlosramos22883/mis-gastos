<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactoRequest;
use App\Http\Requests\DeudaPagoRequest;
use App\Http\Requests\DeudaRequest;
use App\Http\Resources\ContactoResource;
use App\Models\Contacto;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Services\ContactoService;
use App\Services\DeudorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Contactos', description: 'Personas, tiendas, empresas e instituciones del hogar, y lo que nos deben')]
class ContactoController extends Controller
{
    public function __construct(private readonly ContactoService $contactos, private readonly DeudorService $deudas) {}

    #[OA\Get(path: '/api/contactos', summary: 'Contactos con lo que deben', tags: ['Contactos'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'filtro', in: 'query', description: '"deben": solo los que tienen deudas', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function index(Request $request)
    {
        $lista = $request->user()->contactos()->with(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')])
            ->when($request->get('filtro') === 'deben', fn ($q) => $q->whereHas('deudas'))
            ->orderByDesc('activo')->orderBy('nombre')->orderBy('apellido')->get()
            ->each(fn (Contacto $c) => $this->deudas->resumir($c));

        return ContactoResource::collection($lista);
    }

    #[OA\Post(path: '/api/contactos', summary: 'Crear contacto (multipart si lleva foto)', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Creado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function store(ContactoRequest $request)
    {
        $contacto = $this->contactos->crear($request->user(), $request->datos(), $request->file('foto'));

        return (new ContactoResource($this->detalle($request, $contacto)))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/contactos/{id}', summary: 'Contacto con sus deudas y pagos', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Detalle'), new OA\Response(response: 404, description: 'No existe o es de otro hogar')])]
    public function show(Request $request, Contacto $contacto)
    {
        return new ContactoResource($this->detalle($request, $contacto));
    }

    #[OA\Put(path: '/api/contactos/{id}', summary: 'Actualizar contacto (para cambiar la foto: POST con _method=PUT y multipart)', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado')])]
    public function update(ContactoRequest $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->contactos->actualizar($contacto, $request->datos(), $request->file('foto'), $request->boolean('quitar_foto'));

        return new ContactoResource($this->detalle($request, $contacto));
    }

    #[OA\Delete(path: '/api/contactos/{id}', summary: 'Eliminar contacto (solo sin historial)', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 422, description: 'Tiene historial: desactivarlo')])]
    public function destroy(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->contactos->eliminar($contacto);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/contactos/{id}/foto', summary: 'Foto del contacto (privada)', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Imagen'), new OA\Response(response: 404, description: 'Sin foto')])]
    public function foto(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        abort_unless($contacto->foto && Storage::disk(ContactoService::DISCO)->exists($contacto->foto), 404);

        return Storage::disk(ContactoService::DISCO)->response($contacto->foto);
    }

    #[OA\Post(path: '/api/contactos/{id}/deudas', summary: 'Prestar o anotar una deuda', tags: ['Contactos'], security: [['bearerAuth' => []]],
        description: 'origen: "efectivo" | "cuenta:{id}" | "tarjeta:{id}" (crea ese gasto) | "ninguno" (solo anotar).',
        responses: [new OA\Response(response: 201, description: 'Contacto actualizado'), new OA\Response(response: 422, description: 'Error de validación')])]
    public function guardarDeuda(DeudaRequest $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->deudas->registrarDeuda($request->user(), $contacto, $request->validated());

        return (new ContactoResource($this->detalle($request, $contacto)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/deudas/{id}', summary: 'Eliminar una deuda sin pagos (y el gasto que generó)', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminada'), new OA\Response(response: 422, description: 'Tiene pagos')])]
    public function eliminarDeuda(Request $request, Deuda $deuda)
    {
        abort_unless($deuda->esDelHogar($request->user()), 404);
        $this->deudas->eliminarDeuda($deuda);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/contactos/{id}/pagos', summary: 'Registrar un pago del contacto', tags: ['Contactos'], security: [['bearerAuth' => []]],
        description: 'destino: "efectivo" | "cuenta:{id}" | "ninguno". Sin deuda_id se reparte de la deuda más antigua a la más reciente.',
        responses: [new OA\Response(response: 201, description: 'Contacto actualizado'), new OA\Response(response: 422, description: 'Supera lo pendiente')])]
    public function guardarPago(DeudaPagoRequest $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->deudas->registrarPago($request->user(), $contacto, $request->validated());

        return (new ContactoResource($this->detalle($request, $contacto)))->response()->setStatusCode(201);
    }

    #[OA\Delete(path: '/api/deuda-pagos/{id}', summary: 'Eliminar un pago (y su ingreso)', tags: ['Contactos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado')])]
    public function eliminarPago(Request $request, DeudaPago $pago)
    {
        abort_unless($pago->esDelHogar($request->user()), 404);
        $this->deudas->eliminarPago($pago);

        return response()->noContent();
    }

    private function detalle(Request $request, Contacto $contacto): Contacto
    {
        $this->own($request, $contacto);
        $contacto = $contacto->fresh();
        $contacto->load(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')->with('pagos')->orderBy('fecha')->orderBy('id')]);

        return $this->deudas->resumir($contacto)->setAttribute('con_detalle', true);
    }

    private function own(Request $request, Contacto $contacto): void
    {
        abort_unless($contacto->esDelHogar($request->user()), 404);
    }
}
