<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Un reporte en una hoja: encabezado con el período y cada sección (título, columnas, filas y total) una debajo de otra. */
class ReporteExport implements FromArray, ShouldAutoSize, WithStrictNullComparison, WithStyles, WithTitle
{
    private array $filas;

    /** Filas en negrita (títulos, columnas y totales), en numeración de Excel. */
    private array $negritas = [1];

    /** Celdas de dinero ("B12"), con formato de dos decimales. */
    private array $montos = [];

    public function __construct(private readonly string $titulo, string $periodo, array $secciones)
    {
        $filas = [[$titulo], [$periodo], []];
        foreach ($secciones as $s) {
            $filas[] = [$s['titulo']];
            $this->negritas[] = count($filas);
            $filas[] = $s['columnas'];
            $this->negritas[] = count($filas);
            foreach (array_merge($s['filas'], $s['total'] ? [$s['total']] : []) as $f) {
                $filas[] = $f;
                foreach ($s['numericas'] as $col) {
                    if (is_float($f[$col] ?? null)) {
                        $this->montos[] = Coordinate::stringFromColumnIndex($col + 1).count($filas);
                    }
                }
            }
            if ($s['total']) {
                $this->negritas[] = count($filas);
            }
            $filas[] = [];
        }
        $this->filas = $filas;
    }

    public function array(): array
    {
        return $this->filas;
    }

    public function styles(Worksheet $sheet): array
    {
        foreach ($this->montos as $celda) {
            $sheet->getStyle($celda)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        return array_fill_keys($this->negritas, ['font' => ['bold' => true]]);
    }

    public function title(): string
    {
        return mb_substr($this->titulo, 0, 31);
    }
}
