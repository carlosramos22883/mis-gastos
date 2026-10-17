@php
    $clases = [
        'pendiente' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
        'parcial' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'vencido' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        'antiguo' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
        'pagado' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        'cancelado' => 'bg-gray-100 text-gray-600 line-through dark:bg-gray-700 dark:text-gray-400',
    ];
@endphp
<span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $clases[$estado] ?? $clases['pendiente'] }}">{{ \App\Models\Compromiso::ESTADOS_VISUALES[$estado] ?? ucfirst($estado) }}</span>
