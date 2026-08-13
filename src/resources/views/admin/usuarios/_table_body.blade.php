<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
    @forelse($users as $user)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    <img src="{{ $user->avatar ? (filter_var($user->avatar, FILTER_VALIDATE_URL) ? $user->avatar : asset('storage/' . $user->avatar)) : 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=0a0a5e&color=fff' }}"
                        class="w-9 h-9 rounded-full object-cover border border-gray-200 dark:border-gray-600" alt="{{ $user->name }}">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</span>
                </div>
            </td>
            <td class="px-6 py-4">{{ $user->email }}</td>
            <td class="px-6 py-4">
                @foreach ($user->roles as $role)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200">
                        {{ $role->name }}
                    </span>
                @endforeach
            </td>
            <td class="px-6 py-4">
                @if ($user->email_verified_at)
                    <span class="inline-flex items-center text-green-600 dark:text-green-400">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                        Sí
                    </span>
                @else
                    <span class="inline-flex items-center text-red-600 dark:text-red-400">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        No
                    </span>
                @endif
            </td>
            <td class="px-6 py-4 sticky-col-right">
                <div class="flex justify-end gap-2">
                    @can('users.edit')
                        <x-secondary-button x-data="" type="button" class="py-1.5 px-2"
                            x-on:click.prevent="$dispatch('load-user-modal-form', '{{ route('admin.usuarios.edit', $user) }}?modal=1'); $dispatch('open-modal', 'user-modal')" title="Editar usuario">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </x-secondary-button>
                    @endcan
                    @can('users.delete')
                        @if ($user->id !== auth()->id())
                            <x-danger-button class="py-1.5 px-2" type="button"
                                x-on:click.prevent="deleteItem({{ $user->id }}, '{{ $user->name }}', '{{ route('admin.usuarios.destroy', $user) }}')" title="Eliminar usuario">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </x-danger-button>
                        @endif
                    @endcan
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">No se encontraron usuarios.</td>
        </tr>
    @endforelse
</tbody>