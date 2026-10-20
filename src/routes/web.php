<?php

use App\Http\Controllers\Admin\BancoController;
use App\Http\Controllers\Admin\MarcaRedController;
use App\Http\Controllers\Admin\MonedaController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\TipoCuentaController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CategoriaPersonalController;
use App\Http\Controllers\CompromisoController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\DeudorController;
use App\Http\Controllers\EstadoCuentaController;
use App\Http\Controllers\HogarController;
use App\Http\Controllers\IngresoController;
use App\Http\Controllers\MovimientoCuentaController;
use App\Http\Controllers\MovimientoEfectivoController;
use App\Http\Controllers\MovimientoTarjetaController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PagoCompromisoController;
use App\Http\Controllers\PrestamoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\TarjetaCreditoController;
use App\Http\Controllers\TransferenciaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    if (! auth()->user()->onboarding_completed) {
        return redirect()->route('onboarding.create');
    }

    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['throttle:6,1'])->get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding/progress', [OnboardingController::class, 'progress'])->name('onboarding.progress');
    Route::get('/onboarding/progress', [OnboardingController::class, 'progress'])->name('onboarding.progress.data');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::resource('compromisos', CompromisoController::class)->except([])
        ->middlewareFor(['index', 'show'], 'can:compromisos.view')
        ->middlewareFor(['create', 'store'], 'can:compromisos.create')
        ->middlewareFor(['edit', 'update'], 'can:compromisos.edit')
        ->middlewareFor('destroy', 'can:compromisos.delete');
    Route::get('compromisos/{compromiso}/pagar', [PagoCompromisoController::class, 'create'])
        ->middleware('can:compromisos.view')->name('compromisos.pagar');
    Route::post('compromisos/{compromiso}/pagar', [PagoCompromisoController::class, 'store'])
        ->middleware('can:compromisos.view')->name('compromisos.pagar.store');

    // Cuentas bancarias y billeteras
    Route::resource('cuentas', CuentaController::class)
        ->middlewareFor(['index', 'show'], 'can:cuentas.view')
        ->middlewareFor(['create', 'store'], 'can:cuentas.create')
        ->middlewareFor(['edit', 'update'], 'can:cuentas.edit')
        ->middlewareFor('destroy', 'can:cuentas.delete');
    Route::get('cuentas/{cuenta}/export', [CuentaController::class, 'export'])
        ->middleware('can:cuentas.export')->name('cuentas.export');
    Route::prefix('cuentas/{cuenta}/movimientos')->name('cuentas.movimientos.')->controller(MovimientoCuentaController::class)->group(function () {
        Route::get('create', 'create')->middleware('can:cuentas.create')->name('create');
        Route::post('/', 'store')->middleware('can:cuentas.create')->name('store');
        Route::get('{movimiento}/edit', 'edit')->middleware('can:cuentas.edit')->name('edit');
        Route::put('{movimiento}', 'update')->middleware('can:cuentas.edit')->name('update');
        Route::delete('{movimiento}', 'destroy')->middleware('can:cuentas.delete')->name('destroy');
    });

    // Transferencias entre efectivo y cuentas (y avances de tarjeta)
    Route::get('transferencias/create', [TransferenciaController::class, 'create'])
        ->middleware('can:cuentas.create')->name('transferencias.create');
    Route::post('transferencias', [TransferenciaController::class, 'store'])
        ->middleware('can:cuentas.create')->name('transferencias.store');
    Route::delete('transferencias/{transferencia}', [TransferenciaController::class, 'destroy'])
        ->middleware('can:cuentas.delete')->name('transferencias.destroy');

    Route::get('compromisos-export', [CompromisoController::class, 'export'])
        ->middleware('can:compromisos.export')->name('compromisos.export');
    Route::resource('tarjetas', TarjetaCreditoController::class)
        ->middlewareFor(['index', 'show'], 'can:tarjetas.view')
        ->middlewareFor(['create', 'store'], 'can:tarjetas.create')
        ->middlewareFor(['edit', 'update'], 'can:tarjetas.edit')
        ->middlewareFor('destroy', 'can:tarjetas.delete');
    Route::get('tarjetas-export', [TarjetaCreditoController::class, 'export'])
        ->middleware('can:tarjetas.export')->name('tarjetas.export');
    Route::prefix('tarjetas/{tarjeta}/movimientos')->name('tarjetas.movimientos.')->controller(MovimientoTarjetaController::class)->group(function () {
        Route::get('create', 'create')->middleware('can:tarjetas.create')->name('create');
        Route::post('/', 'store')->middleware('can:tarjetas.create')->name('store');
        Route::get('{movimiento}/edit', 'edit')->middleware('can:tarjetas.edit')->name('edit');
        Route::put('{movimiento}', 'update')->middleware('can:tarjetas.edit')->name('update');
        Route::delete('{movimiento}', 'destroy')->middleware('can:tarjetas.delete')->name('destroy');
    });
    Route::prefix('estados-cuenta/{estado}')->name('estados-cuenta.')->controller(EstadoCuentaController::class)->group(function () {
        Route::get('/', 'show')->middleware('can:tarjetas.view')->name('show');
        Route::patch('fecha-limite', 'fechaLimite')->middleware('can:tarjetas.edit')->name('fecha-limite');
        Route::get('mora', 'moraForm')->middleware('can:tarjetas.create')->name('mora.create');
        Route::post('mora', 'mora')->middleware('can:tarjetas.create')->name('mora.store');
    });
    // Deudores ("nos deben")
    Route::resource('deudores', DeudorController::class)->parameters(['deudores' => 'deudor'])
        ->middlewareFor(['index', 'show'], 'can:deudores.view')
        ->middlewareFor(['create', 'store'], 'can:deudores.create')
        ->middlewareFor(['edit', 'update'], 'can:deudores.edit')
        ->middlewareFor('destroy', 'can:deudores.delete');
    Route::controller(DeudorController::class)->group(function () {
        Route::get('deudores/{deudor}/deudas/create', 'crearDeuda')->middleware('can:deudores.create')->name('deudores.deudas.create');
        Route::post('deudores/{deudor}/deudas', 'guardarDeuda')->middleware('can:deudores.create')->name('deudores.deudas.store');
        Route::delete('deudas/{deuda}', 'eliminarDeuda')->middleware('can:deudores.delete')->name('deudas.destroy');
        Route::get('deudores/{deudor}/pagos/create', 'crearPago')->middleware('can:deudores.create')->name('deudores.pagos.create');
        Route::post('deudores/{deudor}/pagos', 'guardarPago')->middleware('can:deudores.create')->name('deudores.pagos.store');
        Route::delete('deuda-pagos/{pago}', 'eliminarPago')->middleware('can:deudores.delete')->name('deuda-pagos.destroy');
    });

    // Ingresos con desglose (sueldos, servicios profesionales…)
    Route::resource('ingresos', IngresoController::class)->parameters(['ingresos' => 'fuente'])
        ->middlewareFor(['index', 'show'], 'can:ingresos.view')
        ->middlewareFor(['create', 'store'], 'can:ingresos.create')
        ->middlewareFor(['edit', 'update'], 'can:ingresos.edit')
        ->middlewareFor('destroy', 'can:ingresos.delete');
    Route::controller(IngresoController::class)->group(function () {
        Route::get('ingresos/{fuente}/recibos/create', 'crearRecibo')->middleware('can:ingresos.create')->name('ingresos.recibos.create');
        Route::post('ingresos/{fuente}/recibos', 'guardarRecibo')->middleware('can:ingresos.create')->name('ingresos.recibos.store');
        Route::delete('recibos/{ingreso}', 'eliminarRecibo')->middleware('can:ingresos.delete')->name('recibos.destroy');
    });

    // Préstamos (lo que debemos)
    Route::resource('prestamos', PrestamoController::class)
        ->middlewareFor(['index', 'show'], 'can:prestamos.view')
        ->middlewareFor(['create', 'store'], 'can:prestamos.create')
        ->middlewareFor(['edit', 'update'], 'can:prestamos.edit')
        ->middlewareFor('destroy', 'can:prestamos.delete');
    Route::controller(PrestamoController::class)->group(function () {
        Route::get('prestamos/{prestamo}/simular', 'simular')->middleware('can:prestamos.view')->name('prestamos.simular');
        Route::get('prestamos/{prestamo}/pagos/create', 'crearPago')->middleware('can:prestamos.create')->name('prestamos.pagos.create');
        Route::post('prestamos/{prestamo}/pagos', 'guardarPago')->middleware('can:prestamos.create')->name('prestamos.pagos.store');
        Route::delete('prestamo-pagos/{pago}', 'eliminarPago')->middleware('can:prestamos.delete')->name('prestamo-pagos.destroy');
    });

    // Hogar: miembros que comparten el fondo
    Route::prefix('hogar')->name('hogar.')->controller(HogarController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:hogar.view')->name('index');
        Route::patch('/', 'update')->middleware('can:hogar.edit')->name('update');
        Route::post('invitaciones', 'invitar')->middleware('can:hogar.edit')->name('invitar');
        Route::delete('invitaciones/{invitacion}', 'cancelar')->middleware('can:hogar.edit')->name('cancelar');
        Route::post('invitaciones/{invitacion}/aceptar', 'aceptar')->middleware('can:hogar.view')->name('aceptar');
        Route::post('invitaciones/{invitacion}/rechazar', 'rechazar')->middleware('can:hogar.view')->name('rechazar');
        Route::post('salir', 'salir')->middleware('can:hogar.view')->name('salir');
        Route::delete('miembros/{miembro}', 'quitar')->middleware('can:hogar.edit')->name('quitar');
    });
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit'); // Asumimos que profile.view lo tiene todos
    Route::get('/notificaciones', function () {
        $notificaciones = request()->user()->notifications()->latest()->paginate(20);
        request()->user()->unreadNotifications->markAsRead();

        return view('notificaciones.index', compact('notificaciones'));
    })->name('notifications.index');

    // Protegemos la actualización con el permiso específico
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::patch('/profile/avatar', [ProfileController::class, 'updateAvatar'])
        ->name('profile.avatar.update');

    // Protegemos la eliminación (solo Admin o quien tenga el permiso)
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

    Route::get('categorias/export', [CategoriaPersonalController::class, 'export'])
        ->middleware('can:categorias.export')->name('categorias.export');
    Route::get('categorias', [CategoriaPersonalController::class, 'index'])
        ->middleware('can:categorias.view')->name('categorias.index');
    Route::get('categorias/create', [CategoriaPersonalController::class, 'create'])
        ->middleware('can:categorias.create')->name('categorias.create');
    Route::post('categorias', [CategoriaPersonalController::class, 'store'])
        ->middleware('can:categorias.create')->name('categorias.store');
    Route::get('categorias/{categoria}/edit', [CategoriaPersonalController::class, 'edit'])
        ->middleware('can:categorias.edit')->name('categorias.edit');
    Route::put('categorias/{categoria}', [CategoriaPersonalController::class, 'update'])
        ->middleware('can:categorias.edit')->name('categorias.update');
    Route::delete('categorias/{categoria}', [CategoriaPersonalController::class, 'destroy'])
        ->middleware('can:categorias.delete')->name('categorias.destroy');
    Route::get('efectivo/export', [MovimientoEfectivoController::class, 'export'])
        ->middleware('can:efectivo.export')->name('efectivo.export');
    Route::get('efectivo', [MovimientoEfectivoController::class, 'index'])
        ->middleware('can:efectivo.view')->name('efectivo.index');
    Route::get('efectivo/create', [MovimientoEfectivoController::class, 'create'])
        ->middleware('can:efectivo.create')->name('efectivo.create');
    Route::post('efectivo', [MovimientoEfectivoController::class, 'store'])
        ->middleware('can:efectivo.create')->name('efectivo.store');
    Route::get('efectivo/{movimiento}/edit', [MovimientoEfectivoController::class, 'edit'])
        ->middleware('can:efectivo.edit')->name('efectivo.edit');
    Route::put('efectivo/{movimiento}', [MovimientoEfectivoController::class, 'update'])
        ->middleware('can:efectivo.edit')->name('efectivo.update');
    Route::delete('efectivo/{movimiento}', [MovimientoEfectivoController::class, 'destroy'])
        ->middleware('can:efectivo.delete')->name('efectivo.destroy');
});

