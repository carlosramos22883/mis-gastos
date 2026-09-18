<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Moneda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        // Obtener solo las monedas activas, clave = codigo, valor = nombre
        $monedas = Moneda::where('activo', true)
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->toArray();

        return view('profile.edit', [
            'user' => $request->user(),
            'monedas' => $monedas,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null; // Deshabilita hasta que verifique
            $user->sendEmailVerificationNotification(); // ¡Envía el nuevo correo automáticamente!
        }

        $user->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ], [], [
            'password' => 'Contraseña',
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Redirigir al login enviando la variable de estado 'account-deleted'
        return redirect('/')->with('status', 'account-deleted');
    }

    public function updateAvatar(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($request->hasFile('avatar')) {
            // Eliminar avatar anterior
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            // La imagen ya viene recortada de Croppie
            $image = $request->file('avatar');

            // Generar nombre único
            $filename = 'avatars/'.uniqid().'.webp';

            // Guardar imagen
            Storage::disk('public')->put($filename, file_get_contents($image->getRealPath()));

            $user->avatar = $filename;
            $user->save();
        }

        // Si la petición es AJAX/Fetch, responde con JSON con la nueva URL
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'avatar_url' => asset('storage/'.$user->avatar).'?v='.time(),
            ]);
        }

        return back()->with('status', 'avatar-updated');
    }
}
