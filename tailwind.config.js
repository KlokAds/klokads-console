import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
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
                canvas: '#f5f6fa',
                line: '#e6e8ef',
                ink: {
                    50: '#f6f7fb',
                    100: '#eceef5',
                    200: '#d9dce8',
                    300: '#b6bbcf',
                    400: '#8b91aa',
                    500: '#646a85',
                    600: '#4a5069',
                    700: '#353a52',
                    800: '#1f2438',
                    900: '#131729',
                    950: '#0b1020',
                },
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
