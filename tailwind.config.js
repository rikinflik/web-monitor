import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Badge class strings live in PHP, not only in Blade: AlertFinding::
        // severityBadge() and AlertSource::healthBadge() return Tailwind
        // classes. Without this glob Tailwind never sees them and silently
        // drops bg-orange-100 / bg-yellow-100 from the build, so the High and
        // Medium severity pills and the Stale badge render unstyled.
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
