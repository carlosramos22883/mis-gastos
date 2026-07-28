<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA; // <-- IMPORTANTE: Usamos la misma notación que AuthController

#[OA\Tag(
    name: "Perfil",
    description: "Endpoints para la gestión del perfil de usuario"
)]
class ProfileController extends Controller
{
    #[OA\Patch(
        path: "/api/profile/avatar",
        summary: "Actualizar avatar del usuario",
        description: "Sube una nueva imagen de perfil. Requiere autenticación con Bearer Token y permiso 'profile.avatar.update'.",
        tags: ["Perfil"],
        security: [["bearerAuth"]], // Coincide con el esquema de seguridad de tu AuthController
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: "avatar",
                            type: "string",
                            format: "binary",
                            description: "Archivo de imagen (jpg, png, webp, gif). Máximo 5MB."
                        )
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Avatar actualizado exitosamente",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Avatar actualizado correctamente"),
                        new OA\Property(property: "avatar_url", type: "string", example: "http://localhost:8081/storage/avatars/123.webp")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "No autenticado",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Unauthenticated.")
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Sin permisos",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "No tienes permisos para realizar esta acción.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Error de validación",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "The avatar field must be an image."),
                        new OA\Property(property: "errors", type: "object")
                    ]
                )
            )
        ]
    )]
    public function updateAvatar(Request $request)
    {
        // 1. Verificar permisos (Spatie)
        if (!$request->user()->can('profile.avatar.update')) {
            return response()->json(['message' => 'No tienes permisos para realizar esta acción.'], 403);
        }

        // 2. Validar la imagen
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $user = $request->user();

        // 3. Procesar la imagen
        if ($request->hasFile('avatar')) {
            // Eliminar avatar anterior si existe
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $image = $request->file('avatar');
            $filename = 'avatars/' . uniqid() . '.webp';

            // Guardar nueva imagen
            Storage::disk('public')->put($filename, file_get_contents($image->getRealPath()));

            $user->avatar = $filename;
            $user->save();

            // 4. Respuesta JSON exitosa
            return response()->json([
                'success' => true,
                'message' => 'Avatar actualizado correctamente',
                'avatar_url' => asset('storage/' . $filename),
            ], 200);
        }

        return response()->json(['message' => 'No se proporcionó ningún archivo.'], 400);
    }


    #[OA\Patch(
        path: "/api/profile",
        summary: "Actualizar información del perfil",
        description: "Actualiza el nombre y correo electrónico del usuario autenticado. Si el correo cambia, se requerirá verificación nuevamente por seguridad.",
        tags: ["Perfil"],
        security: [["bearerAuth"]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Carlos Ramos"),
                    new OA\Property(property: "email", type: "string", format: "email", example: "carlos@test.com")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Perfil actualizado exitosamente",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Perfil actualizado correctamente."),
                        new OA\Property(property: "user", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "No autenticado",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Unauthenticated.")
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Sin permisos",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "No tienes permisos para realizar esta acción.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Error de validación",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "The email has already been taken."),
                        new OA\Property(property: "errors", type: "object")
                    ]
                )
            )
        ]
    )]
    public function update(Request $request)
    {
        // 1. Verificar permisos (Spatie)
        if (!$request->user()->can('profile.update')) {
            return response()->json(['message' => 'No tienes permisos para realizar esta acción.'], 403);
        }

        // 2. Validar los datos
        // Nota: 'unique:users,email,' . $request->user()->id ignora al propio usuario para que no falle al guardar su mismo correo.
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
        ]);

        $user = $request->user();
        $user->fill($request->only(['name', 'email']));

        // 3. Lógica de Seguridad: Si cambia el email, deshabilitar verificación y enviar nuevo correo
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->sendEmailVerificationNotification();
        }

        $user->save();

        // 4. Respuesta JSON exitosa
        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado correctamente.',
            'user' => $user, // Devolvemos el usuario actualizado por si la app necesita refrescar sus datos
        ], 200);
    }
}
