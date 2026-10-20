<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HogarInvitacionRequest;
use App\Http\Requests\HogarRequest;
use App\Http\Resources\HogarResource;
use App\Models\HogarInvitacion;
use App\Models\User;
use App\Services\HogarService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Hogar', description: 'Miembros que comparten un solo fondo (efectivo, ciclo, cuentas, tarjetas y compromisos)')]
class HogarController extends Controller
{
    public function __construct(private readonly HogarService $hogares) {}

    #[OA\Get(path: '/api/hogar', summary: 'Hogar del usuario: miembros e invitaciones', tags: ['Hogar'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Hogar')])]
    public function show(Request $request)
    {
        return new HogarResource($request->user()->hogar->fresh('miembros'));
    }

    #[OA\Patch(path: '/api/hogar', summary: 'Renombrar el hogar (solo quien lo administra)', tags: ['Hogar'], security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'nombre', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Actualizado'), new OA\Response(response: 403, description: 'No administra el hogar')])]
    public function update(HogarRequest $request)
    {
        $hogar = $this->hogares->renombrar($request->user(), $request->validated('nombre'));

        return new HogarResource($hogar->fresh('miembros'));
    }

    #[OA\Post(path: '/api/hogar/invitaciones', summary: 'Invitar por correo (solo quien administra)', tags: ['Hogar'], security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'email', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Invitación enviada'), new OA\Response(response: 422, description: 'Ya es miembro o ya hay una pendiente')])]
    public function invitar(HogarInvitacionRequest $request)
    {
        $invitacion = $this->hogares->invitar($request->user(), $request->validated('email'));

        return response()->json(['data' => ['id' => $invitacion->id, 'email' => $invitacion->email]], 201);
    }

    #[OA\Delete(path: '/api/hogar/invitaciones/{id}', summary: 'Cancelar una invitación enviada', tags: ['Hogar'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Cancelada')])]
    public function cancelar(Request $request, HogarInvitacion $invitacion)
    {
        $this->hogares->cancelar($request->user(), $invitacion);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/hogar/invitaciones/{id}/aceptar', summary: 'Aceptar una invitación recibida', tags: ['Hogar'], security: [['bearerAuth' => []]],
        description: 'Si el usuario era el único miembro de su hogar, sus registros se suman al nuevo.',
        responses: [new OA\Response(response: 200, description: 'Nuevo hogar')])]
    public function aceptar(Request $request, HogarInvitacion $invitacion)
    {
        $hogar = $this->hogares->aceptar($request->user(), $invitacion);

        return new HogarResource($hogar->fresh('miembros'));
    }

    #[OA\Post(path: '/api/hogar/invitaciones/{id}/rechazar', summary: 'Rechazar una invitación recibida', tags: ['Hogar'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Rechazada')])]
    public function rechazar(Request $request, HogarInvitacion $invitacion)
    {
        $this->hogares->rechazar($request->user(), $invitacion);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/hogar/salir', summary: 'Salir del hogar (los registros se quedan en él)', tags: ['Hogar'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Nuevo hogar propio, vacío')])]
    public function salir(Request $request)
    {
        $hogar = $this->hogares->salir($request->user());

        return new HogarResource($hogar->fresh('miembros'));
    }

    #[OA\Delete(path: '/api/hogar/miembros/{id}', summary: 'Quitar a un miembro (solo quien administra)', tags: ['Hogar'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 204, description: 'Quitado')])]
    public function quitar(Request $request, User $miembro)
    {
        $this->hogares->quitar($request->user(), $miembro);

        return response()->noContent();
    }
}
