<?php

namespace App\Http\Controllers\Admin;

use App\Exports\RolesExport;
use App\Http\Controllers\Concerns\HandlesTableNavigation;
use App\Http\Controllers\Controller;
use App\Traits\Exportable;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    use Exportable;
    use HandlesTableNavigation;

    /**
     * Listar roles
     */
    public function index(Request $request)
    {
        $this->tableRequest($request);
        $query = Role::with('permissions');

        // Búsqueda por nombre
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        // Filtro por módulo de permiso
        if ($request->filled('filter_permission')) {
            $module = $request->filter_permission;
            $query->whereHas('permissions', function ($q) use ($module) {
                $q->where('name', 'like', $module.'.%');
            });
        }

        // Ordenamiento
        $sortField = $request->get('sort', 'name'); // <-- Cambiado de 'created_at' a 'name'
        $sortDirection = $request->get('direction', 'asc'); // <-- Cambiado de 'desc' a 'asc'

        // Validar campos permitidos para ordenar (seguridad)
        $allowedSortFields = ['name', 'email', 'created_at', 'email_verified_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc'); // Fallback seguro
        }

        // Usamos el per_page del request (default 10) para que coincida con el selector
        $perPage = $request->get('per_page', 10);
        $roles = $query->paginate($perPage)->withQueryString();

        // Si es una petición AJAX (desde la web), devolvemos solo el HTML de la tabla
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.roles._table_body', compact('roles'))->render(),
                'pagination' => $roles->links()->render(),
            ]);
        }

        // Si es una visita normal, devuelve la vista completa
        return view('admin.roles.index', compact('roles'));
    }

    /**
     * Mostrar formulario de creación
     */
    public function create()
    {
        $permissions = Permission::all()->groupBy(fn ($p) => explode('.', $p->name)[0]);
        if (request()->has('modal')) {
            return view('admin.roles._form', compact('permissions'));
        }

        return view('admin.roles.create', compact('permissions'));
    }

    /**
     * Mostrar formulario de edición
     */
    public function edit(Role $role)
    {
        $permissions = Permission::all()->groupBy(fn ($p) => explode('.', $p->name)[0]);
        if (request()->has('modal')) {
            return view('admin.roles._form', compact('role', 'permissions'));
        }

        return view('admin.roles.edit', compact('role', 'permissions'));
    }

    /**
     * Guardar nuevo rol
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['exists:permissions,name'],
        ], [
            // Mensajes de error personalizados
            'permissions.required' => 'Debes seleccionar al menos un permiso para el rol.',
            'permissions.min' => 'Debes seleccionar al menos un permiso para el rol.',
        ], [
            // Nombres de atributos para los mensajes
            'name' => 'Nombre del rol',
            'permissions' => 'Permisos',
        ]);

        $role = Role::create(['name' => $validated['name']]);
        $role->syncPermissions($this->normalizePermissions($validated['permissions']));

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Rol '{$role->name}' creado exitosamente.",
                'role' => $role,
                ...$this->tableNavigation($this->roleTableQuery($request), $request, $role->id),
            ], 200);
        }

        return redirect()->route('admin.roles.index')
            ->with('success', "Rol '{$role->name}' creado exitosamente.");
    }

    /**
     * Actualizar rol
     */
    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['exists:permissions,name'],
        ], [
            'permissions.required' => 'Debes seleccionar al menos un permiso para el rol.',
            'permissions.min' => 'Debes seleccionar al menos un permiso para el rol.',
        ], [
            'name' => 'Nombre del rol',
            'permissions' => 'Permisos',
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($this->normalizePermissions($validated['permissions']));

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Rol '{$role->name}' actualizado exitosamente.",
                ...$this->tableNavigation($this->roleTableQuery($request), $request, $role->id),
            ], 200);
        }

        return redirect()->route('admin.roles.index')
            ->with('success', "Rol '{$role->name}' actualizado exitosamente.");
    }

    /**
     * Eliminar rol
     */
    public function destroy(Role $role)
    {
        // No permitir eliminar el rol Administrador
        if ($role->name === 'Administrador' || $role->name === 'Usuario') {
            if (request()->wantsJson()) {
                return response()->json(['message' => $role->name === 'Administrador' ? 'No puedes eliminar el rol Administrador.' : 'No puedes eliminar el rol Usuario'], 403);
            }

            return back()->with('error', $role->name === 'Administrador' ? 'No puedes eliminar el rol Administrador.' : 'No puedes eliminar el rol Usuario');
        }

        // Verificar si el rol tiene usuarios asignados
        if ($role->users()->count() > 0) {
            $usersCount = $role->users()->count();

            if (request()->wantsJson()) {
                return response()->json([
                    'message' => "No se puede eliminar el rol '{$role->name}'. Tiene {$usersCount} usuario(s) asignado(s).",
                ], 422);
            }

            return back()->with('error', "No se puede eliminar el rol '{$role->name}'. Tiene {$usersCount} usuario(s) asignado(s).");
        }

        // Obtener la página actual antes de eliminar
        $currentPage = request('page', 1);

        $role->delete();

        if (request()->wantsJson()) {

            // Verificar si la página actual sigue siendo válida
            $perPage = request('per_page', 10);
            $totalRecords = Role::count(); // O User::count() según el controller
            $lastPage = ceil($totalRecords / $perPage);

            // Si la página actual es mayor que la última página válida
            if ($currentPage > $lastPage && $lastPage > 0) {
                // Redirigir a la última página válida
                return response()->json([
                    'message' => 'Rol eliminado exitosamente.',
                    'redirect_to_page' => $lastPage,
                ], 200);
            }

            return response()->json(['message' => 'Rol eliminado exitosamente.'], 200);
        }

        return redirect()->route('admin.roles.index')
            ->with('success', 'Rol eliminado exitosamente.');
    }

    private function normalizePermissions(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        $modules = [];

        foreach ($permissions as $permission) {
            [$module] = explode('.', $permission, 2);
            $modules[$module] = true;
        }

        foreach (array_keys($modules) as $module) {
            if (! in_array("{$module}.view", $permissions, true)) {
                $permissions = array_values(array_filter(
                    $permissions,
                    fn (string $permission): bool => ! str_starts_with($permission, "{$module}.")
                ));
            }
        }

        return $permissions;
    }

    private function roleTableQuery(Request $request)
    {
        $this->tableRequest($request);
        $query = Role::with('permissions');
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($request->filled('filter_permission')) {
            $module = $request->filter_permission;
            $query->whereHas('permissions', fn ($q) => $q->where('name', 'like', $module.'.%'));
        }

        return $query->orderBy($request->get('sort', 'name'), $request->get('direction', 'asc'));
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
                $q->where('name', 'like', $module.'.%');
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
                'created_at' => 'Fecha de Creación',
            ],
            'roles'
        );
    }
}
