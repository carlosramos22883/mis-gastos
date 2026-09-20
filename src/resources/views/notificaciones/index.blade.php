<x-app-layout>
    <x-slot name="header">Notificaciones</x-slot>
    <div class="mx-auto max-w-4xl space-y-3">
        @forelse($notificaciones as $notificacion)
            <a href="{{ isset($notificacion->data['compromiso_id']) ? route('compromisos.show', $notificacion->data['compromiso_id']) : '#' }}" class="block rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                <strong>{{ $notificacion->data['title'] ?? 'Notificación' }}</strong>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $notificacion->data['message'] ?? '' }}</p>
                <time class="text-xs text-gray-400">{{ $notificacion->created_at->format('d/m/Y H:i') }}</time>
            </a>
        @empty
            <p class="rounded-lg bg-white p-6 text-center text-gray-500 shadow dark:bg-gray-800">No tienes notificaciones.</p>
        @endforelse
        {{ $notificaciones->links() }}
    </div>
</x-app-layout>
