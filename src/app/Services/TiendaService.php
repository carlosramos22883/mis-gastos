<?php

namespace App\Services;

use App\Models\ListaCompraItem;
use App\Models\Tienda;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Tiendas del hogar. Una tienda con precios o compras no se elimina: se desactiva (su historial se conserva). */
class TiendaService
{
    public function crear(User $user, array $data): Tienda
    {
        return $user->tiendas()->create($data + ['user_id' => $user->id, 'activo' => true]);
    }

    public function actualizar(Tienda $tienda, array $data): Tienda
    {
        $tienda->update($data);

        return $tienda;
    }

    public function eliminar(Tienda $tienda): void
    {
        if ($this->tieneHistorial($tienda)) {
            throw ValidationException::withMessages(['tienda' => 'Esta tienda tiene precios o compras. Desactívala en lugar de eliminarla.']);
        }
        $tienda->delete();
    }

    /** Agrega las tiendas comunes que el hogar aún no tiene. Devuelve cuántas agregó. */
    public function agregarComunes(User $user): int
    {
        $tiene = $user->tiendas()->pluck('nombre')->map(fn ($n) => mb_strtolower($n))->all();
        $nuevas = 0;
        foreach (Tienda::COMUNES as $nombre => $fuente) {
            if (! in_array(mb_strtolower($nombre), $tiene, true)) {
                $this->crear($user, ['nombre' => $nombre, 'fuente' => $fuente]);
                $nuevas++;
            }
        }

        return $nuevas;
    }

    public function tieneHistorial(Tienda $tienda): bool
    {
        return $tienda->precios()->exists()
            || ListaCompraItem::where('tienda_id', $tienda->id)->orWhere('no_habia_tienda_id', $tienda->id)->exists();
    }
}
