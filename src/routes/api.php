<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\RoleController;

// Rutas públicas
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [AuthController::class, 'googleLogin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Rutas protegidas (requieren token)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Actualizar Información del Perfil (Protegido por permiso de Spatie)
    Route::patch('/profile', [ProfileController::class, 'update'])
         ->middleware('can:profile.update')
         ->name('api.profile.update');
    // Actualizar Avatar (Protegido por permiso de Spatie)
    Route::patch('/profile/avatar', [ProfileController::class, 'updateAvatar'])
         ->middleware('can:profile.avatar.update')
         ->name('api.profile.avatar.update');    
});

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    
    // Gestión de Usuarios
    Route::apiResource('usuarios', UserController::class);
    
    // Gestión de Roles
    Route::apiResource('roles', RoleController::class);
});