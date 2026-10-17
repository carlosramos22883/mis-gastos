@props(['name', 'maxWidth' => '2xl'])
{{-- Modal que carga su formulario por AJAX al recibir el evento "load-{name}-modal-form" con la URL --}}
<x-modal :name="$name.'-modal'" :show="false" :maxWidth="$maxWidth">
    <div x-data="{ loading: false }"
        x-on:load-{{ $name }}-modal-form.window="loading = true; $refs.formContainer.innerHTML = ''; fetch($event.detail, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => { if (!r.ok) throw r; return r.text(); }).then(html => { $refs.formContainer.innerHTML = html; loading = false; Alpine.initTree($refs.formContainer); }).catch(() => { $refs.formContainer.innerHTML = '<p class=\'p-6 text-center text-red-600\'>No se pudo cargar el formulario.</p>'; loading = false; })"
        x-on:close-modal.window="if ($event.detail === '{{ $name }}-modal') $refs.formContainer.innerHTML = ''"
        class="p-6">
        <div x-show="loading" class="py-10 text-center text-gray-500 dark:text-gray-400">Cargando...</div>
        <div x-show="!loading" x-ref="formContainer"></div>
    </div>
</x-modal>
