import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

export default defineConfig({
    plugins: [
        laravel({
            hotFile: 'resources/frontend/hot',
            publicDirectory: "resources/frontend",
            input: ['resources/css/resrv-frontend.css', 'resources/js/resrv-frontend.js'],
            refresh: true,
        }),
    ],
    css: {
        postcss: {
            plugins: [
                tailwindcss('./tailwind-frontend.config.js'),
                autoprefixer(),
            ],
        },
    },
});