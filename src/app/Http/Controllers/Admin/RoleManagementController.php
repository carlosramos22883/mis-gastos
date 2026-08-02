<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Traits\Exportable;
use App\Exports\RolesExport;

class RoleManagementController extends Controller
{
    use Exportable;
    /**
     * Listar roles
     */
    public function index(Request $request)
    {
        $query = Role::with('permissions');

        // Búsqueda por nombre
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        // Filtro por módulo de permiso
        if ($request->filled('filter_permission')) {
            $module = $request->filter_permission;
            $query->whereHas('permissions', function ($q) use ($module) {
                $q->where('name', 'like', $module . '.%');
            });
        }

        // --- LÓGICA DE ORDENAMIENTO ---
        $sortField = $request->get('sort', 'name');
        $sortDirection = $request->get('direction', 'asc');

        // Solo permitimos ordenar por campos seguros de la tabla 'roles'
        $allowedSortFields = ['name', 'created_at', 'updated_at'];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc'); // Fallback por defecto
        }

        // Usamos el per_page del request (default 10) para que coincida con el selector
        $perPage = $request->get('per_page', 10);
        $roles = $query->paginate($perPage)->withQueryString();

        return view('admin.roles.index', compact('roles'));
    }

    /**
     * Mostrar formulario de creación
     */
    public function create()
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            // Agrupar permisos por módulo (ej: "users.view" -> "users")
            return explode('.', $permission->name)[0];
        });

        return view('admin.roles.create', compact('permissions'));
    }

    /**
     * Guardar nuevo rol
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,name'],
        ], [], [
            'name' => 'Nombre del rol',
            'permissions' => 'Permisos',
        ]);

        $role = Role::create(['name' => $validated['name']]);

        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return redirect()->route('admin.roles.index')
            ->with('success', "Rol '{$role->name}' creado exitosamente.");
    }

    /**
     * Mostrar formulario de edición
     */
    public function edit(Role $role)
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        $rolePermissions = $role->permissions->pluck('name')->toArray();

        return view('admin.roles.edit', compact('role', 'permissions', 'rolePermissions'));
    }

    /**
     * Actualizar rol
     */
    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,' . $role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,name'],
        ], [], [
            'name' => 'Nombre del rol',
            'permissions' => 'Permisos',
        ]);

        $role->update(['name' => $validated['name']]);

        $role->syncPermissions($validated['permissions'] ?? []);

        return redirect()->route('admin.roles.index')
            ->with('success', "Rol '{$role->name}' actualizado exitosamente.");
    }

    /**
     * Eliminar rol
     */
    public function destroy(Role $role)
    {
        // No permitir eliminar el rol Administrador
        if ($role->name === 'Administrador') {
            return back()->with('error', 'No puedes eliminar el rol de Administrador.');
        }

        $role->delete();

        return redirect()->route('admin.roles.index')
            ->with('success', 'Rol eliminado exitosamente.');
    }

    public function export(Request $request)
    {
        $query = Role::with('permissions');

        // APLICAR LOS MISMOS FILTROS QUE EN INDEX
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->filled('filter_permission')) {
            $module = $request->filter_permission;
            $query->whereHas('permissions', function ($q) use ($module) {
                $q->where('name', 'like', $module . '.%');
            });
        }

        // APLICAR EL MISMO ORDENAMIENTO QUE EN INDEX
        $sortField = $request->get('sort', 'name');
        $sortDirection = $request->get('direction', 'asc');

        $allowedSortFields = ['name', 'created_at', 'updated_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        $roles = $query->get(); // Obtenemos TODOS los resultados filtrados y ordenados

        return $this->handleExport(
            $request,
            $roles,
            RolesExport::class,
            'Listado de Roles y Permisos',
            [
                'id' => 'ID',
                'name' => 'Nombre del Rol',
                'permissions' => 'Permisos Asignados',
                'created_at' => 'Fecha de Creación'
            ],
            'roles'
        );
    }
}
