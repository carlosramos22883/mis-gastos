<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Auth\VerifyEmailController;
use \App\Http\Controllers\Admin\MonedaController;
use \App\Http\Controllers\Admin\BancoController;
use \App\Http\Controllers\Admin\TipoCuentaController;
use \App\Http\Controllers\Admin\MarcaRedController;


Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['throttle:6,1'])->get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->name('verification.verify');

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

    // Exportación de usuarios
    Route::get('usuarios/export', [UserManagementController::class, 'export'])
        ->name('usuarios.export');

    // Gestión de Usuarios
    Route::resource('usuarios', UserManagementController::class)
        ->middleware('can:users.view');

    // Exportación de roles
    Route::get('roles/export', [RoleManagementController::class, 'export'])
        ->name('roles.export');

    // Gestión de Roles y Permisos
    Route::resource('roles', RoleManagementController::class)
        ->middleware('can:roles.view');



    // ============================================
    // CATÁLOGOS DEL SISTEMA
    // ============================================
    Route::prefix('catalogos')->name('catalogos.')->group(function () {  // ← Cambia aquí

       /* // Monedas
        Route::resource('monedas', \App\Http\Controllers\Admin\MonedaController::class)->except(['show']);

        // Bancos
        Route::resource('bancos', \App\Http\Controllers\Admin\BancoController::class)->except(['show']);

        // Tipos de Cuenta
        Route::resource('tipos-cuenta', \App\Http\Controllers\Admin\TipoCuentaController::class)->except(['show']);

        // Marcas de Red
        Route::resource('marca-red', \App\Http\Controllers\Admin\MarcaRedController::class)->except(['show']);
*/

        
    
        // --- MONEDAS ---
        Route::get('monedas', [MonedaController::class, 'index'])->name('monedas.index');
        Route::get('monedas/create', [MonedaController::class, 'create'])->name('monedas.create');
        Route::post('monedas', [MonedaController::class, 'store'])->name('monedas.store');
        Route::get('monedas/{moneda}/edit', [MonedaController::class, 'edit'])->name('monedas.edit');
        Route::put('monedas/{moneda}', [MonedaController::class, 'update'])->name('monedas.update');
        Route::delete('monedas/{moneda}', [MonedaController::class, 'destroy'])->name('monedas.destroy');

        // --- BANCOS ---
        Route::get('bancos', [BancoController::class, 'index'])->name('bancos.index');
        Route::get('bancos/create', [BancoController::class, 'create'])->name('bancos.create');
        Route::post('bancos', [BancoController::class, 'store'])->name('bancos.store');
        Route::get('bancos/{banco}/edit', [BancoController::class, 'edit'])->name('bancos.edit');
        Route::put('bancos/{banco}', [BancoController::class, 'update'])->name('bancos.update');
        Route::delete('bancos/{banco}', [BancoController::class, 'destroy'])->name('bancos.destroy');

        // --- TIPOS DE CUENTA ---
        Route::get('tipos-cuenta', [TipoCuentaController::class, 'index'])->name('tipos-cuenta.index');
        Route::get('tipos-cuenta/create', [TipoCuentaController::class, 'create'])->name('tipos-cuenta.create');
        Route::post('tipos-cuenta', [TipoCuentaController::class, 'store'])->name('tipos-cuenta.store');
        Route::get('tipos-cuenta/{tipoCuenta}/edit', [TipoCuentaController::class, 'edit'])->name('tipos-cuenta.edit');
        Route::put('tipos-cuenta/{tipoCuenta}', [TipoCuentaController::class, 'update'])->name('tipos-cuenta.update');
        Route::delete('tipos-cuenta/{tipoCuenta}', [TipoCuentaController::class, 'destroy'])->name('tipos-cuenta.destroy');

        // --- MARCAS DE RED ---
        Route::get('marcas-red', [MarcaRedController::class, 'index'])->name('marcas-red.index');
        Route::get('marcas-red/create', [MarcaRedController::class, 'create'])->name('marcas-red.create');
        Route::post('marcas-red', [MarcaRedController::class, 'store'])->name('marcas-red.store');
        Route::get('marcas-red/{marcaRed}/edit', [MarcaRedController::class, 'edit'])->name('marcas-red.edit');
        Route::put('marcas-red/{marcaRed}', [MarcaRedController::class, 'update'])->name('marcas-red.update');
        Route::delete('marcas-red/{marcaRed}', [MarcaRedController::class, 'destroy'])->name('marcas-red.destroy');

    

    });
});

// Rutas para autenticación social
Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);



require __DIR__ . '/auth.php';
