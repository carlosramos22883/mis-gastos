import Alpine from 'alpinejs';
import Croppie from 'croppie';
import 'croppie/croppie.css';
import Swal from 'sweetalert2';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.css';

// Hacer Tom Select disponible globalmente
window.TomSelect = TomSelect;

// ==========================================
// MANEJADOR DE TABLA DE DATOS AJAX (WEB)
// ==========================================
document.addEventListener('alpine:init', () => {
    Alpine.data('dataTableHandler', () => ({
        tableBodyHtml: '',
        paginationHtml: '',
        loading: false,
        hasFilters: false,
        isProcessing: false, // <-- FLAG ANTI-BUCLE

        init() {
            const tbody = document.getElementById('ajax-table-body');
            const pagination = document.getElementById('ajax-pagination');
            if (tbody) this.tableBodyHtml = tbody.innerHTML;
            if (pagination) this.paginationHtml = pagination.innerHTML;

            this.updateFilterState();
            this.attachFormListeners();
            this.attachPerPageListener(); // <-- NUEVO

            window.addEventListener('refresh-table', () => {
                const form = document.getElementById('data-table-form');
                if (form) this.fetchData(form.action, new FormData(form));
            });
        },

        attachFormListeners() {
            const form = document.getElementById('data-table-form');
            if (!form) return;

            // Intercepta el envío del formulario
            form.addEventListener('submit', (e) => {
                if (this.isProcessing) return;
                e.preventDefault();
                this.fetchData(form.action, new FormData(form));
            });

            // Escucha cambios para actualizar el botón "Limpiar"
            form.addEventListener('input', () => this.updateFilterState());
            form.addEventListener('change', () => this.updateFilterState());
        },

        attachPerPageListener() {
            // Escucha el evento personalizado del selector "Por página"
            window.addEventListener('per-page-changed', (e) => {
                if (this.isProcessing) return; // <-- EVITA BUCLE

                const form = document.getElementById('data-table-form');
                if (!form) return;

                const formData = new FormData(form);
                formData.set('per_page', e.detail.value);

                this.fetchData(form.action, formData);
            });
        },

        updateFilterState() {
            const form = document.getElementById('data-table-form');
            if (!form) return;

            const formData = new FormData(form);
            let active = false;

            for (let [key, value] of formData.entries()) {
                if (['page', 'per_page', 'sort', 'direction', '_token'].includes(key)) continue;
                if (value && value.trim() !== '') {
                    active = true;
                    break;
                }
            }
            this.hasFilters = active;
        },

        clearFilters() {
            const form = document.getElementById('data-table-form');
            if (!form) return;

            const searchInput = form.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            }

            form.querySelectorAll('select').forEach(select => {
                if (['per_page', 'sort', 'direction'].includes(select.name)) return;

                if (select.tomselect) {
                    select.tomselect.clear();
                } else {
                    select.value = '';
                    select.dispatchEvent(new Event('change'));
                }
            });

            this.updateFilterState();
            this.fetchData(form.action, new FormData(form));
        },

        async fetchData(baseUrl, formData) {
            if (this.isProcessing) return; // <-- SEGURIDAD EXTRA

            this.isProcessing = true;
            this.loading = true;

            try {
                const url = new URL(baseUrl, window.location.origin);
                for (let [key, value] of formData.entries()) {
                    if (value) url.searchParams.append(key, value);
                }

                const response = await fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });

                if (response.ok) {
                    const data = await response.json();
                    this.tableBodyHtml = data.html;
                    this.paginationHtml = data.pagination;

                    window.history.pushState({}, '', url.toString());

                    setTimeout(() => {
                        if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                            const tbody = document.getElementById('ajax-table-body');
                            const pagination = document.getElementById('ajax-pagination');

                            if (tbody) Alpine.initTree(tbody);
                            if (pagination) Alpine.initTree(pagination);
                        }
                    }, 50);
                } else {
                    console.error("Error en la respuesta:", response.status);
                }
            } catch (error) {
                console.error('Error AJAX:', error);
                showAlert('error', 'Error', 'No se pudieron cargar los datos.');
            } finally {
                this.loading = false;
                this.isProcessing = false; // <-- LIBERA EL BLOQUEO
            }
        }
    }));
});


