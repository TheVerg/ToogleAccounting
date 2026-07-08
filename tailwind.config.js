import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Corporate navy — the primary brand color.
                brand: {
                    50: '#eef3f9',
                    100: '#d8e3f0',
                    200: '#b4c9e2',
                    300: '#86a6cd',
                    400: '#5480b4',
                    500: '#33619c',
                    600: '#254c83',
                    700: '#1e3d6b',
                    800: '#1a3356',
                    900: '#142743',
                    950: '#0a1526',
                },
            },
        },
    },

    plugins: [forms],
};