// Rutas de Configuración del Sistema: cada acción exige su propio permiso ("ver" da acceso a la pantalla)
Route::middleware(['auth', 'verified'])->prefix('configuracion')->name('admin.')->group(function () {

    // Gestión de Usuarios
    Route::get('usuarios/export', [UserManagementController::class, 'export'])
        ->middleware('can:users.export')->name('usuarios.export');
    Route::resource('usuarios', UserManagementController::class)->except('show')
        ->middlewareFor('index', 'can:users.view')
        ->middlewareFor(['create', 'store'], 'can:users.create')
        ->middlewareFor(['edit', 'update'], 'can:users.edit')
        ->middlewareFor('destroy', 'can:users.delete');

    // Gestión de Roles y Permisos
    Route::get('roles/export', [RoleManagementController::class, 'export'])
        ->middleware('can:roles.export')->name('roles.export');
    Route::resource('roles', RoleManagementController::class)->except('show')
        ->middlewareFor('index', 'can:roles.view')
        ->middlewareFor(['create', 'store'], 'can:roles.create')
        ->middlewareFor(['edit', 'update'], 'can:roles.edit')
        ->middlewareFor('destroy', 'can:roles.delete');

    // ============================================
    // CATÁLOGOS DEL SISTEMA
    // ============================================
    Route::prefix('catalogos')->name('catalogos.')->group(function () {
        $catalogos = [
            // [ruta, controlador, parámetro, prefijo del permiso]
            ['monedas', MonedaController::class, 'moneda', 'monedas'],
            ['bancos', BancoController::class, 'banco', 'bancos'],
            ['tipos-cuenta', TipoCuentaController::class, 'tipoCuenta', 'tipos_cuenta'],
            ['marcas-red', MarcaRedController::class, 'marcaRed', 'marcas-red'],
        ];

        foreach ($catalogos as [$uri, $controller, $param, $permiso]) {
            Route::get($uri, [$controller, 'index'])->name("{$uri}.index")->middleware("can:{$permiso}.view");
            Route::get("{$uri}/create", [$controller, 'create'])->name("{$uri}.create")->middleware("can:{$permiso}.create");
            Route::post($uri, [$controller, 'store'])->name("{$uri}.store")->middleware("can:{$permiso}.create");
            Route::get("{$uri}/{{$param}}/edit", [$controller, 'edit'])->name("{$uri}.edit")->middleware("can:{$permiso}.edit");
            Route::put("{$uri}/{{$param}}", [$controller, 'update'])->name("{$uri}.update")->middleware("can:{$permiso}.edit");
            Route::delete("{$uri}/{{$param}}", [$controller, 'destroy'])->name("{$uri}.destroy")->middleware("can:{$permiso}.delete");
        }
    });
});

// Rutas para autenticación social
Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

require __DIR__.'/auth.php';
