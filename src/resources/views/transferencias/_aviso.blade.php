{{--
    En el formulario de un movimiento nuevo: si en realidad es mover dinero propio (retirar del banco, depositar
    efectivo, pasar entre cuentas), lo correcto es una transferencia, que registra la salida y la entrada juntas.
    Uso: @include('transferencias._aviso', ['modal' => 'movimiento-modal', 'origen' => 'cuenta:42', 'destino' => 'efectivo'])
--}}
@can('cuentas.create')
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900 ring-1 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-100 dark:ring-sky-800">
        <span>¿Sacaste del banco, depositaste efectivo o pasaste entre cuentas? Eso es una <strong>transferencia</strong>: registra la salida y la entrada juntas.</span>
        <button type="button" class="shrink-0 font-semibold text-sky-700 underline hover:no-underline dark:text-sky-300"
            {{-- A la ventana y no con $dispatch: al cerrar este modal se borra su contenido (este botón incluido) y sus eventos ya no llegarían --}}
            x-on:click="const enviar = (n, d) => window.dispatchEvent(new CustomEvent(n, { detail: d })); enviar('close-modal', '{{ $modal }}'); enviar('load-transferencia-modal-form', '{{ route('transferencias.create', array_filter(['origen' => $origen ?? null, 'destino' => $destino ?? null])) }}'); enviar('open-modal', 'transferencia-modal')">Hacer una transferencia</button>
    </div>
@endcan
