{{-- Modales de compromiso y de pago con efectivo; ambos cargan su formulario por AJAX --}}
@foreach (['compromiso' => '', 'efectivo' => ''] as $modal => $_)
    <x-modal name="{{ $modal }}-modal" :show="false">
        <div x-data="{ loading: false }"
            x-on:load-{{ $modal }}-modal-form.window="loading = true; $refs.formContainer.innerHTML = ''; fetch($event.detail).then(r => r.text()).then(html => { $refs.formContainer.innerHTML = html; loading = false; Alpine.initTree($refs.formContainer); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-6 text-center text-red-600\'>No se pudo cargar el formulario.</p>'; loading = false; })"
            x-on:close-modal.window="if ($event.detail === '{{ $modal }}-modal') $refs.formContainer.innerHTML = ''"
            class="p-6">
            <div x-show="loading" class="py-10 text-center">Cargando...</div>
            <div x-show="!loading" x-ref="formContainer"></div>
        </div>
    </x-modal>
@endforeach
