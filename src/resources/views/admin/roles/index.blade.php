<x-app-layout>
    <x-slot name="header">
        <h1>{{ __('Gestión de Roles y Permisos') }}</h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @php
                $headers = [
                    // Agregamos 'key' y 'sortable' => true
                    ['label' => 'Nombre del Rol', 'key' => 'name', 'sortable' => true, 'width' => 'w-1/2'],
                    // Esta columna NO es ordenable (es una relación)
                    ['label' => 'Permisos Asignados', 'sortable' => false, 'width' => ''],
                    ['label' => 'Acciones', 'sortable' => false, 'width' => 'text-right'],
                ];
            @endphp

            <x-data-table :headers="$headers" :data="$roles" :createRoute="route('admin.roles.create')" createPermission="roles.create"
                createModal="role-modal" exportRoute="{{ route('admin.roles.export') }}" exportPermission="roles.view"
                searchPlaceholder="Buscar por nombre de rol..." defaultSort="name" defaultDirection="asc">

                <x-slot:filters>
                    <div class="w-full sm:w-48">
                        <x-floating-select id="filter_permission" name="filter_permission" label="Filtrar por permiso"
                            :options="[
                                'users' => 'Usuarios',
                                'roles' => 'Roles',
                                'profile' => 'Perfil',
                            ]" :value="request('filter_permission')"/>
                    </div>
                </x-slot:filters>

                @forelse($roles as $role)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                        <td class="px-6 py-4">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">
                                {{ $role->name }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($role->permissions as $permission)
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                        {{ $permission->name }}
                                    </span>
                                @endforeach
                                @if ($role->permissions->isEmpty())
                                    <span class="text-gray-500 dark:text-gray-400 text-sm">Sin permisos</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4 sticky-col-right">
                            <div class="flex justify-end gap-2">
                                @can('roles.edit')
                                    <x-secondary-button x-data="" type="button" class="py-1.5 px-2"
                                        x-on:click.prevent="$dispatch('load-role-modal-form', '{{ route('admin.roles.edit', $role) }}?modal=1'); $dispatch('open-modal', 'role-modal')"
                                        title="Editar rol">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </x-secondary-button>
                                @endcan

                                @can('roles.delete')
                                    <x-danger-button class="py-1.5 px-2" type="button"
                                        onclick="confirmDelete({{ $role->id }}, '{{ $role->name }}')"
                                        x-on:click.prevent="deleteItem({{ $role->id }}, '{{ $role->name }}', '{{ route('admin.roles.destroy', $role) }}')"
                                        title="Eliminar rol">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </x-danger-button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                            No se encontraron roles.
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            @if (session('success'))
                showAlert('success', '¡Éxito!', '{{ session('success') }}');
            @endif
            @if (session('error'))
                showAlert('error', 'Error', '{{ session('error') }}');
            @endif
        });
    </script>
    <!-- Modal de Rol -->
    <x-modal name="role-modal" :show="false">
        <div x-data="{ html: '', loading: false }" tabindex="-1"
            x-on:load-role-modal-form.window="
            loading = true;
            html = '';
            fetch($event.detail)
                .then(r => r.text())
                .then(h => { 
                    $refs.formContainer.innerHTML = h;
                    loading = false;
                    setTimeout(() => {
                        if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                            Alpine.initTree($refs.formContainer);
                        }
                    }, 50);
                })
                .catch(err => {
                    $refs.formContainer.innerHTML = '<p class=\'text-red-500 text-center p-4\'>Error al cargar el formulario</p>';
                    loading = false;
                });
        "
            x-on:close-modal.window="html = ''; loading = false; if($refs.formContainer) $refs.formContainer.innerHTML = '';"
            class="p-6 focus:outline-none">

            <div x-show="loading" class="flex justify-center items-center py-12">
                <svg class="animate-spin h-8 w-8 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
            </div>

            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
</x-app-layout>
