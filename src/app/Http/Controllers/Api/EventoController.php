<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventoCobroRequest;
use App\Http\Requests\EventoPagadoRequest;
use App\Http\Requests\EventoRequest;
use App\Http\Resources\EventoResource;
use App\Models\Evento;
use App\Models\EventoParticipante;
use App\Services\EventoService;
use App\Support\EventoImagen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Eventos', description: 'Dividir la cuenta de una comida o celebración: participantes, consumos, propina y festejados')]
class EventoController extends Controller
{
    public function __construct(private readonly EventoService $eventos) {}

    #[OA\Get(path: '/api/eventos', summary: 'Eventos del hogar (del más reciente al más antiguo), con lo que le toca a cada uno', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Lista')])]
    public function index(Request $request)
    {
        return EventoResource::collection($this->eventos->listar($request->user()));
    }

    #[OA\Get(path: '/api/eventos/opciones', summary: 'Para el formulario: participantes posibles ("miembro:{id}" o id de contacto), de dónde pagar, a dónde cobrar y categorías de gasto', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Opciones')])]
    public function opciones(Request $request)
    {
        $user = $request->user();
        $origenes = ['efectivo' => 'Efectivo'];
        foreach ($user->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
            $origenes['cuenta:'.$cuenta->id] = $cuenta->nombre;
        }
        $destinos = $origenes + ['ninguno' => 'Solo anotar'];
        foreach ($user->tarjetasCredito()->where('activo', true)->orderBy('nombre')->get() as $tarjeta) {
            $origenes['tarjeta:'.$tarjeta->id] = 'Tarjeta '.$tarjeta->nombre;
        }
        $lista = fn (array $o) => collect($o)->map(fn ($etiqueta, $valor) => ['valor' => (string) $valor, 'etiqueta' => is_array($etiqueta) ? $etiqueta['label'] : $etiqueta])->values();

        return response()->json(['data' => [
            'participantes' => $lista($this->eventos->opcionesParticipantes($user)),
            'origenes' => $lista($origenes + ['ninguno' => 'Solo anotar (el dinero ya está registrado)']),
            'destinos' => $lista($destinos),
        ]]);
    }

    #[OA\Get(path: '/api/eventos/{id}', summary: 'Detalle de un evento', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Evento'), new OA\Response(response: 404, description: 'No encontrado')])]
    public function show(Request $request, Evento $evento)
    {
        $this->own($request, $evento);

        return new EventoResource($evento->load('participantes.user', 'participantes.contacto', 'participantes.deuda', 'consumos.participantes'));
    }

