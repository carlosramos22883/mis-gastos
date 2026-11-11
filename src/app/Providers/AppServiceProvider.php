<?php

namespace App\Providers;

use App\Models\Compromiso;
use App\Models\MovimientoCuenta;
use App\Models\MovimientoEfectivo;
use App\Models\MovimientoTarjeta;
use App\Services\DashboardService;
use App\Services\PresupuestoService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Un gasto o un compromiso de una categoría guardado o borrado mueve el presupuesto de esa categoría (súper, gasolina…)
        foreach ([MovimientoEfectivo::class, MovimientoCuenta::class, MovimientoTarjeta::class, Compromiso::class] as $modelo) {
            $modelo::saved(function ($m) {
                app(PresupuestoService::class)->alCambiarMovimiento($m);
                DashboardService::olvidarAlertas($m->hogar_id); // la campana vuelve a calcular sus avisos
            });
            $modelo::deleted(function ($m) {
                app(PresupuestoService::class)->alCambiarMovimiento($m);
                DashboardService::olvidarAlertas($m->hogar_id);
            });
        }
    }
}
