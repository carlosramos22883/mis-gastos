<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banco;
use App\Models\Catalogos\Catalogo;
use App\Models\Catalogos\ConceptoPlanilla;
use App\Models\Catalogos\TipoContacto;
use App\Models\Catalogos\TipoEmisor;
use App\Models\Catalogos\TipoIngreso;
use App\Models\Catalogos\TipoPrestamo;
use App\Models\MarcaRed;
use App\Models\Moneda;
use App\Models\TipoCuenta;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Catálogos', description: 'Catálogos activos para llenar selectores en las apps')]
class CatalogoController extends Controller
{
    #[OA\Get(path: '/api/catalogos', summary: 'Monedas, bancos y emisores, tipos (cuenta, contacto, emisor, préstamo, ingreso), marcas de red y conceptos de planilla activos', tags: ['Catálogos'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Catálogos'), new OA\Response(response: 401, description: 'No autenticado')])]
    public function __invoke()
    {
        $logo = fn (?string $path) => $path ? asset('storage/'.$path) : null;

        return response()->json([
            'monedas' => Moneda::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'simbolo']),
            'bancos' => Banco::where('activo', true)->orderBy('nombre')->get()->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre, 'tipo_emisor_id' => $b->tipo_emisor_id, 'logo_url' => $logo($b->logo)]),
            'tipos_cuenta' => TipoCuenta::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'descripcion']),
            'tipos_contacto' => $this->simple(TipoContacto::class),
            'tipos_emisor' => $this->simple(TipoEmisor::class),
            'tipos_prestamo' => $this->simple(TipoPrestamo::class),
            'tipos_ingreso' => $this->simple(TipoIngreso::class),
            'conceptos_planilla' => ConceptoPlanilla::where('activo', true)->ordenados()->get(['id', 'nombre', 'tipo', 'porcentaje', 'tope', 'monto', 'descripcion']),
            'marcas_red' => MarcaRed::where('activo', true)->orderBy('nombre')->get()->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'color' => $m->color, 'logo_url' => $logo($m->logo)]),
        ]);
    }

    /** @param class-string<Catalogo> $modelo */
    private function simple(string $modelo)
    {
        return $modelo::where('activo', true)->ordenados()->get(['id', 'nombre', 'descripcion']);
    }
}
