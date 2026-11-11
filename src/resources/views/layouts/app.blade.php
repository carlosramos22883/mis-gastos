<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-fondo dark:bg-gray-900 text-gray-900 dark:text-gray-100"
    x-data="{ sidebarOpen: false }">

    <!-- Sidebar: menú "off-canvas", oculto por defecto en TODOS los tamaños de pantalla -->
    @include('layouts.navigation')

    <!-- Overlay: aparece detrás del sidebar cuando está abierto, en cualquier tamaño de pantalla -->
    <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-300"
        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-linear duration-300" x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0" @click="sidebarOpen = false"
        class="fixed inset-0 bg-black bg-opacity-50 z-40" style="display: none;"></div>

    <!-- Contenedor Principal: ya no comparte fila con el sidebar, por eso ocupa 100% del ancho -->
    <div class="flex flex-col min-h-screen">

        <!-- Top Bar (Full Width) -->
        <header
            class="w-full flex items-center justify-between px-6 py-4 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-30">
            <div class="flex items-center gap-4">
                <!-- Botón Hamburguesa -->
                <button @click="sidebarOpen = true"
                    class="text-gray-500 dark:text-gray-400 focus:outline-none hover:text-gray-700 dark:hover:text-gray-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <!-- Logo junto al botón de menú -->
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <img src="{{ asset('images/logo-light.png') }}" alt="Logo" class="h-8 w-auto dark:hidden">
                    <img src="{{ asset('images/logo-dark.png') }}" alt="Logo" class="h-8 w-auto hidden dark:block">
                </a>
            </div>

            <!-- Botón de Modo Oscuro y Perfil -->
            <div class="flex items-center gap-4">
                <x-dark-mode-toggle size="md" alignment="center" />
                {{-- Campana: los avisos del inicio (vencidos, tope, presupuestos, tarjetas…) y las notificaciones guardadas.
                     El número cuenta solo lo que no se ha abierto; lo ya visto se queda en la lista, atenuado. --}}
                @php($avisos = Auth::user()->hogar ? app(\App\Services\DashboardService::class)->alertasCampana(Auth::user()) : [])
                @php($vistos = Auth::user()->avisos_vistos ?? [])
                @php($nuevos = collect($avisos)->reject(fn ($a) => in_array($a['clave'], $vistos, true))->count() + Auth::user()->unreadNotifications()->count())
                @php($puntos = ['critico' => 'bg-red-600', 'serio' => 'bg-amber-500', 'aviso' => 'bg-yellow-400', 'info' => 'bg-sky-500'])
                <x-dropdown align="top-end" width="w-80 sm:w-96" contentClasses="bg-white dark:bg-gray-800">
                    <x-slot name="trigger">
                        <button class="relative text-gray-500 dark:text-gray-300" aria-label="Avisos y notificaciones: {{ $nuevos }} sin ver">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 01-6 0" /></svg>
                            @if ($nuevos)
                                <span class="absolute -right-1 -top-1 rounded-full bg-red-600 px-1.5 text-[10px] text-white">{{ $nuevos }}</span>
                            @endif
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Avisos</span>
                            @if ($nuevos && $avisos)
                                <button type="button" class="text-xs text-primary-600 hover:underline dark:text-primary-400"
                                    x-on:click="fetch('{{ route('avisos.vistos') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }).then(() => window.location.reload())">Marcar todo como visto</button>
                            @endif
                        </div>
                        <div class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                            @foreach ($avisos as $a)
                                @php($visto = in_array($a['clave'], $vistos, true))
                                <a href="{{ route('avisos.abrir', $a['clave']) }}" class="flex items-start gap-3 px-4 py-3 text-xs leading-5 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ $visto ? 'text-gray-500 dark:text-gray-400' : 'bg-primary-50/60 text-gray-800 dark:bg-gray-700/40 dark:text-gray-100' }}">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $visto ? 'border border-gray-300 dark:border-gray-500' : ($puntos[$a['nivel']] ?? 'bg-gray-400') }}" aria-hidden="true"></span>
                                    <span class="min-w-0">
                                        <span class="block font-semibold {{ $visto ? '' : 'text-gray-900 dark:text-white' }}">{{ $a['etiqueta'] }}@unless ($visto)<span class="sr-only"> (sin ver)</span>@endunless</span>
                                        <span class="block">{{ $a['texto'] }}</span>
                                    </span>
                                </a>
                            @endforeach
                            @foreach (Auth::user()->notifications()->latest()->limit(5)->get() as $notification)
                                <a href="{{ route('notifications.index') }}" class="block px-4 py-3 text-xs leading-5 hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ $notification->read_at ? 'text-gray-500 dark:text-gray-400' : 'font-medium text-gray-800 dark:text-gray-100' }}">{{ $notification->data['message'] ?? 'Notificación' }}</a>
                            @endforeach
                            @if (! $avisos && ! Auth::user()->notifications()->exists())
                                <p class="px-4 py-6 text-center text-xs text-gray-500">No tienes avisos ni notificaciones.</p>
                            @endif
                        </div>
                        <p class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-400 dark:border-gray-700">Los avisos se quitan solos cuando ya no aplican: al pagar lo vencido o al empezar el ciclo siguiente.</p>
                        @if (Auth::user()->notifications()->exists())
                            <x-dropdown-link :href="route('notifications.index')">Ver todas las notificaciones</x-dropdown-link>
                        @endif
                    </x-slot>
                </x-dropdown>

                <!-- Dropdown de Usuario -->
                <div class="relative">
                    <x-dropdown align="top-end" width="48">
                        <x-slot name="trigger">
                            <button
                                class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none">
                                <img src="{{ Auth::user()->avatar ? (filter_var(Auth::user()->avatar, FILTER_VALIDATE_URL) ? Auth::user()->avatar : asset('storage/' . Auth::user()->avatar)) : 'https://ui-avatars.com/api/?name=' . urlencode(Auth::user()->name) . '&background=0a0a5e&color=fff' }}"
                                    alt="Avatar"
                                    class="w-8 h-8 rounded-full object-cover border border-gray-300 dark:border-gray-600 user-avatar">
                                <span class="hidden md:block">{{ Str::limit(Auth::user()->name, 20) }}</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            @can('profile.view')
                                <x-dropdown-link :href="route('profile.edit')" class="dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ __('Perfil') }}
                                </x-dropdown-link>
                            @endcan
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();"
                                    class="dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ __('Cerrar Sesión') }}
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>
        </header>

        <!-- Área de Contenido -->
        {{-- pb-28: el footer es fijo; se deja su alto (~4rem) más un respiro para que no tape el final de la página --}}
        <main class="flex-1 overflow-x-hidden bg-fondo dark:bg-gray-900 px-6 pt-6 pb-28">
            @isset($header)
                <div class="mb-3 px-6 max-w-7xl mx-auto">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $header }}</h1>
                </div>
            @endisset

            {{ $slot }}
        </main>

        <!-- Footer (fijo, ancho completo) -->
        <x-app-footer />
    </div>

    <!-- Scripts de Alertas -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            @if (session('useLocalStorage'))
                @if (session('success'))
                    localStorage.setItem('alertMessage', '{{ session('success') }}');
                    localStorage.setItem('alertTitle', '¡Éxito!');
                    localStorage.setItem('alertIcon', 'success');
                @endif
                @if (session('info'))
                    localStorage.setItem('alertMessage', '{{ session('info') }}');
                    localStorage.setItem('alertTitle', 'Información');
                    localStorage.setItem('alertIcon', 'info');
                @endif
            @endif

            const alertMessage = localStorage.getItem('alertMessage');
            const alertTitle = localStorage.getItem('alertTitle');
            const alertIcon = localStorage.getItem('alertIcon');

            if (alertMessage) {
                showAlert(alertIcon || 'info', alertTitle || 'Información', alertMessage);
                localStorage.removeItem('alertMessage');
                localStorage.removeItem('alertTitle');
                localStorage.removeItem('alertIcon');
            }

            @if (session('success') && !session('useLocalStorage'))
                showAlert('success', '¡Éxito!', '{{ session('success') }}');
            @endif
        });
    </script>
</body>

</html>
