<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ArmaReportes;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReporteRequest;
use App\Support\ReporteTabular;
use OpenApi\Attributes as OA;

/**
 * Reportes para las apps. Montos como número con dos decimales (igual que el dashboard) y fechas Y-m-d.
 * Período: ciclo (cualquier fecha dentro; vacío = el actual) o desde/hasta (máximo 24 meses).
 */
#[OA\Tag(name: 'Reportes', description: 'Ejecución financiera, gastos por categoría, préstamos y estado de cuenta; también en PDF, XLSX o CSV')]
class ReporteController extends Controller
{
    use ArmaReportes;

    #[OA\Get(path: '/api/reportes/{reporte}', summary: 'Un reporte con su período', tags: ['Reportes'], security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'reporte', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ReporteRequest::REPORTES)),
            new OA\Parameter(name: 'ciclo', in: 'query', description: 'Fecha Y-m-d dentro del ciclo (vacío = actual)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'desde', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'hasta', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tarjeta', in: 'query', description: 'Estado de cuenta: id de la tarjeta (devuelve sus cortes)', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'estado', in: 'query', description: 'Estado de cuenta: id del corte', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'data (null en estado de cuenta sin corte elegido), periodo y, con tarjeta, cortes'), new OA\Response(response: 422, description: 'Período inválido')])]
    public function show(ReporteRequest $request, string $reporte)
    {
        ['periodo' => $p, 'datos' => $datos] = $this->armar($request, $reporte);
        $respuesta = [
            'titulo' => ReporteTabular::TITULOS[$reporte],
            'periodo' => ['desde' => $p['desde']->toDateString(), 'hasta' => $p['hasta']->toDateString(), 'es_ciclo' => $p['es_ciclo'],
                'anterior' => $p['anterior'], 'siguiente' => $p['siguiente'], 'texto' => $this->textoPeriodo($p, $reporte)],
            'data' => $datos,
        ];
        if ($reporte === 'estado-cuenta' && ($tarjeta = $request->tarjeta())) {
            $respuesta['cortes'] = $tarjeta->estadosCuenta()->orderByDesc('fecha_corte')->get()
                ->map(fn ($e) => ['id' => $e->id, 'fecha_corte' => $e->fecha_corte->toDateString(), 'total' => round((float) $e->total, 2)])->values();
        }

        return response()->json($respuesta);
    }

    #[OA\Get(path: '/api/reportes/{reporte}/exportar', summary: 'Descargar el reporte (formato pdf, xlsx o csv)', tags: ['Reportes'], security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'formato', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf', 'xlsx', 'csv']))],
        responses: [new OA\Response(response: 200, description: 'Archivo'), new OA\Response(response: 403, description: 'Sin permiso de exportar')])]
    public function exportar(ReporteRequest $request, string $reporte)
    {
        return $this->descargar($request, $reporte);
    }
}
