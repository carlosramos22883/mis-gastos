<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que hay en casa de cada producto.
 *
 *  - Falta (pasa a la lista de compras): «se está acabando», o se lleva la cuenta y quedan 0 o lo del aviso o menos.
 *  - Al subir la cantidad (se compró) se quita «se está acabando». Al llegar a 0 se borra el vencimiento.
 *  - El vencimiento es solo de los perecederos y se compara por día en la zona horaria del usuario.
 */
class DespensaService
{
    public const ACCIONES = ['sumar', 'restar', 'cantidad', 'se_acabo', 'por_acabarse', 'no_llevar_cuenta', 'vence'];

    /** Días antes del vencimiento en que se avisa. */
    public const DIAS_AVISO_VENCE = 3;

    public function ajustar(Producto $producto, string $accion, mixed $valor = null): Producto
    {
        return DB::transaction(function () use ($producto, $accion, $valor) {
            $p = Producto::whereKey($producto->id)->lockForUpdate()->firstOrFail();
            $antes = $p->en_casa;

            match ($accion) {
                'sumar' => $p->en_casa = min(999, (int) $p->en_casa + 1),
                'restar' => $p->en_casa = max(0, (int) $p->en_casa - 1),
                'cantidad' => $p->en_casa = (int) $valor,
                'se_acabo' => [$p->en_casa, $p->por_acabarse] = [0, false],
                'por_acabarse' => $p->por_acabarse = (bool) $valor,
                'no_llevar_cuenta' => [$p->en_casa, $p->vence_el] = [null, null],
                'vence' => $p->vence_el = $this->fechaVence($p, $valor),
            };
            if ($p->en_casa !== null && $p->en_casa > (int) $antes) {
                $p->por_acabarse = false; // se compró
            }
            if ($p->en_casa === 0) {
                $p->vence_el = null;
            }
            $p->despensa_actualizada_at = now();
            $p->save();

            return $p;
        });
    }

    public function falta(Producto $producto): bool
    {
        if (! $producto->activo) {
            return false;
        }
        if ($producto->por_acabarse || $producto->en_casa === 0) {
            return true;
        }

        return $producto->en_casa !== null && $producto->avisar_cuando !== null && $producto->en_casa <= $producto->avisar_cuando;
    }

    /** Cuántos comprar para llegar a la cantidad ideal (al menos 1 si falta). */
    public function porComprar(Producto $producto): int
    {
        if (! $this->falta($producto)) {
            return 0;
        }

        return max(1, (int) $producto->cantidad_ideal - (int) $producto->en_casa);
    }

    /**
     * Estado del vencimiento: null si no aplica; si no, ['dias' => n, 'texto' => …, 'aviso' => bool].
     *
     * @return array{dias: int, texto: string, aviso: bool}|null
     */
    public function vencimiento(Producto $producto, Carbon $hoy): ?array
    {
        if ($producto->duracion !== 'perecedero' || ! $producto->vence_el || $producto->en_casa === 0) {
            return null;
        }
        $dias = (int) $hoy->copy()->startOfDay()->diffInDays(Carbon::parse($producto->vence_el->toDateString())->startOfDay(), false);
        $texto = match (true) {
            $dias < 0 => 'Venció el '.$producto->vence_el->format('d/m'),
            $dias === 0 => 'Vence hoy',
            $dias === 1 => 'Vence mañana',
            default => "Vence en {$dias} días",
        };

        return ['dias' => $dias, 'texto' => $texto, 'aviso' => $dias <= self::DIAS_AVISO_VENCE];
    }

    /** Estado para la pantalla y la API después de un cambio. */
    public function estado(Producto $producto, Carbon $hoy): array
    {
        return [
            'en_casa' => $producto->en_casa,
            'por_acabarse' => $producto->por_acabarse,
            'vence_el' => $producto->vence_el?->toDateString(),
            'vencimiento' => $this->vencimiento($producto, $hoy),
            'falta' => $this->falta($producto),
            'por_comprar' => $this->porComprar($producto),
        ];
    }

    public function hoy(User $user): Carbon
    {
        return Carbon::parse(Carbon::now($user->zona_horaria ?: config('app.timezone'))->toDateString());
    }

    private function fechaVence(Producto $producto, mixed $valor): ?Carbon
    {
        if ($producto->duracion !== 'perecedero') {
            throw ValidationException::withMessages(['valor' => 'Solo los productos perecederos llevan fecha de vencimiento.']);
        }

        return $valor ? Carbon::parse($valor) : null;
    }
}
