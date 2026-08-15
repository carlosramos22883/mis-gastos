<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }}</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/icon.png') }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 min-h-screen flex flex-col">
    @include('layouts.navigation')

    @isset($header)
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                {{ $header }}
            </div>
        </header>
    @endisset

    <!-- Agregamos pb-20 (80px) para que el footer no tape el último contenido -->
    <main class="flex-1 w-full pb-20">
        {{ $slot }}
    </main>

    <x-app-footer />

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            @if (session('useLocalStorage'))
                // Guardar en localStorage para que sobreviva la redirección
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

            // Mostrar alertas de localStorage
            const alertMessage = localStorage.getItem('alertMessage');
            const alertTitle = localStorage.getItem('alertTitle');
            const alertIcon = localStorage.getItem('alertIcon');

            if (alertMessage) {
                showAlert(alertIcon || 'info', alertTitle || 'Información', alertMessage);
                localStorage.removeItem('alertMessage');
                localStorage.removeItem('alertTitle');
                localStorage.removeItem('alertIcon');
            }

            // También mostrar flash messages tradicionales
            @if (session('success') && !session('useLocalStorage'))
                showAlert('success', '¡Éxito!', '{{ session('success') }}');
            @endif
        });
    </script>
</body>

</html>
