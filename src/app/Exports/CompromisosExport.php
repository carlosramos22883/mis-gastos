<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CompromisosExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $items) {}

    public function collection(): Collection
    {
        return $this->items;
    }

    public function headings(): array
    {
        return ['ID', 'Descripción', 'Monto', 'Fecha esperada', 'Medio de pago', 'Estado'];
    }

    public function map($item): array
    {
        return [$item->id, $item->descripcion, number_format((float) $item->monto, 2, '.', ''), $item->fecha_esperada?->format('d/m/Y'), $item->medio_pago, $item->estado];
    }
}
