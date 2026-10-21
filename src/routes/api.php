<?php

use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\CompromisoController;
use App\Http\Controllers\Api\ContactoController;
use App\Http\Controllers\Api\CuentaController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EfectivoController;
use App\Http\Controllers\Api\HogarController;
use App\Http\Controllers\Api\IngresoController;
use App\Http\Controllers\Api\PrestamoController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TarjetaController;
use App\Http\Controllers\Api\TransferenciaController;
use Illuminate\Support\Facades\Route;

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
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::apiResource('contactos', ContactoController::class)
            ->middlewareFor(['index', 'show'], 'can:contactos.view')
            ->middlewareFor('store', 'can:contactos.create')
            ->middlewareFor('update', 'can:contactos.edit')
            ->middlewareFor('destroy', 'can:contactos.delete');
        Route::controller(ContactoController::class)->group(function () {
            Route::get('contactos/{contacto}/foto', 'foto')->middleware('can:contactos.view')->name('contactos.foto');
            Route::post('contactos/{contacto}/deudas', 'guardarDeuda')->middleware('can:deudores.create')->name('contactos.deudas.store');
            Route::delete('deudas/{deuda}', 'eliminarDeuda')->middleware('can:deudores.delete')->name('deudas.destroy');
            Route::post('contactos/{contacto}/pagos', 'guardarPago')->middleware('can:deudores.create')->name('contactos.pagos.store');
            Route::delete('deuda-pagos/{pago}', 'eliminarPago')->middleware('can:deudores.delete')->name('deuda-pagos.destroy');
        });

        Route::apiResource('ingresos', IngresoController::class)->parameters(['ingresos' => 'fuente'])
            ->middlewareFor(['index', 'show'], 'can:ingresos.view')
            ->middlewareFor('store', 'can:ingresos.create')
            ->middlewareFor('update', 'can:ingresos.edit')
            ->middlewareFor('destroy', 'can:ingresos.delete');
        Route::controller(IngresoController::class)->group(function () {
            Route::get('ingresos/{fuente}/propuesta', 'propuesta')->middleware('can:ingresos.view')->name('ingresos.propuesta');
            Route::post('ingresos/{fuente}/recibos', 'guardarRecibo')->middleware('can:ingresos.create')->name('ingresos.recibos.store');
            Route::delete('recibos/{ingreso}', 'eliminarRecibo')->middleware('can:ingresos.delete')->name('recibos.destroy');
        });

        Route::apiResource('prestamos', PrestamoController::class)
            ->middlewareFor(['index', 'show'], 'can:prestamos.view')
            ->middlewareFor('store', 'can:prestamos.create')
            ->middlewareFor('update', 'can:prestamos.edit')
            ->middlewareFor('destroy', 'can:prestamos.delete');
        Route::controller(PrestamoController::class)->group(function () {
            Route::get('prestamos/{prestamo}/proyeccion', 'proyeccion')->middleware('can:prestamos.view')->name('prestamos.proyeccion');
            Route::post('prestamos/{prestamo}/pagos', 'guardarPago')->middleware('can:prestamos.create')->name('prestamos.pagos.store');
            Route::delete('prestamo-pagos/{pago}', 'eliminarPago')->middleware('can:prestamos.delete')->name('prestamo-pagos.destroy');
        });

        Route::prefix('hogar')->name('hogar.')->controller(HogarController::class)->group(function () {
            Route::get('/', 'show')->middleware('can:hogar.view')->name('show');
            Route::patch('/', 'update')->middleware('can:hogar.edit')->name('update');
            Route::post('invitaciones', 'invitar')->middleware('can:hogar.edit')->name('invitar');
            Route::delete('invitaciones/{invitacion}', 'cancelar')->middleware('can:hogar.edit')->name('cancelar');
            Route::post('invitaciones/{invitacion}/aceptar', 'aceptar')->middleware('can:hogar.view')->name('aceptar');
            Route::post('invitaciones/{invitacion}/rechazar', 'rechazar')->middleware('can:hogar.view')->name('rechazar');
            Route::post('salir', 'salir')->middleware('can:hogar.view')->name('salir');
            Route::delete('miembros/{miembro}', 'quitar')->middleware('can:hogar.edit')->name('quitar');
        });

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

        Route::get('compromisos/{compromiso}/origenes-pago', [CompromisoController::class, 'origenesPago'])->middleware('can:compromisos.view')->name('compromisos.origenes-pago');
        Route::post('compromisos/{compromiso}/pagar', [CompromisoController::class, 'pagar'])->middleware('can:compromisos.view')->name('compromisos.pagar');
        Route::apiResource('compromisos', CompromisoController::class)
            ->middlewareFor(['index', 'show'], 'can:compromisos.view')
            ->middlewareFor('store', 'can:compromisos.create')
            ->middlewareFor('update', 'can:compromisos.edit')
            ->middlewareFor('destroy', 'can:compromisos.delete');

        // Cuentas y billeteras, con sus movimientos
        Route::apiResource('cuentas', CuentaController::class)
            ->middlewareFor(['index', 'show'], 'can:cuentas.view')
            ->middlewareFor('store', 'can:cuentas.create')
            ->middlewareFor('update', 'can:cuentas.edit')
            ->middlewareFor('destroy', 'can:cuentas.delete');
        Route::prefix('cuentas/{cuenta}/movimientos')->name('cuentas.movimientos.')->controller(CuentaController::class)->group(function () {
            Route::post('/', 'guardarMovimiento')->middleware('can:cuentas.create')->name('store');
            Route::put('{movimiento}', 'actualizarMovimiento')->middleware('can:cuentas.edit')->name('update');
            Route::delete('{movimiento}', 'eliminarMovimiento')->middleware('can:cuentas.delete')->name('destroy');
        });

        // Transferencias entre efectivo y cuentas (desde una tarjeta = avance)
        Route::controller(TransferenciaController::class)->prefix('transferencias')->name('transferencias.')->group(function () {
            Route::get('opciones', 'opciones')->middleware('can:cuentas.view')->name('opciones');
            Route::post('/', 'store')->middleware('can:cuentas.create')->name('store');
            Route::delete('{transferencia}', 'destroy')->middleware('can:cuentas.delete')->name('destroy');
        });

        // Tarjetas de crédito, con sus movimientos
        Route::apiResource('tarjetas', TarjetaController::class)
            ->middlewareFor(['index', 'show'], 'can:tarjetas.view')
            ->middlewareFor('store', 'can:tarjetas.create')
            ->middlewareFor('update', 'can:tarjetas.edit')
            ->middlewareFor('destroy', 'can:tarjetas.delete');
        Route::prefix('tarjetas/{tarjeta}/movimientos')->name('tarjetas.movimientos.')->controller(TarjetaController::class)->group(function () {
            Route::post('/', 'guardarMovimiento')->middleware('can:tarjetas.create')->name('store');
            Route::put('{movimiento}', 'actualizarMovimiento')->middleware('can:tarjetas.edit')->name('update');
            Route::delete('{movimiento}', 'eliminarMovimiento')->middleware('can:tarjetas.delete')->name('destroy');
        });
    });
});

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {

    // Gestión de Usuarios
    Route::apiResource('usuarios', UserController::class)->except('show');

    // Gestión de Roles
    Route::apiResource('roles', RoleController::class)->except('show');
});
