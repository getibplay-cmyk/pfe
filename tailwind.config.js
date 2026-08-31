import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import { BELKHIR_SPACE_COLORS } from './resources/js/belkhir-space-tokens.js';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Segoe UI', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                belkhir: {
                    space: BELKHIR_SPACE_COLORS,
                },
                brand: {
                    50: '#EFF6FF', 100: '#DBEAFE', 200: '#BFDBFE', 300: '#93C5FD',
                    400: '#60A5FA', 500: '#3B82F6', 600: '#2563EB', 700: '#1D4ED8',
                    800: '#1E40AF', 900: '#1E3A8A', 950: '#172554',
                },
                fleet: { 500: '#22C55E', 600: '#16A34A', 700: '#15803D' },
            },
            boxShadow: {
                panel: '0 1px 2px rgb(15 23 42 / 0.05), 0 8px 24px rgb(15 23 42 / 0.04)',
            },
        },
    },

    plugins: [forms],
};
