<?php

namespace App\Support;

use App\Models\Evento;
use App\Services\EventoService;
use Illuminate\Support\Facades\Storage;

/**
 * Imagen PNG de la tabla de un evento (como la hoja de cálculo: personas en las filas, productos en las columnas, y el
 * total, la propina, lo del festejado y lo que paga cada uno), con la foto de la cuenta debajo si la tiene. La genera
 * el servidor: web, Android e iOS comparten la misma imagen.
 */
class EventoImagen
{
    private const MARGEN = 40;

    private const FILA = 50;

    private const FUENTE = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    private const FUENTE_NEGRITA = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    /** @var array<string, int> */
    private array $colores = [];

    private \GdImage $img;

    /** @param array $resumen EventoService::resumen() */
    public function png(Evento $evento, array $resumen, string $simbolo = '$'): string
    {
        $evento->loadMissing('participantes.user', 'participantes.contacto', 'consumos.participantes');
        $dinero = fn (float $v) => $simbolo.' '.number_format($v, 2);
        $hayPropina = $resumen['propina'] > 0;
        $hayFestejo = $evento->participantes->contains('es_festejado', true);

        // Columnas: [título, ancho, fn(participante) => [texto, color, negrita], total]
        $columnas = [];
        foreach ($evento->consumos as $c) {
            $porId = $c->participantes->pluck('pivot.cantidad', 'id');
            $columnas[] = ['titulo' => $c->descripcion.' ('.$dinero((float) $c->precio).')', 'vertical' => true, 'ancho' => 112,
                'celda' => function ($p) use ($porId, $c) {
                    $n = (int) ($porId[$p->id] ?? 0);

                    return $n ? [($n > 1 ? $n.'× ' : '').number_format($n * (float) $c->precio, 2), 'texto', false] : ['', 'texto', false];
                },
                'pie' => number_format($c->total(), 2)];
        }
        $r = fn ($p) => $resumen['participantes'][$p->id];
        $columnas[] = ['titulo' => 'Total', 'ancho' => 130, 'celda' => fn ($p) => [$dinero($r($p)['consumo']), 'texto', false], 'pie' => $dinero($resumen['subtotal'])];
        if ($hayPropina) {
            $columnas[] = ['titulo' => 'Propina', 'ancho' => 120, 'celda' => fn ($p) => [$dinero($r($p)['propina']), 'texto', false], 'pie' => $dinero($resumen['propina'])];
            $columnas[] = ['titulo' => 'Con propina', 'ancho' => 150, 'celda' => fn ($p) => [$dinero($r($p)['consumo'] + $r($p)['propina']), 'texto', false], 'pie' => $dinero($resumen['total'])];
        }
        if ($hayFestejo) {
            $columnas[] = ['titulo' => 'Cumpleañero', 'ancho' => 175, 'celda' => fn ($p) => $p->es_festejado ? ['', 'texto', false] : [$dinero($r($p)['festejo']), 'texto', false],
                'pie' => $dinero(collect($resumen['participantes'])->sum('festejo'))];
        }
        $columnas[] = ['titulo' => 'A pagar', 'ancho' => 160, 'celda' => fn ($p) => match ($r($p)['estado']) {
            'festejado' => ['Festejado', 'ambar', true],
            'pagado' => [$dinero($r($p)['total']).' ✓', 'verde', true],
            default => [$dinero($r($p)['total']), 'texto', true],
        }, 'pie' => $dinero($resumen['total'])];

        $anchoNombre = 290;
        $ancho = max(1080, self::MARGEN * 2 + $anchoNombre + array_sum(array_column($columnas, 'ancho')));
        $altoTitulos = min(300, max(110, 30 + max(array_map(fn ($c) => empty($c['vertical']) ? 0 : $this->anchoTexto($c['titulo'], 19), $columnas) ?: [0])));
        $filas = $evento->participantes->count();

        // La foto de la cuenta, escalada al ancho de la imagen
        $foto = null;
        if ($evento->foto && Storage::disk(EventoService::DISCO)->exists($evento->foto)) {
            $foto = @imagecreatefromstring(Storage::disk(EventoService::DISCO)->get($evento->foto)) ?: null;
        }
        // Al ancho de la imagen, pero sin pasar de 1400 px de alto (un ticket largo se reduce y se centra)
        $anchoFoto = $ancho - 2 * self::MARGEN;
        $altoFoto = $foto ? (int) round(imagesy($foto) * $anchoFoto / imagesx($foto)) : 0;
        if ($altoFoto > 1400) {
            $anchoFoto = (int) round($anchoFoto * 1400 / $altoFoto);
            $altoFoto = 1400;
        }

        $alto = 170 + 20 + $altoTitulos + ($filas + 1) * self::FILA + 40 + ($foto ? 60 + $altoFoto + 30 : 0);
        $this->img = imagecreatetruecolor($ancho, $alto);
        foreach (['fondo' => '#F3F4F6', 'blanco' => '#FFFFFF', 'primario' => '#0A0A5E', 'texto' => '#111827', 'gris' => '#6B7280',
            'linea' => '#E5E7EB', 'cebra' => '#EEF2FF', 'verde' => '#047857', 'ambar' => '#B45309', 'pie' => '#E0E7FF'] as $nombre => $hex) {
            $this->colores[$nombre] = imagecolorallocate($this->img, ...sscanf($hex, '#%02x%02x%02x'));
        }
        imagefilledrectangle($this->img, 0, 0, $ancho, $alto, $this->colores['fondo']);

        // Encabezado
        imagefilledrectangle($this->img, 0, 0, $ancho, 170, $this->colores['primario']);
        $this->texto($evento->nombre, 36, self::MARGEN, 70, 'blanco', true, $ancho - 2 * self::MARGEN);
        $sub = $evento->fecha->translatedFormat('j \d\e F \d\e Y').($evento->lugar ? ' · '.$evento->lugar : '').' · Pagó la cuenta: '.($evento->pagador()?->nombre() ?? '—');
        $this->texto($sub, 22, self::MARGEN, 120, 'blanco', false, $ancho - 2 * self::MARGEN);

        // Títulos de las columnas (los productos, en vertical como en la hoja)
        $y0 = 190;
        $yTabla = $y0 + $altoTitulos;
        imagefilledrectangle($this->img, self::MARGEN, $y0, $ancho - self::MARGEN, $yTabla + ($filas + 1) * self::FILA, $this->colores['blanco']);
        $x = self::MARGEN + $anchoNombre;
        foreach ($columnas as $c) {
            if (! empty($c['vertical'])) {
                $this->texto($this->recortar($c['titulo'], 19, false, $altoTitulos - 20), 19, $x + (int) ($c['ancho'] / 2) + 8, $yTabla - 12, 'texto', false, null, 90);
            } else {
                $this->texto($c['titulo'], 19, $x + $c['ancho'] - 10 - $this->anchoTexto($c['titulo'], 19, true), $yTabla - 14, 'texto', true);
            }
            $x += $c['ancho'];
        }
        imageline($this->img, self::MARGEN, $yTabla, $ancho - self::MARGEN, $yTabla, $this->colores['linea']);

        // Una fila por persona y la fila de totales
        foreach ($evento->participantes->values() as $i => $p) {
            $y = $yTabla + $i * self::FILA;
            if ($i % 2 === 1) {
                imagefilledrectangle($this->img, self::MARGEN, $y + 1, $ancho - self::MARGEN, $y + self::FILA, $this->colores['cebra']);
            }
            $this->texto($p->nombre().($p->es_festejado ? ' (festejado)' : ''), 20, self::MARGEN + 14, $y + 33, 'texto', false, $anchoNombre - 20);
            $x = self::MARGEN + $anchoNombre;
            foreach ($columnas as $c) {
                [$texto, $color, $negrita] = $c['celda']($p);
                if ($texto !== '') {
                    $tam = empty($c['vertical']) ? 20 : 18;
                    $this->texto($texto, $tam, $x + $c['ancho'] - 10 - $this->anchoTexto($texto, $tam, $negrita), $y + 33, $color, $negrita);
                }
                $x += $c['ancho'];
            }
        }
        $y = $yTabla + $filas * self::FILA;
        imagefilledrectangle($this->img, self::MARGEN, $y, $ancho - self::MARGEN, $y + self::FILA, $this->colores['pie']);
        $this->texto('Total', 20, self::MARGEN + 14, $y + 33, 'texto', true);
        $x = self::MARGEN + $anchoNombre;
        foreach ($columnas as $c) {
            $tam = empty($c['vertical']) ? 20 : 17;
            $this->texto($c['pie'], $tam, $x + $c['ancho'] - 10 - $this->anchoTexto($c['pie'], $tam, true), $y + 33, 'texto', true);
            $x += $c['ancho'];
        }

        if ($foto) {
            $y += self::FILA + 40 + 20;
            $this->texto('Foto de la cuenta', 22, self::MARGEN, $y, 'gris', true);
            imagecopyresampled($this->img, $foto, (int) (($ancho - $anchoFoto) / 2), $y + 20, 0, 0, $anchoFoto, $altoFoto, imagesx($foto), imagesy($foto));
            imagedestroy($foto);
        }

        ob_start();
        imagepng($this->img);
        imagedestroy($this->img);

        return (string) ob_get_clean();
    }

