<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

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

<body class="font-sans text-gray-900 antialiased bg-gray-100 dark:bg-gray-900">
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
        <div>
            <a href="/">
                <img src="{{ asset('images/logo-light.png') }}" alt="{{ config('app.name') }}"
                    class="h-20 w-auto dark:hidden">
                <img src="{{ asset('images/logo-dark.png') }}" alt="{{ config('app.name') }}"
                    class="h-20 w-auto hidden dark:block">
            </a>
        </div>
        <div
            class="w-full sm:max-w-md mt-6 px-6 py-6 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
            {{ $slot }}
        </div>
    </div>
</body>

</html>
