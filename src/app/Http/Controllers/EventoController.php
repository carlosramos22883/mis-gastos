<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventoCobroRequest;
use App\Http\Requests\EventoPagadoRequest;
use App\Http\Requests\EventoRequest;
use App\Models\CategoriaPersonal;
use App\Models\Evento;
use App\Models\EventoParticipante;
use App\Services\CicloEfectivoService;
use App\Services\EventoService;
use App\Support\EventoImagen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Eventos: dividir la cuenta de una comida o celebración (la lógica está en EventoService). */
class EventoController extends Controller
{
    public function __construct(private readonly EventoService $eventos) {}

    public function index(Request $request)
    {
        return view('eventos.index', ['eventos' => $this->eventos->listar($request->user())]);
    }

    public function create(Request $request)
    {
        return view('eventos.form', $this->formData($request));
    }

    public function store(EventoRequest $request)
    {
        $evento = $this->eventos->guardar($request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => 'Evento registrado.', 'redirect' => route('eventos.show', $evento)]);
    }

    public function show(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $evento->load('participantes.user', 'participantes.contacto', 'participantes.deuda.pagos', 'consumos.participantes', 'categoria');

        return view('eventos.show', ['evento' => $evento, 'resumen' => $this->eventos->resumen($evento)]);
    }

    public function edit(Request $request, Evento $evento)
    {
        $this->own($request, $evento);

        return view('eventos.form', $this->formData($request, $evento->load('participantes.user', 'participantes.contacto', 'consumos.participantes')));
    }

    public function update(EventoRequest $request, Evento $evento)
    {
        $this->own($request, $evento);
        $this->eventos->guardar($request->user(), $request->validated(), $evento);

        return response()->json(['success' => true, 'message' => 'Evento actualizado.', 'redirect' => route('eventos.show', $evento)]);
    }

    public function destroy(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $this->eventos->eliminar($evento);

        return response()->json(['success' => true, 'message' => 'Evento eliminado.', 'redirect' => route('eventos.index')]);
    }

    /** El cuadro de cada uno como imagen PNG, para compartir. */
    public function imagen(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        $png = app(EventoImagen::class)->png($evento, $this->eventos->resumen($evento), $request->user()->monedaPreferida?->simbolo ?? '$');

        return response($png, 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="evento-'.$evento->id.'.png"']);
    }

    /** La foto de la cuenta (disco privado). */
    public function foto(Request $request, Evento $evento)
    {
        $this->own($request, $evento);
        abort_unless($evento->foto && Storage::disk(EventoService::DISCO)->exists($evento->foto), 404);

        return Storage::disk(EventoService::DISCO)->response($evento->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function cobroForm(Request $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $pendiente = $this->eventos->resumen($evento)['participantes'][$participante->id]['pendiente'];

        return view('eventos._cobro_form', ['evento' => $evento, 'participante' => $participante, 'pendiente' => $pendiente, 'destinos' => $this->origenes($request, false)]);
    }

    public function cobrar(EventoCobroRequest $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $this->eventos->cobrar($request->user(), $participante, $request->validated());

        return response()->json(['success' => true, 'message' => 'Pago de '.$participante->nombre().' registrado.']);
    }

    public function pagadoForm(Request $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);

        return view('eventos._pagado_form', [
            'evento' => $evento, 'participante' => $participante,
            'monto' => $this->eventos->resumen($evento)['participantes'][$participante->id]['total'],
            'origenes' => $this->origenes($request, true),
            'categorias' => $this->categorias($request),
        ]);
    }

    public function marcarPagado(EventoPagadoRequest $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $this->eventos->marcarPagado($request->user(), $participante, $request->validated());

        return response()->json(['success' => true, 'message' => $participante->nombre().' ya pagó su parte.']);
    }

    public function desmarcarPagado(Request $request, Evento $evento, EventoParticipante $participante)
    {
        $this->own($request, $evento);
        $this->eventos->desmarcarPagado($participante);

        return response()->json(['success' => true, 'message' => 'Se quitó el pago de '.$participante->nombre().'.']);
    }

    private function formData(Request $request, ?Evento $evento = null): array
    {
        $user = $request->user();
        [$inicio] = app(CicloEfectivoService::class)->cicloConsultado($user);

        return [
            'evento' => $evento,
            'opciones' => $this->eventos->opcionesParticipantes($user),
            'origenes' => $this->origenes($request, true),
            'categorias' => $this->categorias($request),
            'cycleStart' => $inicio,
        ];
    }

    private function categorias(Request $request): array
    {
        return CategoriaPersonal::where('hogar_id', $request->user()->hogar_id)->where('activo', true)->where('tipo', 'egreso')->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /** De dónde sale (pago) o a dónde entra (cobro) el dinero. */
    private function origenes(Request $request, bool $conTarjetas): array
    {
        $user = $request->user();
        $opciones = ['efectivo' => 'Efectivo'];
        foreach ($user->cuentas()->where('activo', true)->orderBy('nombre')->get() as $cuenta) {
            $opciones['cuenta:'.$cuenta->id] = $cuenta->nombre;
        }
        if ($conTarjetas) {
            foreach ($user->tarjetasCredito()->where('activo', true)->orderBy('nombre')->get() as $tarjeta) {
                $opciones['tarjeta:'.$tarjeta->id] = 'Tarjeta '.$tarjeta->nombre;
            }
        }

        return $opciones + ['ninguno' => 'Solo anotar (el dinero ya está registrado)'];
    }

    private function own(Request $request, Evento $evento): void
    {
        abort_unless($evento->esDelHogar($request->user()), 404);
    }
}