    private function fuente(bool $negrita): string
    {
        return base_path($negrita ? self::FUENTE_NEGRITA : self::FUENTE);
    }

    private function texto(string $texto, int $tam, int $x, int $y, string $color, bool $negrita = false, ?int $maximo = null, int $angulo = 0): void
    {
        if ($maximo) {
            $texto = $this->recortar($texto, $tam, $negrita, $maximo);
        }
        imagettftext($this->img, $tam * 0.75, $angulo, $x, $y, $this->colores[$color], $this->fuente($negrita), $texto);
    }

    private function anchoTexto(string $texto, int $tam, bool $negrita = false): int
    {
        $caja = imagettfbbox($tam * 0.75, 0, $this->fuente($negrita), $texto);

        return $caja ? $caja[2] - $caja[0] : 0;
    }

    /** Corta el texto con "…" para que quepa en $maximo píxeles. */
    private function recortar(string $texto, int $tam, bool $negrita, int $maximo): string
    {
        if ($this->anchoTexto($texto, $tam, $negrita) <= $maximo) {
            return $texto;
        }
        while (mb_strlen($texto) > 1 && $this->anchoTexto($texto.'…', $tam, $negrita) > $maximo) {
            $texto = mb_substr($texto, 0, -1);
        }

        return rtrim($texto).'…';
    }
}