window.deleteItem = function (id, name, url) {
    showConfirm('¿Eliminar?', `¿Estás seguro de eliminar a "${name}"?`, async () => {
        try {
            const formData = new FormData();
            formData.append('_method', 'DELETE');
            formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            if (response.ok) {
                showAlert('success', '¡Éxito!', 'Registro eliminado correctamente.');
                window.dispatchEvent(new CustomEvent('refresh-table'));
            } else {
                const data = await response.json();
                showAlert('error', 'Error', data.message || 'No se pudo eliminar.');
            }
        } catch (error) {
            showAlert('error', 'Error de conexión', 'No se pudo comunicar con el servidor.');
        }
    });
};

// Función para exportar datos con los filtros actuales
window.exportData = function (format) {
    const form = document.getElementById('data-table-form');
    if (!form) return;

    // Construir URL base de exportación
    const exportUrl = new URL(form.action);

    // Obtener todos los valores del formulario
    const formData = new FormData(form);

    // Agregar cada parámetro a la URL
    for (let [key, value] of formData.entries()) {
        if (value && value.trim() !== '') {
            exportUrl.searchParams.append(key, value);
        }
    }

    // Agregar el formato de exportación
    exportUrl.searchParams.append('format', format);

    // Reemplazar la ruta base con la ruta de exportación
    // (asumiendo que la exportación está en la misma ruta base)
    const currentPath = window.location.pathname;
    exportUrl.pathname = currentPath + '/export';

    // Abrir la exportación en una nueva pestaña
    window.open(exportUrl.toString(), '_blank');
};


// ==========================================
// MANEJADOR DE FORMULARIOS AJAX REUTILIZABLE
// ==========================================
document.addEventListener('alpine:init', () => {
    Alpine.data('ajaxForm', (onSuccessCallback) => ({
        loading: false,
        errors: {},

        async submit(e) {
            this.loading = true;
            this.errors = {};
            const form = e.target;
            const formData = new FormData(form);

            try {
                const response = await fetch(form.action, {
                    method: form.method,
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json' // Le dice a Laravel que espere/devuelva JSON
                    }
                });

                if (response.status === 422) {
                    const data = await response.json();

                    // Console.log detallado
                    console.log('Validación de formulario fallida:');
                    console.log('   Campos con errores:', Object.keys(data.errors));
                    console.log('   Mensajes:', data.errors);

                    this.errors = data.errors;
                    this.showValidationErrors();
                } else if (response.ok) {
                    // Éxito
                    this.clearValidationErrors();
                    if (onSuccessCallback) onSuccessCallback();
                } else {
                    showAlert('error', 'Error', 'Ocurrió un error inesperado en el servidor.');
                }
            } catch (error) {
                console.error('Error AJAX:', error);
                showAlert('error', 'Error de conexión', 'No se pudo comunicar con el servidor.');
            } finally {
                this.loading = false;
            }
        },

        showValidationErrors() {
            // 1. Limpiar errores visuales anteriores
            this.clearValidationErrors();

            // 2. Pintar los nuevos errores
            for (const [field, messages] of Object.entries(this.errors)) {
                // Busca el input por nombre (maneja también arrays como permissions[])
                const inputName = field.includes('.') ? field.replace('.', '\\.') : field;
                const input = document.querySelector(`[name="${inputName}"], [name="${field}[]"]`);

                if (input) {
                    // Resaltar el borde del input
                    input.classList.add('border-red-500', 'focus:ring-red-500', 'focus:border-red-500');
                    input.classList.remove('border-gray-300', 'dark:border-gray-600', 'focus:ring-primary-500', 'focus:border-primary-500');

                    // Crear el mensaje de error debajo del input
                    const errorDiv = document.createElement('p');
                    errorDiv.className = 'mt-1 text-xs flex items-center gap-1 text-red-600 dark:text-red-400 font-medium';
                    errorDiv.innerHTML = `
                        <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        ${messages[0]}
                    `;
                    input.parentElement.appendChild(errorDiv);
                }
            }
        },

        clearValidationErrors() {
            // Limpiar bordes rojos
            document.querySelectorAll('.border-red-500').forEach(el => {
                el.classList.remove('border-red-500', 'focus:ring-red-500', 'focus:border-red-500');
                el.classList.add('border-gray-300', 'dark:border-gray-600', 'focus:ring-primary-500', 'focus:border-primary-500');
            });

            // Limpiar mensajes de error (usa un selector más simple)
            document.querySelectorAll('.text-red-600').forEach(el => {
                // Solo elimina si es un mensaje de error de validación (no otros elementos rojos)
                if (el.parentElement && el.tagName === 'P' && el.classList.contains('flex')) {
                    el.remove();
                }
            });

            // Limpieza adicional
            document.querySelectorAll('p.mt-1.text-xs').forEach(el => {
                if (el.classList.contains('text-red-600') || el.querySelector('.text-red-600')) {
                    el.remove();
                }
            });
        }
    }));
});

