import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Segoe UI', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#eef7ff', 100: '#d9edff', 200: '#bce0ff', 300: '#8ecfff',
                    400: '#59b3ff', 500: '#338ff8', 600: '#1d70ed', 700: '#1859da',
                    800: '#1a49b0', 900: '#1b408a', 950: '#162954',
                },
                fleet: { 500: '#0f9f8f', 600: '#0b8277', 700: '#0f665f' },
            },
            boxShadow: {
                panel: '0 1px 2px rgb(15 23 42 / 0.05), 0 8px 24px rgb(15 23 42 / 0.04)',
            },
        },
    },

    plugins: [forms],
};
