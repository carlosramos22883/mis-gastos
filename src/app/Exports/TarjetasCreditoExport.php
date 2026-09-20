<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TarjetasCreditoExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly Collection $tarjetas) {}

    public function collection(): Collection
    {
        return $this->tarjetas->map(fn ($tarjeta) => [
            $tarjeta->nombre,
            $tarjeta->banco?->nombre ?? 'Sin banco',
            $tarjeta->marcaRed?->nombre ?? 'Sin marca',
            $tarjeta->ultimos_digitos ? '**** '.$tarjeta->ultimos_digitos : '—',
            $tarjeta->limite_credito,
            $tarjeta->saldo_actual,
            $tarjeta->activo ? 'Activa' : 'Inactiva',
        ]);
    }

    public function headings(): array
    {
        return ['Nombre', 'Banco', 'Marca', 'Últimos dígitos', 'Límite de crédito', 'Saldo actual', 'Estado'];
    }
}
