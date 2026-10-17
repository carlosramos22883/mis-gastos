<?php

namespace App\Services;

use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\CompromisoRecurrente;
use Illuminate\Validation\ValidationException;

class CategoriaPersonalService
{
    /** "Saldo inicial" la administra el sistema (wizard y cortes): no se edita ni se elimina. */
    public function esEditable(CategoriaPersonal $categoria): bool
    {
        return $categoria->nombre !== CicloEfectivoService::CATEGORIA_SALDO_INICIAL;
    }

    /** Una categoría con registros no se elimina (se perdería el historial): se desactiva. */
    public function eliminar(CategoriaPersonal $categoria): void
    {
        $enUso = $categoria->movimientos()->exists()
            || Compromiso::where('categoria_personal_id', $categoria->id)->exists()
            || CompromisoRecurrente::where('categoria_personal_id', $categoria->id)->exists();

        if ($enUso) {
            throw ValidationException::withMessages([
                'categoria' => 'Esta categoría ya tiene movimientos o compromisos. Desactívala en lugar de eliminarla.',
            ]);
        }

        $categoria->delete();
    }
}
