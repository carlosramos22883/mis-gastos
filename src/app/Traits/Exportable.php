<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

trait Exportable
{
    protected function handleExport(
        Request $request, 
        $data, 
        string $exportClass, 
        string $title, 
        array $pdfHeaders, 
        string $filenamePrefix,
        ?string $customPdfView = null // Nuevo parámetro opcional
    ) {
        $format = $request->get('format', 'xlsx');
        $filename = "{$filenamePrefix}_" . date('Y-m-d');
        $pdfView = $customPdfView ?? 'exports.generic-pdf'; // Usa la genérica por defecto

        switch ($format) {
            case 'xlsx':
                return Excel::download(new $exportClass($data), "{$filename}.xlsx");
            
            case 'csv':
                return Excel::download(new $exportClass($data), "{$filename}.csv", \Maatwebsite\Excel\Excel::CSV);
            
            case 'pdf':
                // Pasamos las variables que la vista genérica necesita
                $pdf = Pdf::loadView($pdfView, [
                    'title' => $title,
                    'headers' => $pdfHeaders,
                    'data' => $data
                ]);
                return $pdf->download("{$filename}.pdf");
            
            default:
                abort(400, 'Formato de exportación no soportado.');
        }
    }
}