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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
        $sortField = $request->get('sort', 'name'); // <-- Cambiado de 'created_at' a 'name'
        $sortDirection = $request->get('direction', 'asc'); // <-- Cambiado de 'desc' a 'asc'

        // Validar campos permitidos para ordenar (seguridad)
        $allowedSortFields = ['name', 'email', 'created_at', 'email_verified_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc'); // Fallback seguro
        }

        $perPage = $request->get('per_page', 10);
        $users = $query->paginate($perPage)->withQueryString();
        $roles = Role::all();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view('admin.usuarios._table_body', compact('users'))->render(),
                'pagination' => $users->links()->render(),
            ]);
        }

        return view('admin.usuarios.index', compact('users', 'roles'));
    }

    /**
     * Mostrar formulario de creación
     */
    public function create()
    {
        $roles = Role::all();
        if (request()->has('modal')) {
            return view('admin.usuarios._form', compact('roles'));
        }
        return view('admin.usuarios.create', compact('roles'));
    }

    /**
     * Mostrar formulario de edición
     */
    public function edit(User $usuario)
    {
        $roles = Role::all();
        if (request()->has('modal')) {
            return view('admin.usuarios._form', compact('usuario', 'roles'));
        }
        return view('admin.usuarios.edit', compact('usuario', 'roles'));
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
            // NO marcar como verificado - dejar que el usuario lo haga
            // email_verified_at => null, // Esto es automático si no lo pones
        ]);

        $user->assignRole($validated['role']);

        // Enviar correo de verificación
        $user->sendEmailVerificationNotification();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Usuario '{$user->name}' creado exitosamente. Se ha enviado un correo de verificación.",
                'user' => $user
            ], 200);
        }

        return redirect()->route('admin.usuarios.index')
            ->with('success', "Usuario '{$user->name}' creado exitosamente. Se ha enviado un correo de verificación.");
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

        if ($usuario->isDirty('email')) {
            $usuario->email_verified_at = null;
            // Enviar nuevo correo de verificación
            $usuario->sendEmailVerificationNotification();
        }

        if (!empty($validated['password'])) {
            $usuario->password = Hash::make($validated['password']);
        }

        $usuario->save();
        $usuario->syncRoles([$validated['role']]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Usuario '{$usuario->name}' actualizado exitosamente. Se ha enviado un nuevo correo de verificación.",
                'user' => $usuario
            ], 200);
        }

        return redirect()->route('admin.usuarios.index')
            ->with('success', "Usuario '{$usuario->name}' actualizado exitosamente. Se ha enviado un nuevo correo de verificación.");
    }

    /**
     * Eliminar usuario
     */
    public function destroy(User $usuario)
    {
        if ($usuario->id === auth()->id()) {
            if (request()->wantsJson()) {
                return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 403);
            }
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        // Obtener la página actual antes de eliminar
        $currentPage = request('page', 1);

        $usuario->delete();

        if (request()->wantsJson()) {

            // Verificar si la página actual sigue siendo válida
            $perPage = request('per_page', 10);
            $totalRecords = User::count(); // O User::count() según el controller
            $lastPage = ceil($totalRecords / $perPage);

            // Si la página actual es mayor que la última página válida
            if ($currentPage > $lastPage && $lastPage > 0) {
                // Redirigir a la última página válida
                return response()->json([
                    'message' => 'Usuario eliminado exitosamente.',
                    'redirect_to_page' => $lastPage
                ], 200);
            }

            return response()->json(['message' => 'Usuario eliminado exitosamente.'], 200);
        }

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