    #[OA\Post(path: '/api/eventos', summary: 'Crear un evento', tags: ['Eventos'], security: [['bearerAuth' => []]],
        description: 'Como una tabla. participantes: [{quien: "miembro:{id}" | id de contacto | "nuevo:Nombre", es_festejado}]; pagador: posición de quien pagó; consumos (productos): [{descripcion, precio (unitario), cantidades: [cuántos pidió cada participante, en su orden]}]; propina_tipo: ninguna | porcentaje (del consumo de cada uno) | monto (dividido entre todos) con propina_valor. La foto de la cuenta se sube aparte (POST /api/eventos/{id}/foto). Si pagó un miembro del hogar: origen ("efectivo" | "cuenta:{id}" | "tarjeta:{id}" | "ninguno") y categoria_personal_id (si no es "ninguno"). Una tarjeta sin disponible responde 422 "confirmar": reenviar con confirmar = true.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'nombre', type: 'string', example: 'Cumpleaños de María'), new OA\Property(property: 'fecha', type: 'string', format: 'date'),
            new OA\Property(property: 'lugar', type: 'string'), new OA\Property(property: 'propina_tipo', type: 'string', example: 'porcentaje'),
            new OA\Property(property: 'propina_valor', type: 'string', example: '10'), new OA\Property(property: 'pagador', type: 'integer', example: 0),
            new OA\Property(property: 'participantes', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'consumos', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'origen', type: 'string', example: 'tarjeta:5'), new OA\Property(property: 'categoria_personal_id', type: 'integer'),
        ])),
        responses: [new OA\Response(response: 201, description: 'Creado'), new OA\Response(response: 422, description: 'Datos inválidos')])]
    public function store(EventoRequest $request)
    {
        $evento = $this->eventos->guardar($request->user(), $request->validated());

        return (new EventoResource($evento))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/eventos/{id}', summary: 'Actualizar un evento (mismos campos que crear; sus deudas y su pago se ajustan)', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 422, description: 'Datos inválidos o cobros que lo impiden')])]
    public function update(EventoRequest $request, Evento $evento)
    {
        $this->own($request, $evento);

        return new EventoResource($this->eventos->guardar($request->user(), $request->validated(), $evento));
    }

    #[OA\Delete(path: '/api/eventos/{id}', summary: 'Eliminar un evento (y su pago y deudas); si ya hay cobros, primero hay que eliminarlos', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 422, description: 'Tiene cobros')])]
    public function destroy(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $this->eventos->eliminar($evento);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/eventos/{id}/imagen', summary: 'Imagen PNG del cuadro (lo que le toca a cada uno) para compartir', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'image/png')])]
    public function imagen(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $png = app(EventoImagen::class)->png($evento, $this->eventos->resumen($evento), $request->user()->monedaPreferida?->simbolo ?? '$');

        return response($png, 200, ['Content-Type' => 'image/png']);
    }

    #[OA\Get(path: '/api/eventos/{id}/foto', summary: 'La foto de la cuenta (JPEG)', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'image/jpeg'), new OA\Response(response: 404, description: 'Sin foto')])]
    public function foto(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        abort_unless($evento->foto && Storage::disk(EventoService::DISCO)->exists($evento->foto), 404);

        return Storage::disk(EventoService::DISCO)->response($evento->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    #[OA\Post(path: '/api/eventos/{id}/foto', summary: 'Adjuntar (o reemplazar) la foto de la cuenta: multipart con "foto" (JPG o PNG, hasta 10 MB); se endereza y se reduce', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Evento actualizado'), new OA\Response(response: 422, description: 'No es una imagen válida')])]
    public function subirFoto(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $request->validate(['foto' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240']], ['foto.max' => 'La foto de la cuenta no puede pesar más de 10 MB.']);
        $this->eventos->guardarFoto($evento, $request->file('foto'));

        return $this->show($request, $evento->refresh());
    }

    #[OA\Delete(path: '/api/eventos/{id}/foto', summary: 'Quitar la foto de la cuenta', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Evento actualizado')])]
    public function quitarFoto(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $this->eventos->quitarFoto($evento);

        return $this->show($request, $evento->refresh());
    }

    #[OA\Post(path: '/api/eventos/{id}/participantes/{participante}/cobro', summary: 'Cobrar a un contacto su parte (pagó el hogar): monto, fecha, destino ("efectivo" | "cuenta:{id}" | "ninguno")', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Evento actualizado'), new OA\Response(response: 422, description: 'Supera lo pendiente')])]
    public function cobrar(EventoCobroRequest $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $this->eventos->cobrar($request->user(), $participante, $request->validated());

        return $this->show($request, $evento->refresh());
    }

    #[OA\Post(path: '/api/eventos/{id}/participantes/{participante}/pagado', summary: 'Pagó otra persona: marcar que un participante ya le pagó (un miembro del hogar puede registrar su egreso con origen y categoria_personal_id)', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Evento actualizado')])]
    public function marcarPagado(EventoPagadoRequest $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $this->eventos->marcarPagado($request->user(), $participante, $request->validated());

        return $this->show($request, $evento->refresh());
    }

    #[OA\Delete(path: '/api/eventos/{id}/participantes/{participante}/pagado', summary: 'Quitar el pago marcado (y su movimiento)', tags: ['Eventos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Evento actualizado')])]
    public function desmarcarPagado(Request $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $this->eventos->desmarcarPagado($participante);

        return $this->show($request, $evento->refresh());
    }

    private function own(Request $request, Evento $evento): void
    {
        abort_unless($evento->esDelHogar($request->user()), 404);
    }
}
