// Función para inicializar el modo oscuro
export function initDarkMode() {
    // Verificar preferencia guardada o del sistema
    const isDark = localStorage.getItem('darkMode') === 'true' ||
        (!localStorage.getItem('darkMode') && window.matchMedia('(prefers-color-scheme: dark)').matches);

    if (isDark) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

// Función para alternar el modo
export function toggleDarkMode() {
    const html = document.documentElement;
    const isDark = html.classList.contains('dark');

    if (isDark) {
        html.classList.remove('dark');
        localStorage.setItem('darkMode', 'false');
    } else {
        html.classList.add('dark');
        localStorage.setItem('darkMode', 'true');
    }

    return !isDark;
}

// Inicializar al cargar
document.addEventListener('DOMContentLoaded', initDarkMode);