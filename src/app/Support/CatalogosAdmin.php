<?php

namespace App\Support;

use App\Models\Banco;
use App\Models\Catalogos\ConceptoPlanilla;
use App\Models\Catalogos\TipoContacto;
use App\Models\Catalogos\TipoEmisor;
use App\Models\Catalogos\TipoIngreso;
use App\Models\Catalogos\TipoPrestamo;
use App\Models\Contacto;
use App\Models\FuenteIngreso;
use App\Models\Prestamo;

/**
 * Catálogos simples que el administrador mantiene con un solo CRUD genérico (Admin\CatalogoGenericoController).
 * Para agregar uno: su migración y modelo (extiende Catalogos\Catalogo), una entrada aquí y sus permisos.
 */
class CatalogosAdmin
{
    public const DEFINICIONES = [
        'tipos-contacto' => [
            'modelo' => TipoContacto::class, 'permiso' => 'tipos_contacto', 'titulo' => 'Tipos de contacto', 'singular' => 'tipo de contacto',
            'ejemplo' => 'Ej: Mascota', 'usos' => [Contacto::class => 'tipo_contacto_id'], 'campos' => [],
        ],
        'tipos-emisor' => [
            'modelo' => TipoEmisor::class, 'permiso' => 'tipos_emisor', 'titulo' => 'Tipos de emisor', 'singular' => 'tipo de emisor',
            'ejemplo' => 'Ej: Cooperativa', 'usos' => [Banco::class => 'tipo_emisor_id'], 'campos' => [],
        ],
        'tipos-prestamo' => [
            'modelo' => TipoPrestamo::class, 'permiso' => 'tipos_prestamo', 'titulo' => 'Tipos de préstamo', 'singular' => 'tipo de préstamo',
            'ejemplo' => 'Ej: Educativo', 'usos' => [Prestamo::class => 'tipo_prestamo_id'], 'campos' => [],
        ],
        'tipos-ingreso' => [
            'modelo' => TipoIngreso::class, 'permiso' => 'tipos_ingreso', 'titulo' => 'Tipos de ingreso', 'singular' => 'tipo de ingreso',
            'ejemplo' => 'Ej: Alquiler', 'usos' => [FuenteIngreso::class => 'tipo_ingreso_id'], 'campos' => [],
        ],
        'conceptos-planilla' => [
            'modelo' => ConceptoPlanilla::class, 'permiso' => 'conceptos_planilla', 'titulo' => 'Conceptos de planilla', 'singular' => 'concepto de planilla',
            'ejemplo' => 'Ej: ISSS', 'usos' => [],
            // Campos extra: [etiqueta, tipo de campo, reglas de validación, opciones]
            'campos' => [
                'tipo' => ['Tipo', 'select', ['required', 'in:descuento,adicion'], ['descuento' => 'Descuento', 'adicion' => 'Adición']],
                'porcentaje' => ['Porcentaje del bruto (opcional)', 'numero', ['nullable', 'numeric', 'min:0', 'max:100'], null],
                'tope' => ['Tope (opcional)', 'dinero', ['nullable', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'], null],
                'monto' => ['Monto fijo (opcional)', 'dinero', ['nullable', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,2})?$/'], null],
            ],
        ],
    ];

    public static function definicion(string $clave): array
    {
        return self::DEFINICIONES[$clave] ?? abort(404);
    }

    /** Cuántos registros usan el elemento (para no eliminarlo). */
    public static function usos(array $definicion, int $id): int
    {
        return collect($definicion['usos'])->sum(fn ($columna, $modelo) => $modelo::withoutGlobalScopes()->where($columna, $id)->count());
    }
}
