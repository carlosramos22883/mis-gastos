<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Admin - Usuarios", description: "Gestión de usuarios del sistema (Requiere rol Administrador)")]
class UserController extends Controller
{
    #[OA\Get(
        path: "/api/admin/usuarios",
        summary: "Listar usuarios",
        tags: ["Admin - Usuarios"],
        security: [["bearerAuth"]],
        parameters: [
            new OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "page", in: "query", schema: new OA\Schema(type: "integer")),
        ],
        responses: [
            new OA\Response(response: 200, description: "Lista de usuarios paginada"),
            new OA\Response(response: 401, description: "No autenticado"),
            new OA\Response(response: 403, description: "Sin permiso 'users.view'"),
        ]
    )]
    public function index(Request $request)
    {
        if (!$request->user()->can('users.view')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $query = User::query();
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
        }

        return response()->json($query->latest()->paginate(15));
    }

    #[OA\Post(
        path: "/api/admin/usuarios",
        summary: "Crear nuevo usuario",
        tags: ["Admin - Usuarios"],
        security: [["bearerAuth"]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password", "role"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string"),
                    new OA\Property(property: "role", type: "string", description: "Nombre del rol, ej: 'Usuario'"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Usuario creado"),
            new OA\Response(response: 422, description: "Error de validación"),
        ]
    )]
    public function store(Request $request)
    {
        if (!$request->user()->can('users.create')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()],
            'role' => ['required', 'exists:roles,name'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        $user->assignRole($validated['role']);

        return response()->json(['message' => 'Usuario creado exitosamente.', 'user' => $user], 201);
    }

    #[OA\Patch(
        path: "/api/admin/usuarios/{id}",
        summary: "Actualizar usuario",
        tags: ["Admin - Usuarios"],
        security: [["bearerAuth"]],
        parameters: [new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer"))],
        responses: [
            new OA\Response(response: 200, description: "Usuario actualizado"),
            new OA\Response(response: 404, description: "Usuario no encontrado"),
        ]
    )]
    public function update(Request $request, $id)
    {
        if (!$request->user()->can('users.edit')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()],
            'role' => ['required', 'exists:roles,name'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->save();
        $user->syncRoles([$validated['role']]);

        return response()->json(['message' => 'Usuario actualizado.', 'user' => $user]);
    }

    #[OA\Delete(
        path: "/api/admin/usuarios/{id}",
        summary: "Eliminar usuario",
        tags: ["Admin - Usuarios"],
        security: [["bearerAuth"]],
        parameters: [new OA\Parameter(name: "id", in: "path", schema: new OA\Schema(type: "integer"))],
        responses: [
            new OA\Response(response: 200, description: "Usuario eliminado"),
        ]
    )]
    public function destroy(Request $request, $id)
    {
        if (!$request->user()->can('users.delete')) {
            return response()->json(['message' => 'Acción no autorizada.'], 403);
        }

        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes eliminarte a ti mismo.'], 400);
        }

        $user->delete();
        return response()->json(['message' => 'Usuario eliminado.']);
    }
}