<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Admin - Roles", description: "Gestión de roles y permisos")]
class RoleController extends Controller
{
    #[OA\Get(
        path: "/api/admin/roles",
        summary: "Listar roles",
        tags: ["Admin - Roles"],
        security: [["bearerAuth"]],
        responses: [new OA\Response(response: 200, description: "Lista de roles")]
    )]
    public function index(Request $request)
    {
        if (!$request->user()->can('roles.view')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }
        return response()->json(Role::with('permissions')->get());
    }

    #[OA\Post(
        path: "/api/admin/roles",
        summary: "Crear nuevo rol",
        tags: ["Admin - Roles"],
        security: [["bearerAuth"]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "permissions", type: "array", items: new OA\Items(type: "string"), example: ["users.view", "users.edit"]),
                ]
            )
        ),
        responses: [new OA\Response(response: 201, description: "Rol creado")]
    )]
    public function store(Request $request)
    {
        if (!$request->user()->can('roles.create')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $validated['name']]);
        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json(['message' => 'Rol creado.', 'role' => $role->load('permissions')], 201);
    }

    #[OA\Patch(
        path: "/api/admin/roles/{id}",
        summary: "Actualizar rol",
        tags: ["Admin - Roles"],
        security: [["bearerAuth"]],
        responses: [new OA\Response(response: 200, description: "Rol actualizado")]
    )]
    public function update(Request $request, $id)
    {
        if (!$request->user()->can('roles.edit')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $role = Role::findOrFail($id);
        $validated = $request->validate([
            'name' => ['required', 'string', 'unique:roles,name,' . $role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,name'],
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        return response()->json(['message' => 'Rol actualizado.', 'role' => $role->fresh()->load('permissions')]);
    }

    #[OA\Delete(
        path: "/api/admin/roles/{id}",
        summary: "Eliminar rol",
        tags: ["Admin - Roles"],
        security: [["bearerAuth"]],
        responses: [new OA\Response(response: 200, description: "Rol eliminado")]
    )]
    public function destroy(Request $request, $id)
    {
        if (!$request->user()->can('roles.delete')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $role = Role::findOrFail($id);
        if ($role->name === 'Administrador') {
            return response()->json(['message' => 'No se puede eliminar el rol Administrador.'], 400);
        }

        $role->delete();
        return response()->json(['message' => 'Rol eliminado.']);
    }
}