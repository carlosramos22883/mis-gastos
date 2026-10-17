<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banco;
use App\Models\MarcaRed;
use App\Models\Moneda;
use App\Models\TipoCuenta;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Catálogos', description: 'Catálogos activos para llenar selectores en las apps')]
class CatalogoController extends Controller
{
    #[OA\Get(path: '/api/catalogos', summary: 'Monedas, bancos, tipos de cuenta y marcas de red activos', tags: ['Catálogos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Catálogos'), new OA\Response(response: 401, description: 'No autenticado')])]
    public function __invoke()
    {
        $logo = fn (?string $path) => $path ? asset('storage/'.$path) : null;

        return response()->json([
            'monedas' => Moneda::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'simbolo']),
            'bancos' => Banco::where('activo', true)->orderBy('nombre')->get()->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre, 'logo_url' => $logo($b->logo)]),
            'tipos_cuenta' => TipoCuenta::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'descripcion']),
            'marcas_red' => MarcaRed::where('activo', true)->orderBy('nombre')->get()->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'color' => $m->color, 'logo_url' => $logo($m->logo)]),
        ]);
    }
}
