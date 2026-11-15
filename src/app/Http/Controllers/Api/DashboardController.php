<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(path: '/api/dashboard', summary: 'Página de inicio: ciclo, efectivo, cuentas, tarjetas, liquidez, deudas, gastos por categoría, historial y alertas', description: 'tope: gastado + comprometido (por pagar) frente al tope; presupuestado, sin_asignar y fuera (por categoría sin presupuesto: categoria_id, gastado, comprometido, movimientos [clase, id, lugar_id = cuenta o tarjeta, fecha, descripcion, origen, monto] y pendientes [compromisos por pagar: id, descripcion, fecha, monto]). Siempre cuadra: queda = sin_asignar + suma de lo que queda en los presupuestos − fuera_total. prestamos_ciclo: cuotas y pagadas (cuántas), y por préstamo: cuota del mes, pagado (capital + interés + cargos + mora; la planilla va dentro), mora, pagada, fecha del último pago, extra (de más) y falta. avances_ciclo: por tarjeta, lo sacado (avances y pagos a otras tarjetas) y lo pagado a ella; flujos de dónde a dónde; total sacado (0 = no se muestra).', tags: ['Dashboard'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Resumen')])]
    public function __invoke(Request $request, DashboardService $dashboard)
    {
        $d = $dashboard->resumen($request->user());
        $banco = fn ($b) => $b ? ['id' => $b->id, 'nombre' => $b->nombre, 'logo' => $b->logo ? asset('storage/'.$b->logo) : null] : null;
        $d['cuentas'] = array_map(fn ($c) => ['banco' => $banco($c['banco'])] + $c, $d['cuentas']);
        $d['tarjetas'] = array_map(fn ($t) => ['banco' => $banco($t['banco']), 'marca' => $t['marca'] ? ['nombre' => $t['marca']->nombre, 'logo' => $t['marca']->logo ? asset('storage/'.$t['marca']->logo) : null] : null] + $t, $d['tarjetas']);

        return response()->json(['data' => $d]);
    }
}
