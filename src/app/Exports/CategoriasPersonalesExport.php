<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CategoriasPersonalesExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private $items) {}

    public function collection()
    {
        return collect($this->items);
    }

    public function headings(): array
    {
        return ['ID', 'Nombre', 'Tipo', 'Color', 'Activa', 'Fecha de creación'];
    }

    public function map($item): array
    {
        return [$item->id, $item->nombre, $item->tipo, $item->color, $item->activo ? 'Sí' : 'No', $item->created_at?->format('d/m/Y H:i')];
    }
}
