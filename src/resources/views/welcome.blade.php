<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Mis Gastos') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="text-center">
        <img src="{{ asset('images/logo.png') }}" alt="Mis Gastos" class="h-32 mx-auto mb-8">
        <p class="text-gray-600 mb-6">Controla tus finanzas personales</p>
        <div class="space-x-4">
            <a href="{{ route('login') }}" 
               class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                Iniciar Sesión
            </a>
            @if (Route::has('register'))
                <a href="{{ route('register') }}" 
                   class="inline-block px-6 py-3 border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                    Registrarse
                </a>
            @endif
        </div>
    </div>
</body>
</html>