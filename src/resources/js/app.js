import Alpine from 'alpinejs';
import { initDarkMode, toggleDarkMode } from './dark-mode';

// Inicializar Alpine (necesario para los dropdowns y modales de Breeze)
window.Alpine = Alpine;
Alpine.start();

// Hacer la función toggleDarkMode disponible globalmente para el botón HTML
window.toggleDarkMode = toggleDarkMode;

// Inicializar el modo oscuro al cargar la página
initDarkMode();