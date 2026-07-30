<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UsersExport implements FromCollection, WithHeadings, WithMapping
{
    protected $users;

    // Recibimos la colección de usuarios ya filtrada desde el controlador
    public function __construct($users)
    {
        $this->users = $users;
    }

    public function collection()
    {
        return collect($this->users);
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            'Correo Electrónico',
            'Rol',
            'Verificado',
            'Fecha de Creación'
        ];
    }

    public function map($user): array
    {
        return [
            $user->id,
            $user->name,
            $user->email,
            $user->roles->pluck('name')->join(', '), // Une los roles con coma si tiene varios
            $user->email_verified_at ? 'Sí' : 'No',
            $user->created_at->format('d/m/Y H:i'),
        ];
    }
}