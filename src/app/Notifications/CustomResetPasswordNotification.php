<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CustomResetPasswordNotification extends Notification
{
    use Queueable;

    public $token;
    public $actionUrl;

    public function __construct($token, $actionUrl = null)
    {
        $this->token = $token;
        $this->actionUrl = $actionUrl;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Generar URL si no se proporcionó
        if (!$this->actionUrl) {
            $this->actionUrl = url(route('password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
        }

        return (new MailMessage)
            ->subject('Recuperación de Contraseña - Mis Gastos')
            ->view('emails.password-reset', [
                'token' => $this->token,
                'actionUrl' => $this->actionUrl,
                'user' => $notifiable,
            ]);
    }
}