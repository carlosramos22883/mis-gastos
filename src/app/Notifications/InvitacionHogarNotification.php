<?php

namespace App\Notifications;

use App\Models\HogarInvitacion;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitacionHogarNotification extends Notification
{
    use Queueable;

    public function __construct(public HogarInvitacion $invitacion) {}

    /** A quien aún no tiene cuenta solo le llega el correo (para que se registre con ese email). */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Invitación a un hogar',
            'message' => "{$this->quien()} te invitó a unirte a «{$this->invitacion->hogar->nombre}».",
            'url' => route('hogar.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Te invitaron a un hogar - Mis Gastos')
            ->greeting('Hola')
            ->line("{$this->quien()} te invitó a unirte a «{$this->invitacion->hogar->nombre}» en Mis Gastos.")
            ->line('Al unirte compartirán el efectivo, las cuentas, las tarjetas y los compromisos.')
            ->line('Si aún no tienes cuenta, regístrate con este mismo correo y verás la invitación.')
            ->action('Ver invitación', route('hogar.index'));
    }

    private function quien(): string
    {
        return $this->invitacion->invitadoPor?->name ?? 'Alguien';
    }
}
