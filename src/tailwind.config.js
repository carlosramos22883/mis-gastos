/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
    // Paleta principal: Azul Marino (basada en #0a0a5e del logo)
    primary: {
        50: '#f0f4ff',     // Azul muy claro (fondos)
        100: '#e0e7ff',    // Azul claro (hover suave)
        200: '#c7d2fe',    // Azul medio claro
        300: '#a5b4fc',    // Azul medio
        400: '#818cf8',    // Azul vibrante
        500: '#6366f1',    // Azul brillante (modo oscuro - botones)
        600: '#0a0a5e',    // Azul marino del logo (modo claro - botones)
        700: '#08084a',    // Azul marino oscuro
        800: '#070738',    // Azul marino muy oscuro
        900: '#05052a',    // Azul casi negro
        950: '#03031a',    // Azul ultra oscuro
    },
    // Colores de acento (déjalos como estaban)
    accent: {
        50: '#f0f9ff',
        100: '#e0f2fe',
        200: '#bae6fd',
        300: '#7dd3fc',
        400: '#38bdf8',
        500: '#0ea5e9',
        600: '#0284c7',
        700: '#0369a1',
        800: '#075985',
        900: '#0c4a6e',
    },
    // Colores neutros (déjalos como estaban)
    neutral: {
        50: '#f8fafc',
        100: '#f1f5f9',
        200: '#e2e8f0',
        300: '#cbd5e1',
        400: '#94a3b8',
        500: '#64748b',
        600: '#475569',
        700: '#334155',
        800: '#1e293b',
        900: '#0f172a',
        950: '#020617',
    },
},
        },
    },
    plugins: [],
};