import Alpine from 'alpinejs';
import Croppie from 'croppie';
import 'croppie/croppie.css';
import Swal from 'sweetalert2';

// Inicializar Alpine.js
window.Alpine = Alpine;
Alpine.start();

// Hacer Croppie disponible globalmente
window.Croppie = Croppie;

// Hacer SweetAlert disponible globalmente
window.Swal = Swal;

// Función global para toggle de modo oscuro
window.toggleDarkMode = function() {
    if (document.documentElement.classList.contains('dark')) {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    } else {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    }
};

// Inicializar tema al cargar
if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
} else {
    document.documentElement.classList.remove('dark');
}

// Importar nuestro script del avatar
import './avatar-crop';