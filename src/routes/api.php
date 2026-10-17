<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\CompromisoController;
use App\Http\Controllers\Api\EfectivoController;
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

    // Módulos financieros (mismas reglas que la web, vía servicios compartidos)
    Route::name('api.')->group(function () {
        Route::get('/catalogos', CatalogoController::class)->name('catalogos');

        Route::apiResource('categorias', CategoriaController::class)->except('show')
            ->middlewareFor('index', 'can:categorias.view')
            ->middlewareFor('store', 'can:categorias.create')
            ->middlewareFor('update', 'can:categorias.edit')
            ->middlewareFor('destroy', 'can:categorias.delete');

        Route::apiResource('efectivo', EfectivoController::class)->except('show')
            ->parameters(['efectivo' => 'movimiento'])
            ->middlewareFor('index', 'can:efectivo.view')
            ->middlewareFor('store', 'can:efectivo.create')
            ->middlewareFor('update', 'can:efectivo.edit')
            ->middlewareFor('destroy', 'can:efectivo.delete');

        Route::apiResource('compromisos', CompromisoController::class)
            ->middlewareFor(['index', 'show'], 'can:compromisos.view')
            ->middlewareFor('store', 'can:compromisos.create')
            ->middlewareFor('update', 'can:compromisos.edit')
            ->middlewareFor('destroy', 'can:compromisos.delete');
    });
});

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    
    // Gestión de Usuarios
    Route::apiResource('usuarios', UserController::class)->except('show');
    
    // Gestión de Roles
    Route::apiResource('roles', RoleController::class)->except('show');
});