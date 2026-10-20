<?php

namespace App\Http\Controllers;

use App\Http\Requests\HogarInvitacionRequest;
use App\Http\Requests\HogarRequest;
use App\Models\HogarInvitacion;
use App\Models\User;
use App\Services\HogarService;
use Illuminate\Http\Request;

class HogarController extends Controller
{
    public function __construct(private HogarService $hogares) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $hogar = $user->hogar()->with(['miembros', 'administrador'])->first();

        return view('hogar.index', [
            'hogar' => $hogar,
            'esAdmin' => $hogar->esAdministrador($user),
            'enviadas' => $hogar->invitaciones()->where('estado', HogarInvitacion::PENDIENTE)->latest()->get(),
            'recibidas' => $this->hogares->invitacionesPara($user),
        ]);
    }

    public function update(HogarRequest $request)
    {
        $this->hogares->renombrar($request->user(), $request->validated('nombre'));

        return response()->json(['message' => 'Nombre del hogar actualizado.']);
    }

    public function invitar(HogarInvitacionRequest $request)
    {
        $invitacion = $this->hogares->invitar($request->user(), $request->validated('email'));

        return response()->json(['message' => "Invitación enviada a {$invitacion->email}."]);
    }

    public function cancelar(Request $request, HogarInvitacion $invitacion)
    {
        $this->hogares->cancelar($request->user(), $invitacion);

        return response()->json(['message' => 'Invitación cancelada.']);
    }

    public function aceptar(Request $request, HogarInvitacion $invitacion)
    {
        $hogar = $this->hogares->aceptar($request->user(), $invitacion);

        return response()->json(['message' => "Ahora eres parte de «{$hogar->nombre}»."]);
    }

    public function rechazar(Request $request, HogarInvitacion $invitacion)
    {
        $this->hogares->rechazar($request->user(), $invitacion);

        return response()->json(['message' => 'Invitación rechazada.']);
    }

    public function salir(Request $request)
    {
        $this->hogares->salir($request->user());

        return response()->json(['message' => 'Saliste del hogar. Ahora tienes un hogar propio, vacío.']);
    }

    public function quitar(Request $request, User $miembro)
    {
        $this->hogares->quitar($request->user(), $miembro);

        return response()->json(['message' => "{$miembro->name} ya no es parte del hogar."]);
    }
}
