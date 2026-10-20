<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(path: '/api/dashboard', summary: 'Página de inicio: ciclo, efectivo, cuentas, tarjetas, liquidez, deudas, gastos por categoría, historial y alertas', tags: ['Dashboard'], security: [['bearerAuth' => []]],
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
