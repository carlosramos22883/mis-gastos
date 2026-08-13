@props([
    'id',
    'label',
    'name' => null,
    'value' => null,
    'options' => [],
    'searchable' => false,
    'multiple' => false,
    'allowEmpty' => true,
    'error' => null,
    'required' => false,
    'submitForm' => false,
])

@php
    $name = $name ?? $id;
    // Manejo de valores simples y múltiples (arrays)
    $selectedValue = old($name, $value);
    if ($multiple && !is_array($selectedValue)) {
        $selectedValue = array_filter(explode(',', (string) $selectedValue));
    }
    $hasValue = $multiple ? !empty($selectedValue) : $selectedValue !== null && $selectedValue !== '';

    // Plugins de Tom Select según el modo del select
    $plugins = [];
    if ($multiple) {
        $plugins[] = 'remove_button';
    }
    if ($searchable) {
        $plugins[] = 'dropdown_input';
    }
@endphp

<div x-data="{
    selectedValue: {{ json_encode($selectedValue) }},
    isFocused: false,
    isMultiple: {{ $multiple ? 'true' : 'false' }},
    get hasValue() {
        if (this.isMultiple) {
            return Array.isArray(this.selectedValue) && this.selectedValue.length > 0;
        }
        return this.selectedValue !== null && this.selectedValue !== '' && this.selectedValue !== undefined;
    }
}" class="relative w-full">
    <div class="relative w-full flex items-center">
        {{-- SIEMPRE Tom Select, buscable o no --}}
        <div class="w-full" wire:ignore x-init="const tom = new TomSelect($refs.select, {
            create: false,
            maxItems: {{ $multiple ? 'null' : '1' }},
            allowEmptyOption: {{ $allowEmpty ? 'true' : 'false' }},
            placeholder: '',
            dropdownParent: 'body',
            plugins: {{ json_encode($plugins) }},
            @if (!$searchable) controlInput: null, @endif
            @if (!$allowEmpty) onDelete: () => false, @endif
            onFocus: () => { isFocused = true },
            onBlur: () => { isFocused = false },
            onChange: (val) => {
                selectedValue = val;
                @if($submitForm)
                $el.closest('form').requestSubmit();
                @else
                $dispatch('change', val);
                @endif
            }
        });">
            <select id="{{ $id }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" x-ref="select"
                {{ $multiple ? 'multiple' : '' }} {{ $required ? 'required' : '' }}
                {{ $attributes->merge([
                    'class' =>
                        'block w-full text-xs text-gray-900 dark:text-white bg-white dark:bg-[#323d4d] border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500',
                ]) }}>
                @if ($allowEmpty && !$multiple)
                    <option value=""></option>
                @endif

                @foreach ($options as $val => $optLabel)
                    @php
                        $isSelected = $multiple
                            ? is_array($selectedValue) && in_array($val, $selectedValue)
                            : (string) $selectedValue === (string) $val;
                    @endphp
                    <option value="{{ $val }}" {{ $isSelected ? 'selected' : '' }}>
                        {{ $optLabel }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Label Flotante Dinámico -->
        <label for="{{ $id }}"
            :class="{
                'absolute pointer-events-none transition-all duration-200 left-2.5 px-1 z-20': true,
                '-top-2.5 text-xs font-normal bg-white dark:bg-[#323d4d] text-gray-700 dark:text-gray-300': isFocused ||
                    hasValue,
                'top-2.5 text-xs text-gray-500 dark:text-gray-400 bg-transparent': !isFocused && !hasValue,
                'text-primary-600 dark:text-primary-400': isFocused
            }">
            {{ $label }}
        </label>
    </div>

    {{-- Muestra de Error --}}
    @if ($error)
        <p class="mt-1 text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium">
            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd" />
            </svg>
            {{ $error }}
        </p>
    @endif
</div>
