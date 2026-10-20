<?php

namespace App\Support;

class Color
{
    private const OSCURO = '#111827';

    private const CLARO = '#FFFFFF';

    /**
     * Color de texto legible sobre un fondo: entre casi negro y blanco, el de mayor contraste WCAG.
     * Con $fondo2 (ej. el otro extremo de un degradado) elige el que mejor se lea sobre ambos.
     */
    public static function textoSobre(?string $fondo, string $porDefecto = '#64748B', ?string $fondo2 = null): string
    {
        $fondos = array_filter([$fondo ?: $porDefecto, $fondo2]);
        $peor = fn (string $texto) => min(array_map(fn ($f) => self::contraste($texto, $f), $fondos));

        return $peor(self::OSCURO) >= $peor(self::CLARO) ? self::OSCURO : self::CLARO;
    }

    /** Razón de contraste WCAG entre dos colores (1 a 21). */
    public static function contraste(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminancia($a), self::luminancia($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** El mismo color mezclado con negro (porcentaje 0-100): para el otro extremo de un degradado, opaco. */
    public static function oscurecer(string $hex, float $porcentaje): string
    {
        $f = 1 - $porcentaje / 100;

        return '#'.implode('', array_map(fn ($c) => str_pad(dechex((int) round($c * $f)), 2, '0', STR_PAD_LEFT), self::rgb($hex)));
    }

    private static function luminancia(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return array_map('hexdec', str_split(substr(str_pad($hex, 6, '0'), 0, 6), 2));
    }
}
