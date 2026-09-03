<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Models\Moneda;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ], [], [
            'name' => 'Nombre',
            'email' => 'Correo electrónico',
            'password' => 'Contraseña',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'moneda_preferida' => Moneda::where('nombre', 'Dólar Estadounidense')->value('id') ?? 1, // ID del Dólar (USD)
            'fecha_corte_dia' => 31, // valor por defecto
            'zona_horaria' => 'America/El_Salvador', // Valor por defecto
        ]);

        // Asignar rol por defecto "Usuario" si no se especificó uno
        $roleName = $validated['role'] ?? 'Usuario';
        $user->assignRole($roleName);

        event(new Registered($user));

        // REDIRIGIR AL LOGIN CON MENSAJE DE VERIFICACIÓN
        return redirect()->route('login')->with('status', 'verification-link-sent');
    }
}
