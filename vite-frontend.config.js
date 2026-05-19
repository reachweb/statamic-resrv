import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
    ],
    build: {
        outDir: 'resources/frontend',
        emptyOutDir: false,
        rollupOptions: {
            input: {
                'resrv-frontend': 'resources/js/resrv-frontend.js',
                'resrv-tailwind': 'resources/css/resrv-tailwind.css',
            },
            output: {
                entryFileNames: 'js/[name].js',
                assetFileNames: 'css/[name][extname]',
            },
        },
    },
});
