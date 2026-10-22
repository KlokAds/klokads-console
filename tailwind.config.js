import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['"Plus Jakarta Sans"', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Neutrals come from CSS variables (resources/css/app.css) so dark mode flips them in one place.
                canvas: 'rgb(var(--canvas) / <alpha-value>)',
                line: 'rgb(var(--line) / <alpha-value>)',
                surface: 'rgb(var(--surface) / <alpha-value>)',
                ink: Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((n) => [n, `rgb(var(--ink-${n}) / <alpha-value>)`])),
                brand: {
                    50: '#f1f0ff',
                    100: '#e5e3ff',
                    200: '#cdc9ff',
                    300: '#aaa2ff',
                    400: '#8a7dfb',
                    500: '#6f5ef5',
                    600: '#5b45e8',
                    700: '#4b36c9',
                    800: '#3d2ea2',
                    900: '#2f257b',
                },
            },
            boxShadow: {
                card: '0 1px 2px rgba(16, 24, 40, 0.04), 0 1px 3px rgba(16, 24, 40, 0.06)',
                lift: '0 12px 32px -12px rgba(16, 24, 40, 0.18)',
            },
            borderRadius: {
                xl: '0.875rem',
                '2xl': '1.125rem',
            },
        },
    },

    plugins: [forms],
};
