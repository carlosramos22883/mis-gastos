<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * El gasto pasa el presupuesto de su categoría y quien guarda pidió revisarlo (presupuesto = "revisar"). Responde 422
 * con errors.presupuesto (el aviso) y "presupuesto": cuánto se pasa y de qué otras categorías se puede cubrir. El
 * cliente reenvía con presupuesto = "cubrir" + traspasos, o "pasarme" para guardarlo así.
 */
class ExcedePresupuesto extends Exception
{
    /** @param  array<string, mixed>  $detalle  ver PresupuestoTraspasoService::detalle() */
    public function __construct(public readonly array $detalle)
    {
        parent::__construct($detalle['mensaje']);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => ['presupuesto' => [$this->getMessage()]],
            'presupuesto' => $this->detalle], 422);
    }
}
