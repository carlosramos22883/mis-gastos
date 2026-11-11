<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EscenarioProyeccionRequest;
use App\Models\EscenarioProyeccion;
use App\Services\ProyeccionService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Proyecciones', description: '¿Cuándo salgo de mis deudas? Escenarios simulados sobre los datos reales del hogar')]
class ProyeccionController extends Controller
{
    public function __construct(private readonly ProyeccionService $proyecciones) {}

    #[OA\Get(path: '/api/proyecciones', summary: 'Foto de hoy, escenarios guardados y comparación', description: 'Compara «Si sigo igual» con hasta 3 escenarios (?ver[]=id o ?ver=1,2; sin ver, los más recientes; ver vacío, solo «Si sigo igual»). Cada uno trae sus hitos (mes "Y-m" o null = más de 15 años), totales, meta (extra al mes para llegar a tiempo; null = imposible), cuándo queda en cero cada deuda (sin la hipoteca) y la serie de deuda total por mes.', tags: ['Proyecciones'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'ver[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer')))],
        responses: [new OA\Response(response: 200, description: 'Proyección')])]
    public function index(Request $request)
    {
        ['base' => $base, 'escenarios' => $escenarios, 'ver' => $ver, 'comparar' => $comparar] = $this->proyecciones->comparacion($request->user(), $this->ver($request));
        $dinero = fn ($v) => number_format((float) $v, 2, '.', '');
        $deudas = collect($base['deudas']);
        $cuotas = (float) $deudas->where('tipo', 'banco')->sum('cuota_efectivo');

        return response()->json(['data' => [
            'base' => [
                'mes_inicio' => $base['mes_inicio'], 'ingreso' => $dinero($base['ingreso']), 'gasto' => $dinero($base['gasto']), 'gasto_origen' => $base['gasto_origen'],
                'cuotas_efectivo' => $dinero($cuotas), 'sobra' => $dinero($base['ingreso'] - $base['gasto'] - $cuotas),
                'grupos' => [
                    'tarjetas' => $dinero($deudas->where('tipo', 'tarjeta')->sum('saldo')), 'bancos' => $dinero($deudas->where('tipo', 'banco')->where('hipoteca', false)->sum('saldo')),
                    'personas' => $dinero($deudas->where('tipo', 'personal')->sum('saldo')), 'hipoteca' => $dinero($deudas->where('tipo', 'banco')->where('hipoteca', true)->sum('saldo')),
                ],
                'deudas' => $deudas->map(fn ($d) => ['clave' => $d['clave'], 'tipo' => $d['tipo'], 'nombre' => $d['nombre'], 'saldo' => $dinero($d['saldo']),
                    'tasa' => $dinero($d['tasa']), 'hipoteca' => (bool) ($d['hipoteca'] ?? false)])->values(),
            ],
            'escenarios' => $escenarios->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'datos' => $e->datos])->values(),
            'ver' => $ver,
            'comparacion' => $comparar->map(fn ($e) => [
                'id' => $e['id'], 'nombre' => $e['nombre'], 'orden' => $e['datos']['orden'] ?? 'mas_cara', 'modo_tarjetas' => $e['datos']['modo_tarjetas'] ?? 'intereses',
                'hitos' => $e['resultado']['hitos'],
                'totales' => [
                    'intereses_tarjetas' => $dinero($e['resultado']['totales']['intereses_tarjetas']), 'perdida_rotar' => $dinero($e['resultado']['totales']['perdida_rotar']),
                    'prestamos_nuevos' => $dinero($e['resultado']['totales']['intereses_prestamos_nuevos'] + $e['resultado']['totales']['comisiones']),
                    'faltante' => $dinero($e['resultado']['totales']['faltante']), 'meses_con_faltante' => $e['resultado']['totales']['meses_con_faltante'],
                ],
                'meta' => $e['meta'] ? ['que' => $e['meta']['que'], 'mes' => $e['meta']['mes'], 'extra' => $e['meta']['extra'] === null ? null : $dinero($e['meta']['extra']),
                    'logrado_en' => $e['meta']['logrado_en']] : null,
                'deudas' => collect($e['resultado']['deudas'])->reject(fn ($d) => $d['hipoteca'])->map(fn ($d) => ['clave' => $d['clave'], 'nombre' => $d['nombre'], 'cero' => $d['cero']])->values(),
                'serie' => collect($e['resultado']['meses'])->map(fn ($m) => ['mes' => $m['mes'], 'total' => $dinero($m['total'])])->values(),
            ])->values(),
        ]]);
    }

    /** ?ver[]=1&ver[]=2 o ?ver=1,2 (sin ver: los más recientes; ver vacío: solo "Si sigo igual"). */
    private function ver(Request $request): ?array
    {
        if (! $request->query->has('ver')) {
            return null;
        }
        $ver = $request->query('ver');

        return is_array($ver) ? $ver : array_filter(explode(',', (string) $ver), 'is_numeric');
    }

    #[OA\Post(path: '/api/proyecciones', summary: 'Guardar un escenario', description: 'nombre, modo_tarjetas (intereses|rotar), perdida_rotar, sobrante (abonar|ahorrar), orden (mas_cara|mas_pequena|personalizado), prioridad[] (claves "tarjeta:ID" o "prestamo:ID"), meta_que (tarjetas|personas|sin_deudas|clave) + meta_mes (Y-m) y palancas[] (ver EscenarioProyeccionRequest).', tags: ['Proyecciones'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Guardado'), new OA\Response(response: 422, description: 'Validación')])]
    public function store(EscenarioProyeccionRequest $request)
    {
        $escenario = $request->user()->hogar->escenariosProyeccion()->create(['user_id' => $request->user()->id, 'nombre' => $request->validated('nombre'), 'datos' => $request->datos()]);

        return response()->json(['data' => ['id' => $escenario->id, 'nombre' => $escenario->nombre, 'datos' => $escenario->datos]], 201);
    }

    #[OA\Put(path: '/api/proyecciones/{id}', summary: 'Editar un escenario', tags: ['Proyecciones'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 404, description: 'No encontrado'), new OA\Response(response: 422, description: 'Validación')])]
    public function update(EscenarioProyeccionRequest $request, EscenarioProyeccion $proyeccion)
    {
        abort_unless($proyeccion->esDelHogar($request->user()), 404);
        $proyeccion->update(['nombre' => $request->validated('nombre'), 'datos' => $request->datos()]);

        return response()->json(['data' => ['id' => $proyeccion->id, 'nombre' => $proyeccion->nombre, 'datos' => $proyeccion->datos]]);
    }

    #[OA\Delete(path: '/api/proyecciones/{id}', summary: 'Eliminar un escenario', tags: ['Proyecciones'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Eliminado'), new OA\Response(response: 404, description: 'No encontrado')])]
    public function destroy(Request $request, EscenarioProyeccion $proyeccion)
    {
        abort_unless($proyeccion->esDelHogar($request->user()), 404);
        $proyeccion->delete();

        return response()->noContent();
    }
}
