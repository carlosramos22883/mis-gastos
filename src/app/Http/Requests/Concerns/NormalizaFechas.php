<?php

namespace App\Http\Requests\Concerns;

use Carbon\Carbon;

/** Los formularios web envían fechas como d/m/Y y la API como Y-m-d: se normalizan a Y-m-d. */
trait NormalizaFechas
{
    protected function normalizarFechas(string ...$campos): void
    {
        foreach ($campos as $campo) {
            $valor = (string) $this->input($campo);
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $valor)) {
                try {
                    $this->merge([$campo => Carbon::createFromFormat('!d/m/Y', $valor)->format('Y-m-d')]);
                } catch (\Throwable) {
                    // se queda como vino y la regla de formato lo rechaza
                }
            }
        }
    }
}
