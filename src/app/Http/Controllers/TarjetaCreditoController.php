<?php

namespace App\Http\Controllers;

use App\Exports\TarjetasCreditoExport;
use App\Http\Requests\TarjetaRequest;
use App\Models\Banco;
use App\Models\MarcaRed;
use App\Models\TarjetaCredito;
use App\Services\CompromisoService;
use App\Services\TarjetaService;
use App\Traits\Exportable;
use Illuminate\Http\Request;

class TarjetaCreditoController extends Controller
{
    use Exportable;

    public function __construct(private readonly TarjetaService $tarjetas) {}

    /** Formas de ordenar la lista (la elegida se recuerda en la sesión). */
    public const ORDENES = [
        'disponible' => 'Más disponible',
        'deuda' => 'Más endeudada',
        'uso' => 'Más usada (% del límite)',
        'corte' => 'Corte más próximo',
        'pago' => 'Pago más próximo',
        'limite' => 'Límite más alto',
        'nombre' => 'Nombre',
    ];

    public function index(Request $request)
    {
        $this->tarjetas->generarPendientes($request->user());
        $orden = array_key_exists($request->query('orden'), self::ORDENES) ? $request->query('orden') : session('tarjetas_orden', 'nombre');
        session(['tarjetas_orden' => $orden]);
        $tarjetas = $this->ordenar($this->conResumen($request->user()->tarjetasCredito()->with('banco', 'marcaRed')->get()), $orden);

        return view('tarjetas.index', ['tarjetas' => $tarjetas, 'orden' => $orden, 'ordenes' => self::ORDENES]);
    }

    /** Activas primero (las inactivas siempre al final) y dentro de cada grupo, según el orden elegido; empata por nombre. */
    private function ordenar($tarjetas, string $orden)
    {
        $lejos = '9999-12-31';
        $clave = fn (TarjetaCredito $t) => match ($orden) {
            'disponible' => -$t->disponible,
            'deuda' => -$t->deuda,
            'uso' => (float) $t->limite_credito > 0 ? -($t->deuda / (float) $t->limite_credito) : 0,
            'corte' => $t->proximo_corte->toDateString(),
            'pago' => $t->por_pagar?->fecha_esperada?->toDateString() ?? $lejos,
            'limite' => -(float) $t->limite_credito,
            default => mb_strtolower($t->nombre),
        };

        return $tarjetas->sort(fn ($a, $b) => [! $a->activo, $clave($a), mb_strtolower($a->nombre)] <=> [! $b->activo, $clave($b), mb_strtolower($b->nombre)])->values();
    }

    public function create()
    {
        return view('tarjetas._form', $this->formData());
    }

    public function store(TarjetaRequest $request)
    {
        $tarjeta = $this->tarjetas->crear($request->user(), $request->datos());

        return response()->json(['success' => true, 'message' => 'Tarjeta creada correctamente.', 'redirect' => route('tarjetas.show', $tarjeta)]);
    }

    public function show(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $this->tarjetas->generarEstados($tarjeta);
        [$hoy, $inicioCiclo] = app(CompromisoService::class)->fechas($request->user());
        $tarjeta = $this->conResumen(collect([$tarjeta->load('banco', 'marcaRed')]))->first();

        return view('tarjetas.show', [
            'tarjeta' => $tarjeta,
            'porFacturar' => $this->tarjetas->porFacturar($tarjeta),
            'movimientos' => $tarjeta->movimientos()->with('categoria', 'contacto', 'transferencia.destinoCuenta')->withCount('items')
                ->whereDate('fecha', '>=', $this->tarjetas->inicioPeriodoAbierto($tarjeta)->toDateString())
                ->orderByDesc('fecha')->orderByDesc('id')->get(),
            'enCuotas' => $tarjeta->movimientos()->where('tipo', 'compra')->where('cuotas', '>', 1)->withCount('items')->get()
                ->filter(fn ($m) => $m->items_count < $m->cuotas)->values(),
            'estados' => $tarjeta->estadosCuenta()->with('compromiso')->orderByDesc('fecha_corte')->limit(12)->get(),
            'otrosCompromisos' => $tarjeta->compromisos()->whereNull('estado_cuenta_tarjeta_id')->where('estado', 'pendiente')->get(),
            'porPagar' => $tarjeta->compromisos()->where('estado', 'pendiente')->orderBy('fecha_esperada')->get()
                ->map(fn ($c) => ['compromiso' => $c, 'costo' => $this->tarjetas->costoDeNoPagar($tarjeta, $c)]),
            'pagos' => $this->tarjetas->pagos($tarjeta),
            'cargosProximos' => $this->tarjetas->cargosFijosDelProximoCorte($tarjeta),
            'hoy' => $hoy,
            'inicioCiclo' => $inicioCiclo,
        ]);
    }

    public function edit(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);

        return view('tarjetas._form', $this->formData() + compact('tarjeta'));
    }

    public function update(TarjetaRequest $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $tarjeta->update($request->datos());

        return response()->json(['success' => true, 'message' => 'Tarjeta actualizada correctamente.']);
    }

    public function destroy(Request $request, TarjetaCredito $tarjeta)
    {
        $this->own($request, $tarjeta);
        $this->tarjetas->eliminar($tarjeta);

        return response()->json(['success' => true, 'message' => 'Tarjeta eliminada correctamente.', 'redirect' => route('tarjetas.index')]);
    }

    public function export(Request $request)
    {
        $tarjetas = $this->conResumen($request->user()->tarjetasCredito()->with(['banco', 'marcaRed'])->orderBy('nombre')->get());

        return $this->handleExport($request, $tarjetas, TarjetasCreditoExport::class, 'Tarjetas de crédito', [
            'nombre' => 'Nombre', 'banco' => 'Banco', 'marcaRed' => 'Marca', 'ultimos_digitos' => 'Últimos dígitos',
            'limite_credito' => 'Límite', 'deuda' => 'Deuda', 'disponible' => 'Disponible', 'activo' => 'Estado',
        ], 'tarjetas_credito');
    }

    /** Agrega deuda, disponible y el próximo corte a cada tarjeta. */
    private function conResumen($tarjetas)
    {
        return $tarjetas->each(function (TarjetaCredito $t) {
            $t->setAttribute('deuda', $this->tarjetas->deuda($t));
            $t->setAttribute('disponible', $this->tarjetas->disponible($t));
            $t->setAttribute('proximo_corte', $this->tarjetas->proximoCorte($t, $this->tarjetas->inicioPeriodoAbierto($t)));
            $t->setAttribute('por_pagar', $t->compromisos()->where('estado', 'pendiente')->orderBy('fecha_esperada')->first());
        });
    }

    private function formData(): array
    {
        $opciones = fn ($modelo) => $modelo::where('activo', true)->orderBy('nombre')->get()->mapWithKeys(fn ($item) => [
            $item->id => ['label' => $item->nombre, 'logo' => $item->logo ? asset('storage/'.$item->logo) : null],
        ])->all();

        return ['bancos' => $opciones(Banco::class), 'marcas' => $opciones(MarcaRed::class)];
    }

    private function own(Request $request, TarjetaCredito $tarjeta): void
    {
        abort_unless($tarjeta->esDelHogar($request->user()), 404);
    }
}
