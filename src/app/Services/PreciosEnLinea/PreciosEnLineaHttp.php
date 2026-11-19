<?php

namespace App\Services\PreciosEnLinea;

/** Cabeceras de las consultas: un navegador genérico, sin datos del usuario ni de la app. */
final class PreciosEnLineaHttp
{
    public const CABECERAS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        'Accept-Language' => 'es-SV,es;q=0.9',
    ];
}
