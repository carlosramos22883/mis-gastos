<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactoRequest;
use App\Http\Requests\DeudaPagoRequest;
use App\Http\Requests\DeudaRequest;
use App\Models\Contacto;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Services\ContactoService;
use App\Services\DeudorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Contactos del hogar y, en su ficha, lo que nos deben (deudas y pagos). */
class ContactoController extends Controller
{
    public function __construct(private readonly ContactoService $contactos, private readonly DeudorService $deudas) {}

    public function index(Request $request)
    {
        $filtro = $request->get('filtro') === 'deben' ? 'deben' : 'todos';
        $contactos = $request->user()->contactos()->with(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')])
            ->when($filtro === 'deben', fn ($q) => $q->whereHas('deudas'))
            ->orderByDesc('activo')->orderBy('nombre')->orderBy('apellido')->get()
            ->each(fn (Contacto $c) => $this->deudas->resumir($c));
        // Lo que el hogar le debe (préstamos activos de ese contacto)
        $prestamos = app(\App\Services\PrestamoService::class);
        $contactos->each(fn (Contacto $c) => $c->setAttribute('le_debes', round((float) $c->prestamos()->where('activo', true)->get()->sum(fn ($p) => $prestamos->saldo($p)), 2)));
        if ($filtro === 'deben') {
            $contactos = $contactos->filter(fn ($c) => $c->pendiente > 0 || $c->activo)->values();
        }

        return view('contactos.index', compact('contactos', 'filtro'));
    }

    /** "Nos deben" del menú: la lista de contactos filtrada. */
    public function nosDeben()
    {
        return redirect()->route('contactos.index', ['filtro' => 'deben']);
    }

    public function create()
    {
        return view('contactos._form');
    }

    public function store(ContactoRequest $request)
    {
        $contacto = $this->contactos->crear($request->user(), $request->datos(), $request->file('foto'));

        return response()->json(['success' => true, 'message' => 'Contacto creado.', 'redirect' => route('contactos.show', $contacto)]);
    }

    public function show(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $hoy = Carbon::parse(Carbon::now($request->user()->zona_horaria ?: config('app.timezone'))->toDateString());
        $ficha = $this->contactos->ficha($contacto, $hoy);
        $pagos = DeudaPago::with('deuda', 'movimientoCuenta.cuenta')->whereIn('deuda_id', $contacto->deudas->pluck('id'))
            ->orderByDesc('fecha')->orderByDesc('id')->get();

        return view('contactos.show', compact('contacto', 'ficha', 'pagos', 'hoy'));
    }

    public function edit(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);

        return view('contactos._form', compact('contacto'));
    }

    public function update(ContactoRequest $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->contactos->actualizar($contacto, $request->datos(), $request->file('foto'), $request->boolean('quitar_foto'));

        return response()->json(['success' => true, 'message' => 'Contacto actualizado.']);
    }

    public function destroy(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->contactos->eliminar($contacto);

        return response()->json(['success' => true, 'message' => 'Contacto eliminado.', 'redirect' => route('contactos.index')]);
    }

    /** La foto se sirve desde el disco privado, solo a miembros del hogar. */
    public function foto(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        abort_unless($contacto->foto && Storage::disk(ContactoService::DISCO)->exists($contacto->foto), 404);

        return Storage::disk(ContactoService::DISCO)->response($contacto->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function crearDeuda(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);

        return view('contactos._deuda_form', ['contacto' => $contacto, 'origenes' => $this->origenes($request, true)]);
    }

    public function guardarDeuda(DeudaRequest $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $this->deudas->registrarDeuda($request->user(), $contacto, $request->validated());

        return response()->json(['success' => true, 'message' => 'Deuda registrada.']);
    }

    public function eliminarDeuda(Request $request, Deuda $deuda)
    {
        abort_unless($deuda->esDelHogar($request->user()), 404);
        $this->deudas->eliminarDeuda($deuda);

        return response()->json(['success' => true, 'message' => 'Deuda eliminada.']);
    }

    public function crearPago(Request $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $contacto->load(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')->orderBy('fecha')]);
        $this->deudas->resumir($contacto);

        return view('contactos._pago_form', ['contacto' => $contacto, 'destinos' => $this->origenes($request, false)]);
    }

    public function guardarPago(DeudaPagoRequest $request, Contacto $contacto)
    {
        $this->own($request, $contacto);
        $pagos = $this->deudas->registrarPago($request->user(), $contacto, $request->validated());

        return response()->json(['success' => true, 'message' => $pagos->count() > 1
            ? "Pago aplicado a {$pagos->count()} deudas, de la más antigua a la más reciente."
            : 'Pago registrado.']);
    }

    public function eliminarPago(Request $request, DeudaPago $pago)
    {
        abort_unless($pago->esDelHogar($request->user()), 404);
        $this->deudas->eliminarPago($pago);

        return response()->json(['success' => true, 'message' => 'Pago eliminado.']);
    }

    /** De dónde sale (deuda) o a dónde entra (pago) el dinero. */
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

    private function own(Request $request, Contacto $contacto): void
    {
        abort_unless($contacto->esDelHogar($request->user()), 404);
    }
}
