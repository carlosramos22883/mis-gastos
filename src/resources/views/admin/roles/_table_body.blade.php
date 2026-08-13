<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
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
                            onclick="confirmDelete({{ $role->id }}, '{{ $role->name }}')" title="Eliminar rol">
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
</tbody>
