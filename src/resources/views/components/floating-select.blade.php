@props([
    'id', 
    'label', 
    'name' => null, 
    'options' => [], 
    'value' => '', 
    'error' => null,
    'searchable' => true,
    'placeholder' => 'Seleccione...'
])

@php
    $name = $name ?? $id;
    $selectedValue = old($name, $value);
    
    if ($options instanceof \Illuminate\Support\Collection) {
        $options = $options->toArray();
    }
@endphp

<div 
    x-data="{ 
        selectedValue: '{{ $selectedValue }}',
        isFocused: false,
        tomSelectInstance: null,
        initTomSelect() {
            if (!@json((bool)$searchable)) return;
            
            this.$nextTick(() => {
                if (typeof TomSelect !== 'undefined' && !this.tomSelectInstance) {
                    this.tomSelectInstance = new TomSelect(this.$refs.selectInput, {
                        create: false,
                        allowEmptyOption: true,
                        placeholder: '{{ $placeholder }}',
                        plugins: ['dropdown_input'],
                        onFocus: () => { this.isFocused = true; },
                        onBlur: () => { this.isFocused = false; },
                        onChange: (val) => { 
                            this.selectedValue = val; 
                        }
                    });
                }
            });
        }
    }" 
    x-init="initTomSelect()"
    class="relative w-full"
>
    <div class="relative w-full">
        <select 
            x-ref="selectInput"
            id="{{ $id }}"
            name="{{ $name }}"
            x-model="selectedValue"
            @focus="isFocused = true"
            @blur="isFocused = false"
            {{ $attributes->merge([
                'class' => 'block w-full px-4 py-3 text-xs text-gray-900 dark:text-white bg-transparent border border-gray-300 dark:border-gray-600 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 peer dark:bg-gray-700'
            ]) }}
            style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.75rem center; background-repeat: no-repeat; background-size: 1.25em 1.25em;"
        >
            <option value="">{{ $placeholder }}</option>
            @foreach($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" {{ $selectedValue == $optionValue ? 'selected' : '' }}>
                    {{ $optionLabel }}
                </option>
            @endforeach
        </select>

        <!-- Label Flotante -->
        <label 
            for="{{ $id }}"
            :class="(selectedValue !== '' && selectedValue !== null && selectedValue !== undefined || isFocused) 
                ? '-translate-y-4 scale-75 top-2 text-xs text-primary-600 dark:text-primary-400 bg-white dark:bg-gray-800 px-2 rounded left-2' 
                : 'translate-y-0 scale-100 top-3 text-sm text-gray-500 dark:text-gray-400 bg-transparent px-0 left-4'"
            class="absolute z-20 origin-[0] transform transition-all duration-200 pointer-events-none"
        >
            {{ $label }}
        </label>
    </div>

    @if($error)
        <p class="mt-1 text-xs flex items-center gap-1 text-red-500">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            {{ $error }}
        </p>
    @endif
</div>