window.Alpine = Alpine;
Alpine.start();

window.Croppie = Croppie;

// Configuración global de SweetAlert2
const defaultTimer = 3000; // 3 segundos

Swal.mixin({
    timer: defaultTimer,
    timerProgressBar: true,
    showConfirmButton: true,
    focusConfirm: false,
    backdrop: 'rgba(0, 0, 0, 0.5)', // Fondo semi-transparente
    customClass: {
        popup: 'swal2-custom-popup',
        confirmButton: 'btn-primary-custom',
        cancelButton: 'btn-secondary-custom',
        denyButton: 'btn-danger-custom',
        actions: 'swal-actions',
    },
    // Forzar actualización de clases al cambiar de tema
    didOpen: () => {
        // Verificar si estamos en dark mode y aplicar clases
        if (document.documentElement.classList.contains('dark')) {
            const popup = document.querySelector('.swal2-popup');
            if (popup) {
                popup.classList.add('dark');
            }
        }
    }
});

// Función helper para mostrar alertas
window.showAlert = function (icon, title, text, options = {}) {
    const defaultOptions = {
        icon,
        title,
        text,
        timer: defaultTimer, // Asegurar que tenga timer
        timerProgressBar: true,
    };

    // Asignar clase de botón según el tipo de alerta
    if (!options.customClass) {
        options.customClass = {};
    }

    if (!options.customClass.confirmButton) {
        switch (icon) {
            case 'success':
                options.customClass.confirmButton = 'swal-btn-success';
                break;
            case 'warning':
                options.customClass.confirmButton = 'swal-btn-warning';
                break;
            case 'error':
                options.customClass.confirmButton = 'swal-btn-danger';
                break;
            case 'info':
                options.customClass.confirmButton = 'swal-btn-primary';
                break;
            default:
                options.customClass.confirmButton = 'swal-btn-primary';
        }
    }

    return Swal.fire({ ...defaultOptions, ...options });
};

// Función helper para confirmaciones (NO se cierra sola)
window.showConfirm = function (title, text, onConfirm, options = {}) {
    return Swal.fire({
        icon: 'warning',
        title: title,
        text: text,
        showCancelButton: true,
        confirmButtonText: 'Sí, continuar',
        cancelButtonText: 'Cancelar',
        timer: 0, // IMPORTANTE: Las confirmaciones NO se cierran solas
        showConfirmButton: true,
        customClass: {
            confirmButton: 'swal-btn-danger',
            cancelButton: 'swal-btn-secondary',
            actions: 'swal-actions',
        },
        ...options,
    }).then((result) => {
        if (result.isConfirmed && onConfirm) {
            onConfirm();
        }
    });
};

window.Swal = Swal;

window.toggleDarkMode = function () {
    if (document.documentElement.classList.contains('dark')) {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    } else {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    }
};

if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
} else {
    document.documentElement.classList.remove('dark');
}

import './avatar-crop';