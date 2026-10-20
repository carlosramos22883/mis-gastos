<?php

namespace App\Http\Resources;

use App\Models\Hogar;
use App\Models\HogarInvitacion;
use App\Services\HogarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Hogar */
class HogarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'es_administrador' => $this->esAdministrador($user),
            'fecha_corte_dia' => (int) $user->fecha_corte_dia,
            'miembros' => $this->miembros->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->name,
                'email' => $m->email,
                'avatar' => $m->avatar,
                'es_administrador' => $this->esAdministrador($m),
            ])->values(),
            'invitaciones_enviadas' => $this->invitaciones()->where('estado', HogarInvitacion::PENDIENTE)->latest()->get()
                ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'fecha' => $i->created_at->toDateString()])->values(),
            'invitaciones_recibidas' => app(HogarService::class)->invitacionesPara($user)
                ->map(fn ($i) => ['id' => $i->id, 'hogar' => $i->hogar->nombre, 'invitado_por' => $i->invitadoPor?->name, 'fecha' => $i->created_at->toDateString()])->values(),
        ];
    }
}
