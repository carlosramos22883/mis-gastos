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
        return ['Descripción', 'Categoría', 'Monto', 'Pendiente', 'Fecha de pago', 'Cuota', 'Medio de pago', 'Estado'];
    }

    public function map($item): array
    {
        return [
            $item->descripcion,
            $item->categoria?->nombre,
            number_format((float) $item->monto, 2, '.', ''),
            number_format((float) $item->saldo_pendiente, 2, '.', ''),
            $item->fecha_esperada?->format('d/m/Y'),
            $item->numero_cuota ? $item->numero_cuota.($item->total_cuotas ? '/'.$item->total_cuotas : '') : null,
            $item->medio_pago,
            $item->estado_visual ?? $item->estado,
        ];
    }
}
