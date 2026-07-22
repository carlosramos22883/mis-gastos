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
                primary: {
                    50: '#f0f4ff',
                    100: '#e0e7ff',
                    200: '#c7d2fe',
                    300: '#7BB3F0',
                    400: '#4A90E2',
                    500: '#2B66CC',
                    600: '#0a0a5e',
                    700: '#08084a',
                    800: '#070738',
                    900: '#05052a',
                    950: '#03031a',
                },
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
                // Colores de estado
                success: {
                    DEFAULT: '#0B6E4F',
                    hover: '#2E8B57',
                },
                warning: {
                    DEFAULT: '#f59e0b',
                    hover: '#d97706',
                },
                danger: {
                    DEFAULT: '#DC3545',
                    hover: '#b21f2d',
                },
                info: {
                    DEFAULT: '#3b82f6',
                    hover: '#2563eb',
                },
            },
        },
    },
    plugins: [],
};