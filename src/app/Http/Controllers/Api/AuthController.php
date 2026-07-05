<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

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
}