<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Regla de permisos: "ver" es la llave de cada módulo (da acceso a la pantalla y a su opción del menú).
 * Sin "ver", los demás permisos del módulo (crear, editar, eliminar, exportar…) no tienen efecto
 * y se descartan al guardar un rol.
 */
class Permisos
{
    public const MODULOS = [
        'profile' => 'Perfil',
        'users' => 'Usuarios',
        'roles' => 'Roles',
        'monedas' => 'Monedas',
        'bancos' => 'Bancos',
        'tipos_cuenta' => 'Tipos de cuenta',
        'marcas-red' => 'Marcas de red',
        'categorias' => 'Categorías',
        'efectivo' => 'Efectivo',
        'cuentas' => 'Cuentas y billeteras',
        'compromisos' => 'Compromisos',
        'tarjetas' => 'Tarjetas de crédito',
        'deudores' => 'Deudores',
        'hogar' => 'Hogar',
    ];

    public const ACCIONES = [
        'view' => 'Ver',
        'create' => 'Crear',
        'edit' => 'Editar',
        'update' => 'Editar',
        'delete' => 'Eliminar',
        'export' => 'Exportar',
        'avatar.update' => 'Cambiar foto',
        'password.update' => 'Cambiar contraseña',
    ];

    /** Quita los permisos de los módulos que no incluyen "ver". */
    public static function normalizar(array $permisos): array
    {
        $permisos = array_values(array_unique($permisos));

        return array_values(array_filter($permisos, function (string $permiso) use ($permisos) {
            [$modulo] = explode('.', $permiso, 2);

            return in_array("{$modulo}.view", $permisos, true);
        }));
    }

    /** Permisos agrupados por módulo, con "ver" primero, para el formulario de roles. */
    public static function agrupados(): Collection
    {
        $orden = array_flip(array_keys(self::ACCIONES));

        return Permission::all()
            ->groupBy(fn (Permission $p) => explode('.', $p->name, 2)[0])
            ->sortBy(fn ($items, string $modulo) => ($i = array_search($modulo, array_keys(self::MODULOS), true)) === false ? 999 : $i)
            ->map(fn (Collection $items) => $items->sortBy(fn (Permission $p) => $orden[self::accion($p->name)] ?? 99)->values());
    }

    public static function accion(string $permiso): string
    {
        return explode('.', $permiso, 2)[1] ?? '';
    }

    public static function etiquetaModulo(string $modulo): string
    {
        return self::MODULOS[$modulo] ?? ucfirst(str_replace(['_', '-'], ' ', $modulo));
    }

    public static function etiquetaAccion(string $permiso): string
    {
        $accion = self::accion($permiso);

        return self::ACCIONES[$accion] ?? ucfirst($accion);
    }
}
