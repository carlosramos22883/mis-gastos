<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[OA\Info(title: "Mis Gastos API", version: "1.0.0")]
#[OA\Server(url: "http://localhost:8081")]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
class AuthController extends Controller
{
    #[OA\Post(
        path: "/api/register",
        summary: "Registro de usuario",
        tags: ["Autenticación"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password", "password_confirmation"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Carlos Ramos"),
                    new OA\Property(property: "email", type: "string", format: "email", example: "carlos@test.com"),
                    new OA\Property(property: "password", type: "string", format: "password", example: "12345678"),
                    new OA\Property(property: "password_confirmation", type: "string", format: "password", example: "12345678")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Usuario registrado exitosamente",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "user", type: "object"),
                        new OA\Property(property: "token", type: "string"),
                        new OA\Property(property: "message", type: "string", example: "Usuario registrado exitosamente")
                    ]
                )
            ),
            new OA\Response(response: 422, description: "Error de validación")
        ]
    )]
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Usuario registrado exitosamente'
        ], 201);
    }

    #[OA\Post(
        path: "/api/login",
        summary: "Login de usuario",
        tags: ["Autenticación"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email", example: "carlos@test.com"),
                    new OA\Property(property: "password", type: "string", format: "password", example: "12345678")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Login exitoso",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "user", type: "object"),
                        new OA\Property(property: "token", type: "string"),
                        new OA\Property(property: "message", type: "string", example: "Login exitoso")
                    ]
                )
            ),
            new OA\Response(response: 422, description: "Credenciales incorrectas")
        ]
    )]
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas'],
            ]);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Login exitoso'
        ]);
    }

    #[OA\Get(
        path: "/api/user",
        summary: "Obtener usuario autenticado",
        tags: ["Autenticación"],
        security: [["bearerAuth"]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Usuario autenticado",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "email", type: "string")
                    ]
                )
            ),
            new OA\Response(response: 401, description: "No autenticado")
        ]
    )]
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    #[OA\Post(
        path: "/api/logout",
        summary: "Cerrar sesión",
        tags: ["Autenticación"],
        security: [["bearerAuth"]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Sesión cerrada exitosamente",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string", example: "Sesión cerrada exitosamente")
                    ]
                )
            )
        ]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente'
        ]);
    }

        #[OA\Post(
        path: "/api/auth/google",
        summary: "Login con Google (Android)",
        description: "Autenticación mediante ID token de Google. El cliente Android obtiene el token desde Credential Manager y lo envía aquí. Laravel valida con Google y devuelve un token de Sanctum.",
        tags: ["Autenticación"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["id_token"],
                properties: [
                    new OA\Property(
                        property: "id_token", 
                        type: "string", 
                        description: "ID token JWT obtenido de Google Credential Manager",
                        example: "eyJhbGciOiJSUzI1NiIsImtpZCI6IjFh..."
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Login con Google exitoso",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "user", type: "object"),
                        new OA\Property(property: "token", type: "string")
                    ]
                )
            ),
            new OA\Response(
                response: 401, 
                description: "Token de Google inválido o no emitido para esta aplicación"
            ),
            new OA\Response(
                response: 422, 
                description: "Error de validación (falta id_token)"
            ),
            new OA\Response(
                response: 500, 
                description: "Error interno del servidor al autenticar con Google"
            )
        ]
    )]
    public function googleLogin(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        try {
            // 1. Le preguntamos directamente a Google si el token es válido
            $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $request->id_token
            ]);

            // Si Google dice que el token es inválido o expiró
            if ($response->failed()) {
                return response()->json(['error' => 'Token de Google inválido o expirado'], 401);
            }

            $googleUser = $response->json();

            // 2. SEGURIDAD: Verificamos que el token fue emitido para TU aplicación
            // 'aud' (audiencia) debe coincidir con tu GOOGLE_CLIENT_ID (el de tipo Web) en el .env
            if ($googleUser['aud'] !== env('GOOGLE_CLIENT_ID')) {
                return response()->json(['error' => 'Token no emitido para esta aplicación'], 401);
            }

            // 3. Buscamos al usuario por su google_id (en Google se llama 'sub') o lo creamos
            $user = User::firstOrCreate(
                ['google_id' => $googleUser['sub']],
                [
                    'name' => $googleUser['name'] ?? 'Usuario Google',
                    'email' => $googleUser['email'],
                    'avatar' => $googleUser['picture'] ?? null,
                    'password' => Hash::make(Str::random(24)),
                ]
            );

            // 4. Si el usuario ya existía por email pero no tenía google_id o avatar, lo actualizamos
            if (!$user->google_id || !$user->avatar) {
                $user->update([
                    'google_id' => $googleUser['sub'],
                    'avatar' => $googleUser['picture'] ?? $user->avatar,
                ]);
            }

            // 5. Generamos el token de Sanctum para que Android lo guarde
            $token = $user->createToken('android-app-token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al autenticar con Google',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
