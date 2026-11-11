<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Foto de la cuenta de un evento: se endereza según su orientación EXIF (las fotos del celular vienen "acostadas"),
 * se reduce a 1600 px como máximo y se guarda en JPEG. El servidor no tiene la extensión exif: la orientación se lee
 * directo del JPEG.
 */
class FotoCuenta
{
    private const MAXIMO = 1600;

    /** @return string JPEG listo para guardar */
    public static function normalizar(UploadedFile $archivo): string
    {
        $datos = (string) file_get_contents($archivo->getRealPath());
        $img = @imagecreatefromstring($datos) ?: throw new \InvalidArgumentException('No se pudo leer la imagen.');

        $img = match (self::orientacion($datos)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        $ancho = imagesx($img);
        $alto = imagesy($img);
        $escala = min(1, self::MAXIMO / max($ancho, $alto));
        if ($escala < 1) {
            $img = imagescale($img, (int) round($ancho * $escala), (int) round($alto * $escala));
        }

        ob_start();
        imagejpeg($img, null, 82);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Orientación EXIF (1 a 8) de un JPEG; 1 si no tiene. */
    private static function orientacion(string $jpeg): int
    {
        if (! str_starts_with($jpeg, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $largo = strlen($jpeg);
        while ($pos + 4 < $largo && $jpeg[$pos] === "\xFF") {
            $marca = ord($jpeg[$pos + 1]);
            $tam = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($marca === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, $tam - 8);
                $le = substr($tiff, 0, 2) === 'II';
                $u16 = fn (int $o) => unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] ?? 0;
                $u32 = fn (int $o) => unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] ?? 0;
                $ifd = $u32(4);
                $entradas = $u16($ifd);
                for ($i = 0; $i < $entradas; $i++) {
                    $e = $ifd + 2 + $i * 12;
                    if ($u16($e) === 0x0112) {
                        return $u16($e + 8) ?: 1;
                    }
                }

                return 1;
            }
            if ($marca === 0xDA) { // empiezan los datos de la imagen: ya no hay metadatos
                return 1;
            }
            $pos += 2 + $tam;
        }

        return 1;
    }
}
