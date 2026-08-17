import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
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
        isProcessing: false,

        init() {
            const tbody = document.getElementById('ajax-table-body');
            const pagination = document.getElementById('ajax-pagination');
            const container = document.getElementById('data-table-container');

            if (tbody) this.tableBodyHtml = tbody.innerHTML;
            if (pagination) this.paginationHtml = pagination.innerHTML;

            this.defaultSort = container?.dataset.defaultSort || 'created_at';
            this.defaultDirection = container?.dataset.defaultDirection || 'desc';

            this.updateFilterState();
            this.attachFormListeners();
            this.attachPerPageListener();

            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.has('sort')) {
                const form = document.getElementById('data-table-form');
                if (form) {
                    const sortInput = form.querySelector('input[name="sort"]');
                    const directionInput = form.querySelector('input[name="direction"]');
                    if (sortInput) sortInput.value = this.defaultSort;
                    if (directionInput) directionInput.value = this.defaultDirection;
                }
            }

            window.addEventListener('refresh-table', () => {
                const form = document.getElementById('data-table-form');
                if (form) this.fetchData(form.action, new FormData(form));
            });

            // ✅ AGREGA ESTO: Forzar la carga de datos frescos al inicializar el componente
            setTimeout(() => {
                const form = document.getElementById('data-table-form');
                if (form) {
                    this.fetchData(form.action, new FormData(form));
                }
            }, 100);
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

        handleSort(field) {
            const form = document.getElementById('data-table-form');
            if (!form) return;

            const sortInput = form.querySelector('input[name="sort"]');
            const directionInput = form.querySelector('input[name="direction"]');
            const pageInput = form.querySelector('input[name="page"]');

            if (!sortInput || !directionInput) return;

            // Determinar la nueva dirección basándonos en el estado REAL actual
            let newDirection = 'asc';

            if (sortInput.value === field) {
                newDirection = directionInput.value === 'asc'
                    ? 'desc'
                    : 'asc';
            }

            // Actualizar los inputs ocultos
            sortInput.value = field;
            directionInput.value = newDirection;

            if (pageInput) {
                pageInput.value = '1';
            }

            // Crear FormData DESPUÉS de actualizar los inputs
            const formData = new FormData(form);

            // Asegurar los valores
            formData.set('sort', field);
            formData.set('direction', newDirection);
            formData.set('page', '1');

            // Enviar petición AJAX
            this.fetchData(form.action, formData);
        },

        async fetchData(baseUrl, formData) {
            if (this.isProcessing) return;

            this.isProcessing = true;
            this.loading = true;

            try {
                const url = new URL(baseUrl, window.location.origin);

                // Agregar parámetros del formulario a la URL de forma limpia
                for (let [key, value] of formData.entries()) {
                    if (value) {
                        url.searchParams.set(key, value);
                    }
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
                this.isProcessing = false;
            }
        },
    }));
});


