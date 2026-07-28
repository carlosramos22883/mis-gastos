<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialAuthController;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit'); // Asumimos que profile.view lo tiene todos

    // Protegemos la actualización con el permiso específico
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('can:profile.update')
        ->name('profile.update');

    Route::patch('/profile/avatar', [ProfileController::class, 'updateAvatar'])
        ->middleware('can:profile.avatar.update')
        ->name('profile.avatar.update');

    // Protegemos la eliminación (solo Admin o quien tenga el permiso)
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->middleware('can:profile.delete')
        ->name('profile.destroy');
});

// Rutas de Configuración del Sistema (Solo para usuarios con permisos)
Route::middleware(['auth', 'verified'])->prefix('configuracion')->name('admin.')->group(function () {

    // Gestión de Usuarios
    Route::resource('usuarios', \App\Http\Controllers\Admin\UserManagementController::class)
        ->middleware('can:users.view'); // Requiere al menos ver para entrar al listado

    // Gestión de Roles y Permisos
    Route::resource('roles', \App\Http\Controllers\Admin\RoleManagementController::class)
        ->middleware('can:roles.view');
});

// Rutas para autenticación social
Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);



require __DIR__ . '/auth.php';
