import Alpine from 'alpinejs';
import Croppie from 'croppie';
import 'croppie/croppie.css';
import Swal from 'sweetalert2';

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
        confirmButton: 'swal-btn-primary',
        cancelButton: 'swal-btn-secondary',
        denyButton: 'swal-btn-danger',
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