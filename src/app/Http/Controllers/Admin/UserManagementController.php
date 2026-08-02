<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use App\Exports\UsersExport;
use App\Traits\Exportable;

class UserManagementController extends Controller
{
    use Exportable;
    /**
     * Listar usuarios (con búsqueda y paginación)
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Búsqueda por nombre o email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtro por rol
        if ($request->filled('filter_role')) {
            $query->role($request->filter_role);
        }

        // Ordenamiento
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');

        // Validar campos permitidos para ordenar (seguridad)
        $allowedSortFields = ['name', 'email', 'created_at', 'email_verified_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = $request->get('per_page', 10);
        $users = $query->paginate($perPage)->withQueryString();
        $roles = Role::all();

        return view('admin.usuarios.index', compact('users', 'roles'));
    }

    /**
     * Mostrar formulario de creación
     */
    public function create()
    {
        $roles = Role::all();
        return view('admin.usuarios.create', compact('roles'));
    }

    /**
     * Guardar nuevo usuario
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'role' => ['required', 'exists:roles,name'],
        ], [], [
            'name' => 'Nombre',
            'email' => 'Correo electrónico',
            'password' => 'Contraseña',
            'role' => 'Rol',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(), // El admin crea usuarios ya verificados
        ]);

        $user->assignRole($validated['role']);

        return redirect()->route('admin.usuarios.index')
            ->with('success', "Usuario '{$user->name}' creado exitosamente.");
    }

    /**
     * Mostrar formulario de edición
     */
    public function edit(User $usuario)
    {
        $roles = Role::all();
        $userRole = $usuario->roles->first()?->name;
        return view('admin.usuarios.edit', compact('usuario', 'roles', 'userRole'));
    }

    /**
     * Actualizar usuario
     */
    public function update(Request $request, User $usuario)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $usuario->id],
            'password' => [
                'nullable',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'role' => ['required', 'exists:roles,name'],
        ], [], [
            'name' => 'Nombre',
            'email' => 'Correo electrónico',
            'password' => 'Contraseña',
            'role' => 'Rol',
        ]);

        $usuario->name = $validated['name'];
        $usuario->email = $validated['email'];

        // Si cambia el email, deshabilitar verificación hasta que confirme el nuevo
        if ($usuario->isDirty('email')) {
            $usuario->email_verified_at = null;
        }

        // Solo actualizar contraseña si se proporcionó una nueva
        if (!empty($validated['password'])) {
            $usuario->password = Hash::make($validated['password']);
        }

        $usuario->save();

        // Actualizar rol (sync reemplaza el rol anterior)
        $usuario->syncRoles([$validated['role']]);

        return redirect()->route('admin.usuarios.index')
            ->with('success', "Usuario '{$usuario->name}' actualizado exitosamente.");
    }

    /**
     * Eliminar usuario
     */
    public function destroy(User $usuario)
    {
        // Seguridad: No permitir que el usuario se elimine a sí mismo
        if ($usuario->id === auth()->id()) {
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        $usuario->delete();

        return redirect()->route('admin.usuarios.index')
            ->with('success', 'Usuario eliminado exitosamente.');
    }

    /**
     * Exportar usuarios (CSV, Excel o PDF) usando el Trait estandarizado
     */
    public function export(Request $request)
    {
        $query = \App\Models\User::with('roles');

        // APLICAR LOS MISMOS FILTROS QUE EN INDEX
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('filter_role')) {
            $query->role($request->filter_role);
        }

        // APLICAR EL MISMO ORDENAMIENTO QUE EN INDEX
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');

        $allowedSortFields = ['name', 'email', 'created_at', 'email_verified_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $users = $query->get(); // Obtenemos TODOS los resultados filtrados y ordenados

        return $this->handleExport(
            $request,
            $users,
            UsersExport::class,
            'Listado de Usuarios del Sistema',
            [
                'id' => 'ID',
                'name' => 'Nombre',
                'email' => 'Correo',
                'roles' => 'Rol',
                'email_verified_at' => 'Verificado',
                'created_at' => 'Creado'
            ],
            'usuarios'
        );
    }
}
