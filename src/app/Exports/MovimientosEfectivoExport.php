<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MovimientosEfectivoExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private $items) {}

    public function collection()
    {
        return collect($this->items);
    }

    public function headings(): array
    {
        return ['ID', 'Descripción', 'Monto', 'Fecha', 'Tipo', 'Categoría'];
    }

    public function map($item): array
    {
        return [$item->id, $item->descripcion, number_format((float) $item->monto, 2, '.', ''), $item->fecha?->format('d/m/Y'), $item->tipo, $item->categoria?->nombre];
    }
}
