<?php

namespace App\Http\Controllers\Concerns;

use App\Exports\ReporteExport;
use App\Http\Requests\ReporteRequest;
use App\Services\ReporteService;
use App\Support\ReporteTabular;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Excel as Formato;
use Maatwebsite\Excel\Facades\Excel;

/** Arma un reporte a partir de la petición (web y API comparten período, validación y datos). */
trait ArmaReportes
{
    /** @return array{periodo: array, datos: ?array} */
    protected function armar(ReporteRequest $request, string $reporte): array
    {
        abort_unless(in_array($reporte, ReporteRequest::REPORTES, true), 404);
        $user = $request->user();
        $servicio = app(ReporteService::class);
        $periodo = $servicio->periodo($user, $request->input('ciclo'), $request->input('desde'), $request->input('hasta')) ?? abort(404);

        $datos = match ($reporte) {
            'ejecucion' => $servicio->ejecucion($user, $periodo['desde'], $periodo['hasta']),
            'gastos' => $servicio->gastos($user, $periodo['desde'], $periodo['hasta']),
            'prestamos' => $servicio->prestamos($user),
            'estado-cuenta' => ($e = $request->estadoCuenta()) ? $servicio->estadoCuenta($e) : null,
        };

        return compact('periodo', 'datos');
    }

    /** "Ciclo del 23/09/2026 al 22/10/2026" o "Del 01/01/2026 al 22/10/2026". */
    protected function textoPeriodo(array $periodo, string $reporte): string
    {
        if ($reporte === 'prestamos') {
            return 'Al '.now()->format('d/m/Y');
        }
        $rango = 'del '.$periodo['desde']->format('d/m/Y').' al '.$periodo['hasta']->format('d/m/Y');

        return $periodo['es_ciclo'] ? 'Ciclo '.$rango : ucfirst($rango);
    }

    /** Nombre corto del miembro que hizo un registro (solo si el hogar tiene más de uno). */
    protected function quien(ReporteRequest $request): callable
    {
        $miembros = $request->user()->miembrosHogar;

        return fn (?int $id) => $miembros->count() > 1 ? strtok((string) $miembros->firstWhere('id', $id)?->name, ' ') ?: null : null;
    }

    /** El reporte como archivo: PDF (por defecto), XLSX o CSV. */
    protected function descargar(ReporteRequest $request, string $reporte)
    {
        ['periodo' => $periodo, 'datos' => $datos] = $this->armar($request, $reporte);
        abort_if($datos === null, 404);
        $titulo = ReporteTabular::TITULOS[$reporte];
        $texto = $this->textoPeriodo($periodo, $reporte);
        $secciones = ReporteTabular::secciones($reporte, $datos, $this->quien($request));
        $archivo = 'reporte_'.str_replace('-', '_', $reporte).'_'.($reporte === 'prestamos' ? now() : $periodo['hasta'])->format('Y-m-d');

        return match ($request->input('formato', 'pdf')) {
            'xlsx' => Excel::download(new ReporteExport($titulo, $texto, $secciones), "{$archivo}.xlsx"),
            'csv' => Excel::download(new ReporteExport($titulo, $texto, $secciones), "{$archivo}.csv", Formato::CSV),
            default => Pdf::loadView('reportes.pdf', ['titulo' => $titulo, 'periodo' => $texto, 'hogar' => $request->user()->hogar?->nombre, 'secciones' => $secciones,
                'generado' => now($request->user()->zona_horaria ?: config('app.timezone'))->format('d/m/Y H:i')])
                ->setPaper('letter', $reporte === 'estado-cuenta' ? 'portrait' : 'landscape')
                ->download("{$archivo}.pdf"),
        };
    }
}
