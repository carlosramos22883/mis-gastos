<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ArmaReportes;
use App\Http\Requests\ReporteRequest;
use App\Support\ReporteTabular;

/** Reportes: ejecución financiera, gastos por categoría, préstamos y estado de cuenta; en pantalla o en PDF, XLSX y CSV. */
class ReporteController extends Controller
{
    use ArmaReportes;

    public function show(ReporteRequest $request, string $reporte = 'ejecucion')
    {
        ['periodo' => $periodo, 'datos' => $datos] = $this->armar($request, $reporte);
        $tarjetas = $reporte === 'estado-cuenta'
            ? $request->user()->tarjetasCredito()->with(['estadosCuenta' => fn ($q) => $q->orderByDesc('fecha_corte')])->orderBy('nombre')->get()
            : collect();

        return view('reportes.index', [
            'reporte' => $reporte,
            'titulo' => ReporteTabular::TITULOS[$reporte],
            'periodo' => $periodo,
            'textoPeriodo' => $this->textoPeriodo($periodo, $reporte),
            'd' => $datos,
            'tarjetas' => $tarjetas,
        ]);
    }

    public function exportar(ReporteRequest $request, string $reporte)
    {
        return $this->descargar($request, $reporte);
    }
}
