<?php

namespace App\Services;

use App\Models\CargoFijoTarjeta;
use App\Models\CategoriaPersonal;
use App\Models\Compromiso;
use App\Models\CompromisoRecurrente;
use App\Models\Contacto;
use App\Models\Cuenta;
use App\Models\Deuda;
use App\Models\DeudaPago;
use App\Models\EscenarioProyeccion;
use App\Models\Evento;
use App\Models\FuenteIngreso;
use App\Models\Hogar;
use App\Models\HogarInvitacion;
use App\Models\Ingreso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Models\Prestamo;
use App\Models\PrestamoPago;
use App\Models\TarjetaCredito;
use App\Models\Transferencia;
use App\Models\User;
use App\Notifications\InvitacionHogarNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Hogar: los miembros comparten un solo fondo. Cada registro guarda quién lo hizo (user_id)
 * y pertenece al hogar (hogar_id). Nada es privado entre miembros.
 */
class HogarService
{
    /** Modelos cuyos registros pertenecen al hogar. */
    public const MODELOS = [
        CategoriaPersonal::class, MovimientoEfectivo::class, CompromisoRecurrente::class, Compromiso::class,
        Cuenta::class, Transferencia::class, MovimientoCuenta::class, TarjetaCredito::class, MovimientoTarjeta::class,
        Contacto::class, Deuda::class, DeudaPago::class, Prestamo::class, PrestamoPago::class,
        FuenteIngreso::class, Ingreso::class, CargoFijoTarjeta::class, Evento::class, EscenarioProyeccion::class,
    ];

    /** Modelos con categoría (para unificar categorías al fusionar hogares). */
    private const CON_CATEGORIA = [
        MovimientoEfectivo::class, MovimientoCuenta::class, MovimientoTarjeta::class, Compromiso::class, CompromisoRecurrente::class, CargoFijoTarjeta::class,
    ];

    public function crearPara(User $user): Hogar
    {
        $hogar = Hogar::create(['nombre' => 'Hogar de '.$user->name, 'user_id' => $user->id]);
        $this->asignar($user, $hogar);

        return $hogar;
    }

    public function renombrar(User $user, string $nombre): Hogar
    {
        $this->asegurarAdministrador($user);
        $hogar = $user->hogar;
        $hogar->update(['nombre' => $nombre]);

        return $hogar;
    }

    public function invitar(User $user, string $email): HogarInvitacion
    {
        $this->asegurarAdministrador($user);
        $email = mb_strtolower(trim($email));
        $hogar = $user->hogar;

        if ($hogar->miembros()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Esa persona ya es miembro de tu hogar.']);
        }
        if ($hogar->invitaciones()->where('email', $email)->where('estado', HogarInvitacion::PENDIENTE)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya hay una invitación pendiente para ese correo.']);
        }

        $invitacion = $hogar->invitaciones()->create([
            'email' => $email, 'invitado_por' => $user->id, 'estado' => HogarInvitacion::PENDIENTE,
        ]);

        $invitado = User::whereRaw('LOWER(email) = ?', [$email])->first();
        $invitado
            ? $invitado->notify(new InvitacionHogarNotification($invitacion))
            : Notification::route('mail', $email)->notify(new InvitacionHogarNotification($invitacion));

        return $invitacion;
    }

    /** Invitaciones pendientes dirigidas al correo del usuario. */
    public function invitacionesPara(User $user)
    {
        return HogarInvitacion::with('hogar', 'invitadoPor')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
            ->where('estado', HogarInvitacion::PENDIENTE)
            ->where('hogar_id', '!=', $user->hogar_id)
            ->latest()->get();
    }

    /**
     * Al aceptar, el usuario se muda al hogar que lo invitó. Si era el único miembro de su hogar,
     * sus registros se suman al nuevo (las categorías con el mismo nombre se unifican);
     * si había más miembros, sus registros se quedan con ellos.
     */
    public function aceptar(User $user, HogarInvitacion $invitacion): Hogar
    {
        $this->validarDestinatario($user, $invitacion);

        return DB::transaction(function () use ($user, $invitacion) {
            $destino = Hogar::lockForUpdate()->findOrFail($invitacion->hogar_id);
            $origen = $user->hogar;

            if ($origen && $origen->miembros()->count() === 1) {
                $this->fusionar($origen, $destino);
                $this->asignar($user, $destino);
                $origen->delete();
            } else {
                $this->moverMiembro($user, $destino);
            }

            $this->igualarCiclo($user, $destino);
            $this->recalcularArrastres($user->fresh());
            $invitacion->update(['estado' => HogarInvitacion::ACEPTADA, 'respondida_en' => now()]);

            return $destino;
        });
    }

    public function rechazar(User $user, HogarInvitacion $invitacion): void
    {
        $this->validarDestinatario($user, $invitacion);
        $invitacion->update(['estado' => HogarInvitacion::RECHAZADA, 'respondida_en' => now()]);
    }

    public function cancelar(User $user, HogarInvitacion $invitacion): void
    {
        $this->asegurarAdministrador($user);
        abort_unless((int) $invitacion->hogar_id === (int) $user->hogar_id, 404);
        if ($invitacion->estado !== HogarInvitacion::PENDIENTE) {
            throw ValidationException::withMessages(['invitacion' => 'La invitación ya fue respondida.']);
        }
        $invitacion->update(['estado' => HogarInvitacion::CANCELADA, 'respondida_en' => now()]);
    }

