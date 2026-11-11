<?php

namespace App\Support;

use App\Models\Compromiso;
use App\Models\Contacto;
use App\Models\Prestamo;
use App\Models\TarjetaCredito;
use Illuminate\Http\Request;

/**
 * Navegación de regreso entre pantallas: un enlace lleva "?desde=contacto:12" y la pantalla de destino
 * muestra "← Volver a Karen". Solo se aceptan pantallas conocidas y registros del propio hogar
 * (nunca una URL libre: así no se puede redirigir fuera del sistema).
 */
class Volver
{
    /** "desde" listo para agregar a una ruta, o vacío si no viene o no es válido. */
    public static function parametro(Request $request): array
    {
        return self::destino($request) ? ['desde' => $request->query('desde')] : [];
    }

    /** @return array{texto: string, url: string}|null */
    public static function destino(Request $request): ?array
    {
        if (! preg_match('/^(contacto|prestamo|tarjeta|compromiso):(\d+)$/', (string) $request->query('desde'), $m) || ! ($user = $request->user())) {
            return null;
        }
        [, $tipo, $id] = $m;

        return match ($tipo) {
            'contacto' => ($c = Contacto::delHogar($user)->find($id)) ? ['texto' => 'Volver a '.$c->nombreCompleto(), 'url' => route('contactos.show', $c)] : null,
            'prestamo' => ($p = Prestamo::delHogar($user)->find($id)) ? ['texto' => 'Volver a '.$p->nombre, 'url' => route('prestamos.show', $p)] : null,
            'tarjeta' => ($t = TarjetaCredito::delHogar($user)->find($id)) ? ['texto' => 'Volver a la tarjeta '.$t->nombre, 'url' => route('tarjetas.show', $t)] : null,
            'compromiso' => ($c = Compromiso::where('hogar_id', $user->hogar_id)->find($id)) ? ['texto' => 'Volver a '.$c->descripcion, 'url' => route('compromisos.show', $c)] : null,
        };
    }
}
