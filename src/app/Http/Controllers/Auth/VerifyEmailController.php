<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;


class VerifyEmailController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // 1. Validar la firma del enlace
        if (! URL::hasValidSignature($request)) {
            return redirect()->route('login')
                ->with(
                    'error',
                    'El enlace de verificación no es válido o ha expirado.'
                );
        }

        // 2. Obtener el usuario del enlace
        $userId = $request->route('id');
        $hash = $request->route('hash');

        $user = User::find($userId);

        // 3. Verificar que el usuario exista y que el hash corresponda
        if (! $user || ! hash_equals(
            (string) $hash,
            sha1($user->getEmailForVerification())
        )) {
            return redirect()->route('login')
                ->with(
                    'error',
                    'El enlace de verificación no es válido o ha expirado.'
                );
        }

        // 4. IMPORTANTE:
        // Si hay alguien logueado y NO es el usuario del enlace,
        // no permitimos verificar la cuenta.
        if (Auth::check() && Auth::id() !== $user->id) {
            return redirect()->route('dashboard')
                ->with(
                    'verification_error',
                    'Tienes una sesión iniciada con otro usuario. Para verificar esta cuenta, primero debes cerrar tu sesión actual e iniciar sesión con la cuenta correspondiente.'
                );
        }

        // 5. Si la cuenta ya estaba verificada
        if ($user->hasVerifiedEmail()) {

            if (Auth::id() === $user->id) {
                return redirect()->route('dashboard')
                    ->with(
                        'verification_info',
                        'Tu correo ya estaba verificado.'
                    );
            }

            return redirect()->route('login')
                ->with(
                    'verification_info',
                    'Esta cuenta ya fue verificada anteriormente.'
                );
        }

        // 6. Verificar la cuenta
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // 7. Si el usuario está logueado y es el propietario
        if (Auth::id() === $user->id) {
            return redirect()->route('dashboard')
                ->with(
                    'verification_success',
                    '¡Correo verificado exitosamente!'
                );
        }

        // 8. Si no está logueado, enviarlo al login
        return redirect()->route('login')
            ->with('status', 'verification-success')
            ->with(
                'verification_success',
                '¡Correo verificado exitosamente! Ya puedes iniciar sesión con tu cuenta.'
            );
    }
}