    /** El usuario deja el hogar y arranca uno propio, vacío. Sus registros se quedan en el hogar. */
    public function salir(User $user): Hogar
    {
        if ($user->hogar->miembros()->count() === 1) {
            throw ValidationException::withMessages(['hogar' => 'Eres el único miembro de este hogar.']);
        }

        return DB::transaction(function () use ($user) {
            $this->cederAdministracion($user);

            return $this->crearPara($user);
        });
    }

    public function quitar(User $admin, User $miembro): void
    {
        $this->asegurarAdministrador($admin);
        abort_unless((int) $miembro->hogar_id === (int) $admin->hogar_id, 404);
        if ($miembro->is($admin)) {
            throw ValidationException::withMessages(['miembro' => 'Para irte del hogar usa "Salir del hogar".']);
        }
        $this->salir($miembro);
    }

    /** Antes de borrar un usuario: sus registros pasan a otro miembro; si era el único, el hogar se va con él. */
    public function antesDeEliminarUsuario(User $user): void
    {
        $hogar = $user->hogar;
        if (! $hogar) {
            return;
        }

        $otro = $hogar->miembros()->whereKeyNot($user->id)->first();
        if (! $otro) {
            $user->forceFill(['hogar_id' => null])->saveQuietly();
            $hogar->delete();

            return;
        }

        foreach (self::MODELOS as $modelo) {
            $modelo::where('hogar_id', $hogar->id)->where('user_id', $user->id)->update(['user_id' => $otro->id]);
        }
        $this->cederAdministracion($user);
    }

    /** Renombrar, invitar y quitar miembros es solo de quien administra el hogar. */
    private function asegurarAdministrador(User $user): void
    {
        abort_unless($user->hogar?->esAdministrador($user), 403, 'Solo quien administra el hogar puede hacer esto.');
    }

    private function asignar(User $user, Hogar $hogar): void
    {
        $user->forceFill(['hogar_id' => $hogar->id])->saveQuietly();
        $user->setRelation('hogar', $hogar)->unsetRelation('miembrosHogar');
    }

    private function validarDestinatario(User $user, HogarInvitacion $invitacion): void
    {
        abort_unless(mb_strtolower($invitacion->email) === mb_strtolower($user->email), 404);
        if ($invitacion->estado !== HogarInvitacion::PENDIENTE) {
            throw ValidationException::withMessages(['invitacion' => 'Esta invitación ya no está vigente.']);
        }
        if ((int) $invitacion->hogar_id === (int) $user->hogar_id) {
            throw ValidationException::withMessages(['invitacion' => 'Ya eres miembro de ese hogar.']);
        }
    }

    private function moverMiembro(User $user, Hogar $destino): void
    {
        $this->cederAdministracion($user);
        $this->asignar($user, $destino);
    }

    /** Si el usuario administra su hogar y hay más miembros, la administración pasa al siguiente. */
    private function cederAdministracion(User $user): void
    {
        $hogar = $user->hogar;
        if ($hogar && $hogar->esAdministrador($user)) {
            $siguiente = $hogar->miembros()->whereKeyNot($user->id)->orderBy('id')->first();
            $hogar->update(['user_id' => $siguiente?->id]);
        }
    }

    private function igualarCiclo(User $user, Hogar $destino): void
    {
        $referencia = $destino->miembros()->whereKeyNot($user->id)->first();
        if ($referencia) {
            $user->forceFill($referencia->only(['fecha_corte_dia', 'zona_horaria']))->saveQuietly();
        }
    }

    private function fusionar(Hogar $origen, Hogar $destino): void
    {
        // Los saldos arrastrados son informativos: se descartan y se recalculan sobre el fondo unido.
        MovimientoEfectivo::where('hogar_id', $origen->id)->where('es_saldo_arrastrado', true)->delete();

        $existentes = CategoriaPersonal::where('hogar_id', $destino->id)->get()->keyBy(fn ($c) => $c->tipo.'|'.mb_strtolower($c->nombre));
        foreach (CategoriaPersonal::where('hogar_id', $origen->id)->get() as $categoria) {
            $igual = $existentes->get($categoria->tipo.'|'.mb_strtolower($categoria->nombre));
            if ($igual) {
                foreach (self::CON_CATEGORIA as $modelo) {
                    $modelo::where('categoria_personal_id', $categoria->id)->update(['categoria_personal_id' => $igual->id]);
                }
                $categoria->delete();
            }
        }

        foreach (self::MODELOS as $modelo) {
            $modelo::where('hogar_id', $origen->id)->update(['hogar_id' => $destino->id]);
        }
    }

    /** Cada saldo arrastrado vuelve a ser el efectivo al cierre del día anterior. */
    private function recalcularArrastres(User $user): void
    {
        $ciclos = app(CicloEfectivoService::class);
        $user->movimientosEfectivo()->where('es_saldo_arrastrado', true)->get()
            ->each(fn (MovimientoEfectivo $m) => $m->update([
                'monto' => max(0, $ciclos->balance($user, Carbon::parse($m->fecha)->subDay())),
            ]));
    }
}