window.deleteItem = function (id, name, url) {
    showConfirm('¿Eliminar?', `¿Estás seguro de eliminar a "${name}"?`, async () => {
        try {
            const formData = new FormData();
            formData.append('_method', 'DELETE');
            formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

            // Obtener la página actual de la URL
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = urlParams.get('page') || '1';
            const perPage = urlParams.get('per_page') || '10';

            // Agregar página y per_page a la URL
            const deleteUrl = new URL(url, window.location.origin);
            deleteUrl.searchParams.set('page', currentPage);
            deleteUrl.searchParams.set('per_page', perPage);

            const response = await fetch(deleteUrl.toString(), {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            if (response.ok) {
                const data = await response.json();
                showAlert('success', '¡Éxito!', data.message || 'Registro eliminado correctamente.');

                // Obtener el formulario y construir los datos para refrescar
                const form = document.getElementById('data-table-form');
                if (form) {
                    const refreshFormData = new FormData(form);

                    // Determinar qué página mostrar
                    let targetPage = currentPage;
                    if (data.redirect_to_page) {
                        targetPage = data.redirect_to_page;
                    }

                    refreshFormData.set('page', targetPage);

                    // Llamar directamente a fetchData del componente Alpine
                    const container = document.getElementById('data-table-container');
                    if (container) {
                        const alpineData = Alpine.$data(container);
                        if (alpineData && alpineData.fetchData) {
                            alpineData.fetchData(form.action, refreshFormData);
                        }
                    }
                }
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
            this.clearValidationErrors();

            for (const [field, messages] of Object.entries(this.errors)) {
                let input;

                // CASO ESPECIAL: Permisos (grupo de checkboxes)
                if (field === 'permissions') {
                    input = document.getElementById('permissions-error-container');
                } else {
                    // Comportamiento normal para inputs de texto, selects, etc.
                    const inputName = field.includes('.') ? field.replace('.', '\\.') : field;
                    input = document.querySelector(`[name="${inputName}"], [name="${field}[]"]`);
                }

                if (input) {
                    if (field === 'permissions') {
                        // Para el contenedor de permisos, solo mostramos el mensaje
                        input.classList.remove('hidden');
                        input.innerHTML = `
                            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            ${messages[0]}
                        `;
                    } else {
                        // Comportamiento normal para otros campos
                        input.classList.add('border-red-500', 'focus:ring-red-500', 'focus:border-red-500');
                        input.classList.remove('border-gray-300', 'dark:border-gray-600', 'focus:ring-primary-500', 'focus:border-primary-500');

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
            }
        },

        clearValidationErrors() {
            // Limpiar bordes rojos
            document.querySelectorAll('.border-red-500').forEach(el => {
                el.classList.remove('border-red-500', 'focus:ring-red-500', 'focus:border-red-500');
                el.classList.add('border-gray-300', 'dark:border-gray-600', 'focus:ring-primary-500', 'focus:border-primary-500');
            });

            // Limpiar el contenedor especial de permisos
            const permError = document.getElementById('permissions-error-container');
            if (permError) {
                permError.classList.add('hidden');
                permError.innerHTML = '';
            }

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

// ==========================================
// COMPONENTE REUTILIZABLE: RECORTE DE IMÁGENES (Croppie)
// Sirve para cualquier formulario con logo/foto + modal de recorte,
// incluso cuando el formulario entero se inyecta por AJAX dentro de
// otro modal (banco-modal, marca-red-modal, etc). Al ser un x-data
// de Alpine (no un <script> suelto), Alpine lo detecta e inicializa
// automáticamente apenas el HTML se inyecta en el DOM.
//
// Uso en Blade:
// <div x-data="imageCropper({
//         previewId: 'logo-preview',
//         inputId: 'logo-upload',
//         cropContainerId: 'banco-crop-container',
//         cropModalName: 'crop-banco-logo',
//         shape: 'square',        // 'square' | 'circle'
//         outputFormat: 'jpeg',   // 'jpeg' | 'png'
//     })">
//     ...input file con x-on:change="handleSelect($event)"...
//     ...modal con div#cropContainerId y botones
//        x-on:click="cancelCrop()" / x-on:click="saveCrop()"...
// </div>
// ==========================================
document.addEventListener('alpine:init', () => {
    Alpine.data('imageCropper', ({
        previewId,
        inputId,
        cropContainerId,
        cropModalName,
        shape = 'circle',
        viewportSize = 200,
        outputSize = 256,
        outputFormat = 'jpeg',
    }) => ({
        croppieInstance: null,

        handleSelect(event) {
            const file = event.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                const imageUrl = e.target.result;

                // Abrimos el modal PRIMERO. Si Croppie se inicializa mientras
                // el modal todavía está oculto (display:none), mide 0x0 y el
                // recorte se ve roto/diminuto. $nextTick espera a que Alpine
                // ya haya aplicado el cambio de "show" al DOM antes de crear
                // la instancia de Croppie.
                this.$dispatch('open-modal', cropModalName);

                this.$nextTick(() => {
                    if (this.croppieInstance) {
                        this.croppieInstance.destroy();
                        this.croppieInstance = null;
                    }

                    const container = document.getElementById(cropContainerId);
                    if (!container) return;

                    this.croppieInstance = new Croppie(container, {
                        viewport: { width: viewportSize, height: viewportSize, type: shape },
                        boundary: { width: viewportSize + 100, height: viewportSize + 100 },
                        enableExif: true,
                        enableOrientation: true,
                        enableZoom: true,
                        mouseWheelZoom: true,
                    });

                    this.croppieInstance.bind({ url: imageUrl, orientation: 1 });
                });
            };
            reader.readAsDataURL(file);
        },

        saveCrop() {
            if (!this.croppieInstance) return;

            this.croppieInstance.result({
                type: 'blob',
                size: { width: outputSize, height: outputSize },
                format: outputFormat,
                quality: 0.9,
            }).then((blob) => {
                const extension = outputFormat === 'png' ? 'png' : 'jpg';
                const file = new File([blob], `logo.${extension}`, { type: `image/${outputFormat}` });

                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                const input = document.getElementById(inputId);
                if (input) input.files = dataTransfer.files;

                // URL.createObjectURL es SÍNCRONO (a diferencia de FileReader),
                // así que la preview queda lista antes de cerrar el modal —
                // sin eso, el cierre podía ganarle la carrera a la lectura
                // async del FileReader y el <img> ya no existía cuando
                // intentábamos setear el .src.
                const preview = document.getElementById(previewId);
                if (preview) preview.src = URL.createObjectURL(blob);

                this.$dispatch('close-modal', cropModalName);
            });
        },

        cancelCrop() {
            this.$dispatch('close-modal', cropModalName);
        },
    }));
});

// ==========================================
// MANEJO DE ALERTAS DESDE SESIONES FLASH
// ==========================================
document.addEventListener('DOMContentLoaded', function () {
    // Verificar si hay mensajes en localStorage (backup)
    const alertMessage = localStorage.getItem('alertMessage');
    const alertTitle = localStorage.getItem('alertTitle');
    const alertIcon = localStorage.getItem('alertIcon');

    if (alertMessage) {
        showAlert(alertIcon || 'info', alertTitle || 'Información', alertMessage);
        // Limpiar después de mostrar
        localStorage.removeItem('alertMessage');
        localStorage.removeItem('alertTitle');
        localStorage.removeItem('alertIcon');
    }
});

window.Alpine = Alpine;
Alpine.plugin(collapse);
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
