<?php

use App\Models\Compromiso;
use App\Models\User;
use App\Notifications\CommitmentDueNotification;
use App\Services\CicloEfectivoService;
use App\Services\CompromisoService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('efectivo:cerrar-ciclos', function () {
    User::where('onboarding_completed', true)->each(function (User $user) {
        app(CicloEfectivoService::class)->closeIfNeeded($user);
        app(CompromisoService::class)->sincronizar($user);
    });
    $this->info('Ciclos procesados.');
})->purpose('Cierra ciclos de efectivo y genera compromisos recurrentes');

Artisan::command('compromisos:notificar-pendientes', function () {
    Compromiso::with('user')->where('estado', Compromiso::PENDIENTE)->whereNotNull('fecha_esperada')->whereDate('fecha_esperada', '<=', now())->each(function (Compromiso $compromiso) {
        $count = $compromiso->user->notifications()->where('type', CommitmentDueNotification::class)->get()->filter(fn ($notification) => (string) ($notification->data['compromiso_id'] ?? '') === (string) $compromiso->id)->count();
        if ($count < 5) {
            $compromiso->user->notify(new CommitmentDueNotification($compromiso, $count + 1));
        }
    });
    $this->info('Recordatorios procesados.');
})->purpose('Envía recordatorios de compromisos pendientes');
