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

<body class="font-sans antialiased bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100"
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

                <!-- Dropdown de Usuario -->
                <div class="relative">
                    <x-dropdown align="top-end" width="48">
                        <x-slot name="trigger">
                            <button
                                class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 focus:outline-none">
                                <img src="{{ Auth::user()->avatar ? (filter_var(Auth::user()->avatar, FILTER_VALIDATE_URL) ? Auth::user()->avatar : asset('storage/' . Auth::user()->avatar)) : 'https://ui-avatars.com/api/?name=' . urlencode(Auth::user()->name) . '&background=0a0a5e&color=fff' }}"
                                    alt="Avatar"
                                    class="w-8 h-8 rounded-full object-cover border border-gray-300 dark:border-gray-600">
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
        <main class="flex-1 overflow-x-hidden bg-gray-50 dark:bg-gray-900 p-6">
            @isset($header)
                <div class="mb-3 px-6 max-w-7xl mx-auto">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $header }}</h1>
                </div>
            @endisset

            {{ $slot }}
        </main>

        <!-- Footer (Full Width) -->
        <div class="w-full mt-8">
            <x-app-footer />
        </div>
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
