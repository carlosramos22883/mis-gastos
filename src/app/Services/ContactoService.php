<?php

namespace App\Services;

use App\Models\Compromiso;
use App\Models\Contacto;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contactos del hogar: personas, tiendas, empresas e instituciones.
 *
 *  - La foto va al disco privado ("local") y solo se sirve a miembros del hogar (ruta contactos.foto).
 *  - "¿Con quién?" en los formularios acepta un contacto existente o "nuevo:Nombre" (se crea al vuelo).
 *  - Un contacto con historial (deudas, préstamos, compromisos o movimientos) no se elimina: se desactiva.
 */
class ContactoService
{
    public const DISCO = 'local';

    public function __construct(private readonly DeudorService $deudas, private readonly PrestamoService $prestamos) {}

    public function crear(User $user, array $data, ?UploadedFile $foto = null): Contacto
    {
        $contacto = $user->contactos()->create($data + ['user_id' => $user->id, 'activo' => true]);
        if ($foto) {
            $this->guardarFoto($contacto, $foto);
        }

        return $contacto;
    }

    public function actualizar(Contacto $contacto, array $data, ?UploadedFile $foto = null, bool $quitarFoto = false): Contacto
    {
        $contacto->update($data);
        if ($foto) {
            $this->guardarFoto($contacto, $foto);
        } elseif ($quitarFoto) {
            $this->borrarFoto($contacto);
            $contacto->update(['foto' => null]);
        }

        return $contacto;
    }

    public function eliminar(Contacto $contacto): void
    {
        if ($this->tieneHistorial($contacto)) {
            throw ValidationException::withMessages(['contacto' => 'Este contacto tiene historial (deudas, préstamos, compromisos o movimientos). Desactívalo en lugar de eliminarlo.']);
        }
        $this->borrarFoto($contacto);
        $contacto->delete();
    }

    /** Id del contacto para "¿Con quién?": un id del hogar, "nuevo:Nombre Apellido" (se crea) o vacío. */
    public function resolver(User $user, mixed $valor): ?int
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        if (ctype_digit($valor)) {
            return $user->contactos()->whereKey((int) $valor)->value('id')
                ?? throw ValidationException::withMessages(['contacto_id' => 'Elige un contacto válido.']);
        }
        if (! str_starts_with($valor, 'nuevo:') || ($nombre = Str::limit(trim(substr($valor, 6)), 100, '')) === '') {
            throw ValidationException::withMessages(['contacto_id' => 'Elige un contacto válido.']);
        }
        [$primero, $resto] = array_pad(explode(' ', $nombre, 2), 2, null);
        $existente = $user->contactos()->whereRaw("LOWER(TRIM(nombre || ' ' || COALESCE(apellido, ''))) = ?", [mb_strtolower($nombre)])->value('id');

        return $existente ?? $this->crear($user, ['nombre' => $primero, 'apellido' => $resto, 'tipo' => 'persona'])->id;
    }

    /** Opciones para el selector "¿Con quién?" (con foto). */
    public function opciones(User $user, ?int $incluir = null): array
    {
        return $user->contactos()->where(fn ($q) => $q->where('activo', true)->when($incluir, fn ($q) => $q->orWhere('id', $incluir)))
            ->orderBy('nombre')->orderBy('apellido')->get()
            ->mapWithKeys(fn (Contacto $c) => [$c->id => ['label' => $c->nombreCompleto(), 'logo' => $c->urlFoto()]])->all();
    }

    /** Todo lo que el hogar tiene con el contacto. */
    public function ficha(Contacto $contacto, Carbon $hoy): array
    {
        $contacto->load(['deudas' => fn ($q) => $q->withSum('pagos', 'monto')->orderBy('fecha')->orderBy('id')]);
        $this->deudas->resumir($contacto);

        $movimientos = $this->movimientos($contacto);
        $delAnio = $movimientos->filter(fn ($m) => $m['fecha']->year === $hoy->year);

        return [
            'prestamos' => $contacto->prestamos()->where('activo', true)->get()
                ->map(fn ($p) => ['prestamo' => $p, 'saldo' => $this->prestamos->saldo($p)])->all(),
            'compromisos' => $contacto->compromisos()->where('estado', '!=', Compromiso::CANCELADO)
                ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [Compromiso::PENDIENTE])->orderByDesc('fecha_esperada')->limit(12)->get(),
            'movimientos' => $movimientos->take(40)->values()->all(),
            'pagado_anio' => round((float) $delAnio->where('tipo', 'egreso')->sum('monto'), 2),
            'recibido_anio' => round((float) $delAnio->where('tipo', 'ingreso')->sum('monto'), 2),
        ];
    }

    public function tieneHistorial(Contacto $contacto): bool
    {
        return $contacto->deudas()->exists() || $contacto->prestamos()->exists() || $contacto->compromisos()->exists()
            || MovimientoEfectivo::where('contacto_id', $contacto->id)->exists()
            || MovimientoCuenta::where('contacto_id', $contacto->id)->exists()
            || MovimientoTarjeta::where('contacto_id', $contacto->id)->exists();
    }

    /** Movimientos con el contacto (efectivo, cuentas y tarjetas), del más reciente al más antiguo. */
    private function movimientos(Contacto $contacto): Collection
    {
        $fila = fn (string $donde) => fn ($m) => ['fecha' => $m->fecha, 'descripcion' => $m->descripcion, 'monto' => (float) $m->monto,
            'tipo' => in_array($m->tipo, ['ingreso', 'credito'], true) ? 'ingreso' : 'egreso', 'donde' => $donde, 'user_id' => $m->user_id];

        return collect()
            ->concat(MovimientoEfectivo::where('contacto_id', $contacto->id)->where('es_saldo_arrastrado', false)->get()->map($fila('Efectivo')))
            ->concat(MovimientoCuenta::with('cuenta')->where('contacto_id', $contacto->id)->get()->map(fn ($m) => $fila($m->cuenta->nombre)($m)))
            ->concat(MovimientoTarjeta::with('tarjeta')->where('contacto_id', $contacto->id)->get()->map(fn ($m) => $fila('Tarjeta '.$m->tarjeta->nombre)($m)))
            ->sortByDesc(fn ($m) => $m['fecha']->toDateString())->values();
    }

    private function guardarFoto(Contacto $contacto, UploadedFile $foto): void
    {
        $this->borrarFoto($contacto);
        $ruta = $foto->store("contactos/{$contacto->hogar_id}", self::DISCO);
        $contacto->update(['foto' => $ruta]);
    }

    private function borrarFoto(Contacto $contacto): void
    {
        if ($contacto->foto && Storage::disk(self::DISCO)->exists($contacto->foto)) {
            Storage::disk(self::DISCO)->delete($contacto->foto);
        }
    }
}
