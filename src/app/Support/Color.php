<?php

namespace App\Support;

class Color
{
    /** Color de texto legible (oscuro o blanco) sobre un fondo hexadecimal. */
    public static function textoSobre(?string $fondo, string $porDefecto = '#64748B'): string
    {
        $hex = ltrim($fondo ?: $porDefecto, '#');
        $rgb = array_map('hexdec', str_split($hex, 2));
        $luminancia = (0.299 * $rgb[0]) + (0.587 * $rgb[1]) + (0.114 * $rgb[2]);

        return $luminancia > 186 ? '#111827' : '#FFFFFF';
    }
}
