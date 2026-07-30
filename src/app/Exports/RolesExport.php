<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RolesExport implements FromCollection, WithHeadings, WithMapping
{
    protected $roles;

    public function __construct($roles)
    {
        $this->roles = $roles;
    }

    public function collection()
    {
        return collect($this->roles);
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre del Rol',
            'Permisos Asignados',
            'Fecha de Creación'
        ];
    }

    public function map($role): array
    {
        return [
            $role->id,
            $role->name,
            $role->permissions->pluck('name')->join(', '), // Une los permisos con coma
            $role->created_at->format('d/m/Y H:i'),
        ];
    }
}