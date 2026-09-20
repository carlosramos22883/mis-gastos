<?php

namespace App\Notifications;

use App\Models\Compromiso;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommitmentDueNotification extends Notification
{
    use Queueable;

    public function __construct(public Compromiso $compromiso, public int $number) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Compromiso pendiente',
            'message' => "Recordatorio {$this->number}/5: {$this->compromiso->descripcion} sigue pendiente.",
            'compromiso_id' => $this->compromiso->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Compromiso pendiente - Mis Gastos')
            ->greeting('Hola '.$notifiable->name)
            ->line("El compromiso «{$this->compromiso->descripcion}» sigue pendiente.")
            ->line("Este es el recordatorio {$this->number} de un máximo de 5.")
            ->action('Revisar compromiso', route('compromisos.show', $this->compromiso));
    }
}
